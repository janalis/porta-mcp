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
        $broadcast = $this->cliAwait(static fn (array $m): bool => 'selection_changed' === ($m['method'] ?? null), 'the broadcast');
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'selection_changed', 'params' => ['filePath' => '/p/a.php']], $broadcast, 'a JSON-RPC notification');
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

    public function testTheUpgradeAnswersWithTheRfcsAcceptAndNamesMcpAmongTheOffered(): void
    {
        $server = $this->server();
        $server->start();
        $port = (int) $server->port();

        [, $answer] = $this->upgrade($port, self::upgradeRequest($port, $this->lock($this->config->dir)['token'], "Sec-WebSocket-Protocol: chat, mcp\r\nA line with no colon\r\n"));

        $this->assertStringStartsWith('HTTP/1.1 101', $answer);
        $this->assertStringContainsString("\r\nSec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n", $answer, 'the RFC\'s own example key, answered');
        $this->assertStringEndsWith("\r\nSec-WebSocket-Protocol: mcp\r\n\r\n", $answer, 'mcp, found after a comma and a space');
    }

    public function testAWrongTokenIsRefusedAndHungUpOn(): void
    {
        $server = $this->server();
        $server->start();
        $port = (int) $server->port();

        [$this->cli, $answer] = $this->upgrade($port, self::upgradeRequest($port, 'wrong'));
        $this->cliFrames = new WebSocketFrames();

        $this->assertStringStartsWith('HTTP/1.1 401', $answer);
        $this->await($this->cliClosed(...), timeout: 2.0, what: 'the refused one hung up on — at once, not at the deadline');
        $this->assertSame(0, $server->clientCount());
    }

    public function testAHandshakeInPiecesIsJoinedAndOnePastTheCapIsHungUpOnAtOnce(): void
    {
        $server = $this->server();
        $server->start();
        $port = (int) $server->port();
        $request = self::upgradeRequest($port, $this->lock($this->config->dir)['token']);
        $stream = \stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 1.0);
        $this->assertNotFalse($stream);
        \fwrite($stream, \substr($request, offset: 0, length: 40));
        // A turn of the loop, so the server reads the first piece alone.
        $this->pump(0.05);
        \fwrite($stream, \substr($request, offset: 40));
        $this->await(static fn (): bool => 1 === $server->clientCount(), what: 'the joined handshake');

        [$this->cli] = $this->upgrade($port, \str_repeat('X', times: 20_000));
        $this->cliFrames = new WebSocketFrames();
        // Well inside the ten seconds a silent client gets: the cap, not the deadline.
        $this->await($this->cliClosed(...), timeout: 2.0, what: 'the endless headers hung up on');
        $this->assertSame([], $this->logged, 'by the cap, which says nothing');
    }

    public function testIdeConnectedMakesANewcomerOnceAndOnlyAsAMessage(): void
    {
        $server = $this->server();
        $server->start();
        $this->connectCli($this->config->dir);
        $this->assertFalse($server->takeNewcomer(), 'shaking hands is not saying ide_connected');

        $this->cliWrite(self::clientFrame('{"method":"ide_connected"}', WebSocketFrames::PING));
        $this->assertSame('{"method":"ide_connected"}', $this->cliAwaitFrame(WebSocketFrames::PONG, 'the pong'));
        $this->assertFalse($server->takeNewcomer(), 'a ping that says it is no message');

        $this->cliNotify('ide_connected', ['pid' => 1]);
        $taken = false;
        $this->await(static function () use ($server, &$taken): bool {
            return $taken = $server->takeNewcomer();
        }, what: 'the newcomer');
        $this->assertTrue($taken);
        $this->assertFalse($server->takeNewcomer(), 'taken once');
    }

    public function testAFramePastTheCapIsLoggedAndHungUpOn(): void
    {
        $server = $this->server();
        $server->start();
        $this->connectCli($this->config->dir);

        $this->cliWrite("\x81\xFF".\pack('J', 20_000_000).'mask');

        $this->await($this->cliClosed(...), what: 'hung up on');
        $this->assertSame(['Claude Code client dropped: a frame past 16777216 bytes'], $this->logged);
    }

    public function testAClientGoneBeforeItsDeadlineLeavesNoDeadlineBehind(): void
    {
        $server = $this->server(handshakeTimeout: 0.2);
        $server->start();
        $gone = \stream_socket_client('tcp://127.0.0.1:'.(int) $server->port(), timeout: 1.0);
        $silent = \stream_socket_client('tcp://127.0.0.1:'.(int) $server->port(), timeout: 1.0);
        $this->assertNotFalse($gone);
        $this->assertNotFalse($silent);
        \fclose($gone);

        $this->await(fn (): bool => [] !== $this->logged, what: 'the silent one\'s deadline');
        $this->pump(0.05);

        $this->assertSame(['Claude Code client dropped: no handshake in time'], $this->logged, 'the one that left had its deadline taken with it');
    }
}
