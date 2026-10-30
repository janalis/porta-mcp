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
        $this->assertSame($real.'/src/a/b/New.php', new Arguments(['path' => 'src/a/b/New.php'], $link)->path(), 'in folders not there yet, through the deepest one that is');
    }

    /** A link in the project to somewhere else is somewhere else, however deep the new path goes — and one to nothing leads anywhere. */
    public function testALinkOutOfTheProjectIsOutsideItForWhatIsNotThereYet(): void
    {
        $outside = new TempProject([]);
        $project = new TempProject(['a.php' => '']);
        \symlink($outside->dir, $project->path('link'));
        \symlink($outside->path('planted.php'), $project->path('dangling.php'));
        \symlink($outside->path('later'), $project->path('dangling-dir'));

        foreach (['link/x.php', 'link/new/x.php', 'dangling.php', 'dangling-dir/x.php', '../x.php'] as $raw) {
            try {
                new Arguments(['path' => $raw], $project->dir)->path();
                $this->fail($raw.' was taken');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($raw.' is outside the project', $e->getMessage());
            }
        }
    }
}
