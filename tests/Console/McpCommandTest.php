<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Console\McpCommand;
use Porta\McpPlugin\Protocol\Relay;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bridge, with the IDE played by the far end of a socket pair: whatever
 * that end already wrote is what the IDE answered, and whatever reached it is
 * what the bridge relayed.
 */
final class McpCommandTest extends TestCase
{
    private TempProject $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    /** @return resource a stdin that holds $text and then ends */
    private function stdin(string $text)
    {
        $file = $this->project->write('stdin', $text);
        $stream = \fopen($file, mode: 'r');
        $this->assertIsResource($stream);

        return $stream;
    }

    /**
     * @param (\Closure(string): (resource|null)) $connect
     * @param resource                            $stdin
     * @param resource                            $stdout
     */
    private function bridge(\Closure $connect, $stdin, $stdout, ?int $wait = null): CommandTester
    {
        $application = new Application();
        $application->addCommand(new McpCommand($this->project->dir, $connect, $stdin, $stdout, retry: 0.01));
        $tester = new CommandTester($application->find('mcp'));
        $tester->execute(null === $wait ? [] : ['--wait' => (string) $wait], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** @param resource $stream */
    private static function drain($stream): string
    {
        \stream_set_blocking($stream, enable: false);

        return (string) \stream_get_contents($stream);
    }

    public function testItRelaysBothWaysUntilTheClientHangsUp(): void
    {
        [$bridgeEnd, $ideEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($ideEnd, '{"jsonrpc":"2.0","id":1,"result":{}}'."\n");
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);
        $asked = null;

        $tester = $this->bridge(
            static function (string $path) use ($bridgeEnd, &$asked) {
                $asked = $path;

                return $bridgeEnd;
            },
            $this->stdin('{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"),
            $stdout,
        );

        $this->assertSame(0, $tester->getStatusCode(), 'the client closing its stdin is the normal end');
        $this->assertSame($this->project->dir.'/.porta/mcp.sock', $asked);
        \rewind($stdout);
        $this->assertSame('{"jsonrpc":"2.0","id":1,"result":{}}'."\n", \stream_get_contents($stdout), 'what the IDE said reached the client');
        $this->assertSame('{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n", self::drain($ideEnd), 'what the client said reached the IDE');
    }

    public function testTheIdeHangingUpEndsTheBridgeWithAFailure(): void
    {
        [$bridgeEnd, $ideEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fclose($ideEnd);
        [$stdinEnd, $clientEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP); // never readable while the client end is held
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);
        $connects = 0;

        $tester = $this->bridge(
            static function (string $path) use ($bridgeEnd, &$connects) {
                return 1 === ++$connects ? $bridgeEnd : null;
            },
            $stdinEnd,
            $stdout,
            wait: 0,
        );

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Porta closed the connection.', $tester->getErrorOutput());
        $this->assertIsResource($clientEnd, 'the client never hung up: the IDE going is what ended it');
    }

    public function testWithNoIdeRunningItSaysWhatToDo(): void
    {
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);

        $tester = $this->bridge(static fn (string $path): null => null, $this->stdin(''), $stdout, wait: 0);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No Porta is serving MCP for '.$this->project->dir, $tester->getErrorOutput());
        $this->assertStringContainsString('mcp plugin enabled', $tester->getErrorOutput());
    }

    public function testTheRealConnectReachesAListeningSocket(): void
    {
        $path = $this->project->path('.porta/mcp.sock');
        \mkdir(\dirname($path));
        $server = \stream_socket_server('unix://'.$path);
        $this->assertNotFalse($server);
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);

        // No connector: the command connects for real, then the empty stdin
        // ends it at once.
        $application = new Application();
        $application->addCommand(new McpCommand($this->project->dir, null, $this->stdin(''), $stdout));
        $tester = new CommandTester($application->find('mcp'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertIsResource(\stream_socket_accept($server, timeout: 1.0), 'it had connected');
    }

    public function testTheRealConnectReportsNoIde(): void
    {
        $application = new Application();
        $application->addCommand(new McpCommand($this->project->dir, null, $this->stdin(''), \fopen('php://memory', mode: 'w+')));
        $tester = new CommandTester($application->find('mcp'));
        $tester->execute(['--wait' => '0'], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No Porta is serving MCP', $tester->getErrorOutput());
    }

    public function testItWaitsForTheIdeAndHandsItTheHandshakeTheClientAlreadyMade(): void
    {
        [$stdinEnd, $clientEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($clientEnd, self::INITIALIZE."\n".self::INITIALIZED."\n");
        [$bridgeEnd, $ideEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($ideEnd, '{"jsonrpc":"2.0","id":"'.Relay::REPLAY_ID.'","result":{}}'."\n");
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);
        $connects = 0;

        $tester = $this->bridge(
            static function (string $path) use ($bridgeEnd, $clientEnd, &$connects) {
                if (++$connects < 3) {
                    return null;
                }
                \fclose($clientEnd); // the client hangs up once the IDE is there

                return $bridgeEnd;
            },
            $stdinEnd,
            $stdout,
        );

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(3, $connects, 'it kept trying until the IDE was up');
        $this->assertStringContainsString('Waiting for Porta to open '.$this->project->dir, $tester->getErrorOutput());
        $said = self::lines($stdout);
        $this->assertCount(2, $said, 'the handshake answered, then the list changed — not the replay\'s answer');
        $this->assertSame(1, $said[0]['id'] ?? null);
        $this->assertSame('2025-06-18', $said[0]['result']['protocolVersion'] ?? null);
        $this->assertSame('notifications/tools/list_changed', $said[1]['method'] ?? null);
        $replayed = \array_map(static fn (string $line): mixed => \json_decode($line, associative: true), \array_filter(\explode("\n", self::drain($ideEnd))));
        $this->assertSame(
            [
                [
                    'jsonrpc' => '2.0',
                    'id' => Relay::REPLAY_ID,
                    'method' => 'initialize',
                    'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
                ],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ],
            \array_values($replayed),
        );
    }

    public function testAnIdeThatQuitsMidCallIsAnsweredForAndTheNextOneTakesOver(): void
    {
        [$stdinEnd, $clientEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($clientEnd, self::INITIALIZE."\n".self::INITIALIZED."\n".'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"context"}}'."\n");
        [$first, $firstIde] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($firstIde, '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18"}}'."\n");
        \fclose($firstIde); // answers the handshake, then quits with the call running
        [$second, $secondIde] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fwrite($secondIde, '{"jsonrpc":"2.0","id":"'.Relay::REPLAY_ID.'","result":{}}'."\n");
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);
        $connects = 0;

        $tester = $this->bridge(
            static function (string $path) use ($first, $second, $clientEnd, &$connects) {
                ++$connects;
                if (1 === $connects) {
                    return $first;
                }
                \fclose($clientEnd);

                return $second;
            },
            $stdinEnd,
            $stdout,
        );

        $this->assertSame(0, $tester->getStatusCode());
        $said = self::lines($stdout);
        $this->assertCount(3, $said);
        $this->assertSame(1, $said[0]['id'] ?? null, 'the first IDE\'s answer reached the client');
        $this->assertSame(2, $said[1]['id'] ?? null);
        $this->assertStringContainsString('Porta closed while this call ran', $said[1]['error']['message'] ?? '');
        $this->assertSame('notifications/tools/list_changed', $said[2]['method'] ?? null);
        $this->assertStringContainsString('"id":"'.Relay::REPLAY_ID.'"', self::drain($secondIde), 'the second IDE was handed the handshake');
    }

    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}';

    private const string INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';

    /**
     * @param resource $stdout
     *
     * @return list<array<string, mixed>>
     */
    private static function lines($stdout): array
    {
        \rewind($stdout);
        $lines = [];
        foreach (\array_filter(\explode("\n", (string) \stream_get_contents($stdout))) as $line) {
            /** @var array<string, mixed> $message */
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
            $lines[] = $message;
        }

        return $lines;
    }
}
