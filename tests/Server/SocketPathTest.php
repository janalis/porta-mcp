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
        $server = new SocketPath($root, '/t/');

        $path = $server->forServer();

        $this->assertMatchesRegularExpression('~^/t/porta-mcp-[0-9a-f]{16}\.sock$~', $path);
        $this->assertSame($path, new SocketPath($root, '/elsewhere')->current(), 'the bridge reads the pointer, whatever its own temp dir');
        $again = $server->forServer();
        $this->assertNotSame($path, $again, 'a name nobody can guess, and so take first');
        $this->assertSame($again, $server->current(), 'the pointer follows the latest bind');

        $server->release();
        $this->assertFileDoesNotExist($server->pointer());
        $this->assertSame($root.'/.porta/mcp.sock', $server->current(), 'no pointer, the default');
        $server->release(); // already gone: nothing to do
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

    public function testABlankPointerFallsBackToTheDefault(): void
    {
        $project = new TempProject(['.porta/mcp.sock.path' => "  \n"]);

        $this->assertSame($project->dir.'/.porta/mcp.sock', new SocketPath($project->dir)->current());
    }
}
