<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Ide;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Ide\IdeServer;
use Porta\McpPlugin\Ide\LockFile;
use Porta\McpPlugin\Ide\WebSocketFrames;
use Porta\McpPlugin\Server\Session;
use Porta\McpPlugin\Server\ToolCatalog;
use Porta\McpPlugin\Tests\Support\TalksClaudeCode;
use Porta\Support\NonBlockingWriter;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** The WebSocket end on the real loop: who is a client yet, who hears a broadcast, who is hung up on. */
final class IdeServerTest extends TestCase
{
    use PumpsLoop;
    use TalksClaudeCode;

    private TempProject $config;

    private ?IdeServer $server = null;

    /** @var list<string> */
    private array $logged = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->config = new TempProject();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    private function server(float $handshakeTimeout = IdeServer::HANDSHAKE_TIMEOUT): IdeServer
    {
        $catalog = new ToolCatalog(
            static fn (): array => [[
                'plugin' => 'mcp',
                'id' => 'echo',
                'description' => 'echo',
                'inputSchema' => ['type' => 'object'],
                'handler' => static fn (array $arguments): string => (string) ($arguments['word'] ?? ''),
                'effect' => null,
            ]],
            'mcp',
            static fn (): array => [],
        );

        return $this->server = new IdeServer(
            new LockFile($this->config->dir.'/ide'),
            ['/p'],
            static fn (\Closure $send, string $connection): Session => new Session($send, $catalog, '1.0', $connection),
            function (string $line): void {
                $this->logged[] = $line;
            },
            $handshakeTimeout,
        );
    }

    public function testOnlyAClientThatShookHandsCountsOrHearsABroadcast(): void
    {
        $server = $this->server();
        $this->assertNull($server->start());
        $this->assertNull($server->start(), 'starting twice is harmless');
        $port = (int) $server->port();
        $this->assertSame($this->lock($this->config->dir)['port'], $port);

        $half = \stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 1.0);
        $this->assertNotFalse($half);
        \fwrite($half, data: "GET / HTTP/1.1\r\n");
        $this->connectCli($this->config->dir);
        $this->assertSame(1, $server->clientCount(), 'the one still sending headers is not a client');

        $server->broadcast('selection_changed', ['filePath' => '/p/a.php']);
        $this->assertSame(['filePath' => '/p/a.php'], $this->cliAwait(static fn (array $m): bool => 'selection_changed' === ($m['method'] ?? null), 'the broadcast')['params']);
        \stream_set_blocking($half, enable: false);
        $this->assertSame('', (string) \fread($half, length: 100), 'and it heard nothing');

        $this->assertIsResource($this->cli);
        \fclose($this->cli);
        $this->await(static fn (): bool => 0 === $server->clientCount(), what: 'the hang-up noticed');
    }

    public function testAClientThatStopsReadingIsDroppedAndLogged(): void
    {
        $server = $this->server();
        $server->start();
        $this->connectCli($this->config->dir);
        $this->assertIsResource($this->cli);
        $writer = new NonBlockingWriter($this->cli, static fn (string $reason): null => null, 64 * 1_048_576);
        $request = self::clientFrame('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":{"word":"'.\str_repeat('x', times: 1_048_576).'"}}}');
        for ($i = 0; $i < 12; ++$i) {
            $writer->write($request);
        }

        try {
            $this->await(fn (): bool => [] !== $this->logged, what: 'the overflow to be logged');
        } finally {
            $writer->close();
        }
        $this->assertSame('Claude Code client dropped: write queue overflow', $this->logged[0]);
        $this->assertSame(0, $server->clientCount());
    }

    public function testStopHangsUpOnEveryClientAndTakesTheLockBack(): void
    {
        $server = $this->server();
        $server->start();
        $this->connectCli($this->config->dir);

        $server->stop();

        $this->assertNull($server->port());
        $this->assertSame([], \glob($this->config->dir.'/ide/*.lock'));
        $this->await($this->cliClosed(...), what: 'the client hung up on');
        $this->assertFalse(\getenv('CLAUDE_CODE_SSE_PORT'));
        $server->stop(); // twice: nothing left to stop
    }

    public function testAClientThatNeverShakesHandsIsHungUpOn(): void
    {
        $server = $this->server(handshakeTimeout: 0.1);
        $server->start();
        $silent = \stream_socket_client('tcp://127.0.0.1:'.(int) $server->port(), timeout: 1.0);
        $this->assertNotFalse($silent);

        $this->await(fn (): bool => [] !== $this->logged, what: 'the deadline');

        $this->assertSame(['Claude Code client dropped: no handshake in time'], $this->logged);
        \stream_set_blocking($silent, enable: true);
        $this->assertSame('', \fread($silent, length: 10), 'hung up on');
        $this->connectCli($this->config->dir);
        $this->pump(0.2);
        $this->assertSame(1, $server->clientCount(), 'one that did is kept');
    }

    public function testAFrameAfterOneThatCostTheConnectionIsLeftUnread(): void
    {
        $server = $this->server();
        $server->start();
        $this->connectCli($this->config->dir);
        $this->assertIsResource($this->cli);
        // An answer past the write queue's cap costs the connection, and a
        // close right behind it, read in the same batch, has nobody left.
        $request = self::clientFrame('{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"echo","arguments":{"word":"'.\str_repeat('x', times: 9 * 1_048_576).'"}}}');
        $writer = new NonBlockingWriter($this->cli, static fn (string $reason): null => null, 64 * 1_048_576);
        $writer->write($request.self::clientFrame("\x03\xe8", WebSocketFrames::CLOSE));

        try {
            $this->await(fn (): bool => [] !== $this->logged, what: 'the overflow');
            $this->pump(0.1);
        } finally {
            $writer->close();
        }
        $this->assertSame(['Claude Code client dropped: write queue overflow'], $this->logged, 'and nothing threw');
        $this->assertSame(0, $server->clientCount());
    }
}
