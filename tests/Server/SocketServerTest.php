<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Server;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Server\Session;
use Porta\McpPlugin\Server\SocketPath;
use Porta\McpPlugin\Server\SocketServer;
use Porta\McpPlugin\Server\ToolCatalog;
use Porta\Support\NonBlockingWriter;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** The IDE's end, over a real unix socket on the real loop. */
final class SocketServerTest extends TestCase
{
    use PumpsLoop;

    private TempProject $project;

    private ?SocketServer $server = null;

    /** @var list<string> */
    private array $logged = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    private function server(?SocketPath $paths = null): SocketServer
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

        return $this->server = new SocketServer(
            $paths ?? new SocketPath($this->project->dir),
            static fn (\Closure $send): Session => new Session($send, $catalog, '1.0'),
            function (string $line): void {
                $this->logged[] = $line;
            },
        );
    }

    /** @return resource */
    private function connect(string $path)
    {
        $client = \stream_socket_client('unix://'.$path, timeout: 1.0);
        $this->assertNotFalse($client, 'the server accepts');
        \stream_set_blocking($client, enable: false);

        return $client;
    }

    /**
     * Pump the loop until the client has a whole line, and decode it.
     *
     * @param resource $client
     *
     * @return array<string, mixed>
     */
    private function readMessage($client): array
    {
        $received = '';
        $this->await(static function () use ($client, &$received): bool {
            $chunk = \fread($client, length: 65_536);
            $received .= false === $chunk ? '' : $chunk;

            return \str_ends_with($received, "\n");
        }, what: 'an answer from the server');
        $message = \json_decode(\trim($received), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($message);

        return $message;
    }

    public function testAClientTalksToASessionAndEachClientHasItsOwn(): void
    {
        $server = $this->server();
        $this->assertNull($server->start());
        $this->assertNull($server->start(), 'starting twice is harmless');
        $path = (string) $server->path();
        $this->assertSame(0, \fileperms($path) & 0o077, 'only the user may connect');

        $first = $this->connect($path);
        $second = $this->connect($path);
        \fwrite($first, '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":{"word":"one"}}}'."\n");
        \fwrite($second, data: '{"jsonrpc":"2.0","id":2,"method":"tools/call",');
        \fwrite($second, '"params":{"name":"echo","arguments":{"word":"two"}}}'."\n");

        $this->assertSame('one', $this->readMessage($first)['result']['content'][0]['text']);
        $this->assertSame('two', $this->readMessage($second)['result']['content'][0]['text']);
        $this->assertSame(2, $server->clientCount());
    }

    public function testToolsChangedReachesEveryInitializedClient(): void
    {
        $server = $this->server();
        $server->start();
        $client = $this->connect((string) $server->path());
        \fwrite($client, '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n");
        $this->await(static fn (): bool => 1 === $server->clientCount(), what: 'the connection');
        $this->pump(0.05); // the notification line has to be read before the change is announced

        $server->toolsChanged();

        $this->assertSame('notifications/tools/list_changed', $this->readMessage($client)['method']);
    }

    public function testAClientHangingUpIsForgotten(): void
    {
        $server = $this->server();
        $server->start();
        $client = $this->connect((string) $server->path());
        $this->await(static fn (): bool => 1 === $server->clientCount(), what: 'the connection');

        \fclose($client);

        $this->await(static fn (): bool => 0 === $server->clientCount(), what: 'the hang-up to be noticed');
    }

    public function testAClientThatStopsReadingIsDroppedAndLogged(): void
    {
        $server = $this->server();
        $server->start();
        $client = $this->connect((string) $server->path());
        // Written through the loop, never blocking: the server that must read
        // these runs on the same thread. Twelve megabyte-sized echoes are far
        // past the server's 8 MiB queue cap once their answers pile up unread.
        $writer = new NonBlockingWriter($client, static fn (string $reason): null => null, 64 * 1_048_576);
        $request = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":{"word":"'.\str_repeat('x', times: 1_048_576).'"}}}'."\n";
        for ($i = 0; $i < 12; ++$i) {
            $writer->write($request);
        }

        try {
            $this->await(fn (): bool => [] !== $this->logged, what: 'the overflow to be logged');
        } finally {
            $writer->close();
        }
        $this->assertSame('MCP client dropped: write queue overflow', $this->logged[0]);
        $this->assertSame(0, $server->clientCount());
    }

    public function testStopHangsUpAndRemovesTheSocketAndThePointer(): void
    {
        $root = $this->project->dir.'/'.\str_repeat('deep/', times: 20);
        \mkdir($root, recursive: true);
        $paths = new SocketPath($root);
        $server = $this->server($paths);
        $server->start();
        $path = (string) $server->path();
        $client = $this->connect($path);
        $this->await(static fn (): bool => 1 === $server->clientCount(), what: 'the connection');

        $server->stop();

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($paths->pointer());
        $this->assertNull($server->path());
        $this->assertSame(0, $server->clientCount());
        \stream_set_blocking($client, enable: true);
        $this->assertSame('', \fread($client, length: 10), 'the client was hung up on');
        $server->stop(); // twice is harmless
    }

    public function testALeftoverSocketFromACrashIsReplaced(): void
    {
        $paths = new SocketPath($this->project->dir);
        $path = $paths->forServer();
        $dead = \stream_socket_server('unix://'.$path);
        $this->assertNotFalse($dead);
        \fclose($dead); // closed without unlinking: what a crash leaves
        $this->assertFileExists($path);

        $this->assertNull($this->server()->start(), 'a refused connect means nobody owns it');
        $this->assertIsResource($this->connect($path));
    }

    public function testALiveOwnerIsLeftAloneEvenWhenTheLoserStops(): void
    {
        // A long root, so there is a pointer to lose as well as a socket.
        $root = $this->project->dir.'/'.\str_repeat('deep/', times: 20);
        \mkdir($root, recursive: true);
        $paths = new SocketPath($root);
        $owner = new SocketServer($paths, static fn (\Closure $send): Session => throw new \LogicException('unused'), static fn (string $line): null => null);
        $this->assertNull($owner->start());

        try {
            $loser = $this->server($paths);
            $this->assertSame('Another Porta is already serving MCP for this project', $loser->start());
            $this->assertNull($loser->path());

            $loser->stop();

            $this->assertFileExists((string) $owner->path(), 'the owner keeps its socket');
            $this->assertSame($owner->path(), $paths->current(), 'and its pointer');
        } finally {
            $owner->stop();
        }
    }

    public function testABindFailureIsReportedAndLeavesNoPointer(): void
    {
        $root = $this->project->dir.'/'.\str_repeat('deep/', times: 20);
        \mkdir($root, recursive: true);
        $paths = new SocketPath($root, '/porta-no-such-dir');

        $error = $this->server($paths)->start();

        $this->assertIsString($error);
        $this->assertStringStartsWith('MCP could not listen at /porta-no-such-dir/porta-mcp-', $error);
        $this->assertFileDoesNotExist($paths->pointer(), 'a pointer to nothing would send the bridge astray');
    }

    public function testWhatSitsAtTheSocketPathAndIsNoSocketIsNeverRemoved(): void
    {
        $paths = new SocketPath($this->project->dir);
        $path = $paths->forServer();
        \file_put_contents($path, data: 'precious');

        $error = $this->server()->start();

        $this->assertSame(\sprintf('MCP could not listen at %s: something that is not a socket is there', $path), $error);
        $this->assertSame('precious', \file_get_contents($path));
    }

    public function testASymlinkAtTheSocketPathIsNeverFollowedNorRemoved(): void
    {
        $paths = new SocketPath($this->project->dir);
        $path = $paths->forServer();
        $target = $this->project->dir.'/target';
        \file_put_contents($target, data: 'precious');
        \symlink($target, $path);

        $this->assertIsString($this->server()->start());
        $this->assertTrue(\is_link($path));
        $this->assertSame('precious', \file_get_contents($target));
    }

    public function testOnceTheOwnerQuitsTheOneThatWaitedServes(): void
    {
        $paths = new SocketPath($this->project->dir);
        $owner = new SocketServer($paths, self::sessions(), static fn (string $line): null => null);
        $this->assertNull($owner->start());
        $logged = [];
        $waiting = new SocketServer(
            $paths,
            self::sessions(),
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            retryEvery: 0.05,
        );

        try {
            $this->assertSame('Another Porta is already serving MCP for this project', $waiting->start());
            $this->pump(0.15);
            $this->assertNull($waiting->path(), 'not while the owner serves');

            $owner->stop();
            $this->await(static fn (): bool => null !== $waiting->path(), what: 'the take-over');
            $this->assertSame(['MCP is served from this Porta now'], $logged);
            $this->assertIsResource($this->connect((string) $waiting->path()));
        } finally {
            $waiting->stop();
            $owner->stop();
        }
    }

    public function testStoppingWhileWaitingStopsTheWait(): void
    {
        $paths = new SocketPath($this->project->dir);
        $owner = new SocketServer($paths, self::sessions(), static fn (string $line): null => null);
        $this->assertNull($owner->start());
        $waiting = new SocketServer($paths, self::sessions(), static fn (string $line): null => null, retryEvery: 0.05);
        $waiting->start();

        $waiting->stop();
        $owner->stop();
        $this->pump(0.15);

        $this->assertNull($waiting->path(), 'it gave up waiting when told to stop');
    }

    /** @return \Closure(\Closure(array<string, mixed>): void): Session sessions with nothing to offer, for a server only connected to */
    private static function sessions(): \Closure
    {
        return static fn (\Closure $send): Session => new Session($send, new ToolCatalog(static fn (): array => [], 'mcp', static fn (): array => []), '1.0');
    }
}
