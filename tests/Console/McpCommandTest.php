<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Console\McpCommand;
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
    private function bridge(\Closure $connect, $stdin, $stdout): CommandTester
    {
        $application = new Application();
        $application->addCommand(new McpCommand($this->project->dir, $connect, $stdin, $stdout));
        $tester = new CommandTester($application->find('mcp'));
        $tester->execute([], ['capture_stderr_separately' => true]);

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
        [$stdinEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP); // never readable
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);

        $tester = $this->bridge(static fn (string $path) => $bridgeEnd, $stdinEnd, $stdout);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Porta closed the connection.', $tester->getErrorOutput());
    }

    public function testWithNoIdeRunningItSaysWhatToDo(): void
    {
        $stdout = \fopen('php://memory', mode: 'w+');
        $this->assertIsResource($stdout);

        $tester = $this->bridge(static fn (string $path): null => null, $this->stdin(''), $stdout);

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
        $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No Porta is serving MCP', $tester->getErrorOutput());
    }
}
