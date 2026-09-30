<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Ide;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Ide\LockFile;
use Porta\Tests\Support\TempProject;

/** How Claude Code finds this IDE: a file per port, where the CLI looks, readable by the user alone. */
final class LockFileTest extends TestCase
{
    private TempProject $dir;

    private string|false $config;

    private string|false $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = new TempProject();
        $this->config = \getenv('CLAUDE_CONFIG_DIR');
        $this->home = \getenv('HOME');
    }

    #[\Override]
    protected function tearDown(): void
    {
        \putenv(false === $this->config ? 'CLAUDE_CONFIG_DIR' : 'CLAUDE_CONFIG_DIR='.$this->config);
        \putenv(false === $this->home ? 'HOME' : 'HOME='.$this->home);
    }

    public function testTheDirectoryIsTheOneTheCliReads(): void
    {
        \putenv('CLAUDE_CONFIG_DIR=/cfg');
        $this->assertSame('/cfg/ide', LockFile::directory());
        \putenv('CLAUDE_CONFIG_DIR=');
        \putenv('HOME=/home/me');
        $this->assertSame('/home/me/.claude/ide', LockFile::directory(), 'an empty setting is no setting');
    }

    public function testItIsWrittenWholeAndPrivateAndTakenBack(): void
    {
        $lock = new LockFile($this->dir->path('cfg/ide'));

        $token = \bin2hex(\random_bytes(16));
        $this->assertNull($lock->write(4242, ['/p'], $token));

        $path = $this->dir->path('cfg/ide/4242.lock');
        $this->assertSame(
            ['pid' => \getmypid(), 'workspaceFolders' => ['/p'], 'ideName' => 'Porta', 'transport' => 'ws', 'authToken' => $token],
            \json_decode((string) \file_get_contents($path), associative: true),
        );
        $this->assertSame(0o600, \fileperms($path) & 0o777);
        $this->assertSame(0o700, \fileperms($this->dir->path('cfg/ide')) & 0o777);
        $this->assertFileDoesNotExist($path.'.tmp');

        $lock->remove(4242);
        $this->assertFileDoesNotExist($path);
        $lock->remove(4242); // already gone: nothing to do
    }

    public function testWhereItCannotBeWrittenItSaysSo(): void
    {
        $blocked = $this->dir->write('file', 'in the way');

        $this->assertSame($blocked.'/ide could not be created', new LockFile($blocked.'/ide')->write(1, [], 't'));

        \mkdir($this->dir->path('readonly'), permissions: 0o500);
        $this->assertSame($this->dir->path('readonly').' is not writable', new LockFile($this->dir->path('readonly'))->write(1, [], 't'));
        \chmod($this->dir->path('readonly'), permissions: 0o700);
    }
}
