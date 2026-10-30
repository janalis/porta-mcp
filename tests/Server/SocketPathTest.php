<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Server;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Server\SocketPath;
use Porta\Tests\Support\TempProject;

final class SocketPathTest extends TestCase
{
    public function testAShortRootListensInItsOwnPortaDirectoryWhichIsCreated(): void
    {
        $project = new TempProject();
        $paths = new SocketPath($project->dir);

        $this->assertSame($project->dir.'/.porta/mcp.sock', $paths->forServer());
        $this->assertDirectoryExists($project->dir.'/.porta');
        $this->assertFileDoesNotExist($paths->pointer(), 'the default needs no pointer');
        $this->assertSame($project->dir.'/.porta/mcp.sock', $paths->current());
    }

    public function testALongRootListensInTheTempDirectoryUnderARandomNameAndPointsTheBridgeThere(): void
    {
        $project = new TempProject();
        $root = $project->dir.'/'.\str_repeat('deep/', times: 20);
        \mkdir($root, recursive: true);
        // where a socket's whole path fits
        $temp = \sys_get_temp_dir();
        $server = new SocketPath($root, $temp.'/');

        $path = $server->forServer();

        $this->assertMatchesRegularExpression('~^'.\preg_quote($temp, delimiter: '~').'/porta-mcp-[0-9a-f]{16}\.sock$~', $path);
        $this->assertSame($root.'/.porta/mcp.sock', $server->current(), 'nothing listening there yet: not believed');
        $listening = self::listen($path);
        $this->assertSame(\realpath($temp).'/'.\basename($path), new SocketPath($root, '/elsewhere')->current(), 'the bridge reads the pointer, whatever its own temp dir');
        $again = $server->forServer();
        $this->assertNotSame($path, $again, 'a name nobody can guess, and so take first');
        $listening = self::listen($again);
        $this->assertSame(\realpath($temp).'/'.\basename($again), $server->current(), 'the pointer follows the latest bind');
        \fclose($listening);
        \unlink($path);
        \unlink($again);

        $server->release();
        $this->assertFileDoesNotExist($server->pointer());
        $this->assertSame($root.'/.porta/mcp.sock', $server->current(), 'no pointer, the default');
        $server->release(); // already gone: nothing to do
    }

    public function testAPortaDirectoryThatCannotBeMadeLeavesTheDefaultForTheBindToRefuse(): void
    {
        // A file holds the name the folder needs, on a root long enough that
        // the socket would otherwise go to the temp dir behind a pointer.
        $project = new TempProject();
        $root = $project->dir.'/'.\str_repeat('deep/', times: 20);
        \mkdir($root, recursive: true);
        \file_put_contents($root.'/.porta', data: 'not a folder');
        $paths = new SocketPath($root, '/t/');

        $this->assertSame($root.'/.porta/mcp.sock', $paths->forServer(), 'no pointer to write, so no temp-dir socket to point at');
    }

    public function testTheCutOffIsTheLongestBindablePath(): void
    {
        $project = new TempProject();
        $suffix = '/.porta/mcp.sock';
        $fits = $project->dir.'/'.\str_repeat('a', SocketPath::MAX_LENGTH - \strlen($project->dir) - 1 - \strlen($suffix));
        $over = $fits.'b';

        $this->assertSame($fits.$suffix, new SocketPath($fits, '/t')->forServer());
        $this->assertStringStartsWith('/t/porta-mcp-', new SocketPath($over, '/t')->forServer());
    }

    /** @return resource a socket listening at $path */
    private static function listen(string $path)
    {
        $server = \stream_socket_server('unix://'.$path);

        return false === $server ? throw new \RuntimeException('cannot listen at '.$path) : $server;
    }

    public function testABlankPointerFallsBackToTheDefault(): void
    {
        $project = new TempProject(['.porta/mcp.sock.path' => "  \n"]);

        $this->assertSame($project->dir.'/.porta/mcp.sock', new SocketPath($project->dir)->current());
    }

    /**
     * The pointer is a file in the project, and a project is something one
     * clones: a pointer naming anything but a socket of OUR naming would send
     * the bridge, and the IDE's clean-up of a dead socket, wherever it said.
     */
    public function testAPointerNotOfOurNamingIsIgnored(): void
    {
        foreach (['/Users/x/somefile', 'porta-mcp-0123456789abcdef.sock', '/t/porta-mcp-0123456789abcdef.sock/../x', '/t/porta-mcp-0123.sock'] as $foreign) {
            $project = new TempProject(['.porta/mcp.sock.path' => $foreign]);

            $this->assertSame($project->dir.'/.porta/mcp.sock', new SocketPath($project->dir)->current(), $foreign);
        }
        $project = new TempProject(['tmp/porta-mcp-fedcba9876543210.sock' => 'not a socket']);
        // where a socket's whole path fits
        $socket = (string) \realpath(\sys_get_temp_dir()).'/porta-mcp-'.\bin2hex(\random_bytes(8)).'.sock';
        $listening = self::listen($socket);
        $default = $project->dir.'/.porta/mcp.sock';
        $project->write('.porta/mcp.sock.path', $socket."\n");
        $this->assertSame($socket, new SocketPath($project->dir)->current(), 'ours, from any temp dir');
        $this->assertSame(
            $default,
            new SocketPath($project->dir, user: (int) \fileowner($socket) + 1)->current(),
            'another user\'s, in a temp dir they share: someone else listening at the name a clone gave',
        );
        foreach (['porta-mcp-fedcba9876543210.sock', 'porta-mcp-aaaaaaaaaaaaaaaa.sock'] as $name) {
            if ('porta-mcp-aaaaaaaaaaaaaaaa.sock' === $name) {
                \symlink($socket, $project->path('tmp/'.$name)); // passes for the user's, and can be aimed elsewhere once believed
            }
            $project->write('.porta/mcp.sock.path', $project->path('tmp/'.$name));
            $this->assertSame($default, new SocketPath($project->dir)->current(), $name);
        }
        \fclose($listening);
        \unlink($socket);
    }
}
