<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Tools\Arguments;
use Porta\Tests\Support\TempProject;

final class ArgumentsTest extends TestCase
{
    public function testAPathNotThereYetIsSpelledThroughTheProjectsRealPlace(): void
    {
        $project = new TempProject(['src/A.php' => '']);
        $real = (string) \realpath($project->dir);
        $alias = new TempProject([]);
        $link = $alias->path('project');
        \symlink($real, $link);

        // Both spelled through the link, as an agent handed that root may.
        $read = new Arguments(['path' => $link.'/src/New.php', 'to' => $link.'/src/A.php'], $link);

        $this->assertSame($real.'/src/New.php', $read->path(), 'a file to create, through its real folder');
        $this->assertSame($real.'/src/A.php', $read->requiredPathAt('to'), 'a file there, through itself');
    }
}
