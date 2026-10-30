<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\CommandRunRequested;
use Porta\Ide;
use Porta\LocalHistory\SnapshotStore;
use Porta\McpPlugin\Tests\Support\TalksMcp;
use Porta\Run\RunController;
use Porta\Search\ProjectSearch;
use Porta\Terminal\TerminalSession;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The tools that reach past the open buffers: searching the project, saving a
 * file, and running tasks and tests in the terminal panel — each behind the
 * setting that lets an agent do it.
 */
final class McpWorkspaceE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use TalksMcp;
    use UntrustsProjects;
    use UsesBundledPlugins;

    /** @param array<string, mixed> $mcp the plugin's settings */
    private static function project(array $mcp = [], array $files = []): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n// needle one\necho 'a';\n",
            'src/B.php' => "<?php\n// Needle two\n",
            'lib/C.txt' => "needle three\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['mcp'], 'plugins' => ['mcp' => $mcp], 'languages' => ['php' => ['runCommand' => \PHP_BINARY]]], \JSON_THROW_ON_ERROR),
            ...$files,
        ]);
    }

    /** @return list<string> */
    private function names(): array
    {
        return \array_column($this->request('tools/list')['result']['tools'], 'name');
    }

    public function testAnAgentsEditLeavesTheUsersTabAndCaretWhereTheyAreUnlessFollowed(): void
    {
        $project = self::project();
        $a = (string) \realpath($project->path('src/A.php'));
        $b = (string) \realpath($project->path('src/B.php'));
        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                $this->connect($project);
                $this->ok('open_file', ['path' => 'src/A.php', 'line' => 3, 'column' => 3]);

                $this->ok('edit_buffer', ['oldText' => '// needle one', 'newText' => "// one\n// two"]);
                $this->assertSame(['line' => 4, 'column' => 3], $this->json('context')['caret'], 'a line inserted above: the caret moved down with its text');
                $this->ok('edit_buffer', ['path' => 'src/B.php', 'oldText' => 'two', 'newText' => '2']);
                $context = $this->json('context');
                $this->assertSame($a, $context['activeFile'], 'the edited file waits behind');
                $this->assertSame([['path' => $a, 'dirty' => true], ['path' => $b, 'dirty' => true]], $context['openFiles']);
            },
        ]);

        $followed = self::project(['followAgent' => true]);
        $this->runIde($followed, $followed->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($followed): void {
                $this->connect($followed);
                $this->ok('edit_buffer', ['path' => 'src/B.php', 'oldText' => 'two', 'newText' => '2']);
                $this->assertSame((string) \realpath($followed->path('src/B.php')), $this->json('context')['activeFile'], 'followed: brought forward');
            },
        ]);
    }

    public function testAgentsCreateMoveAndDeleteFilesAndLocalHistoryKeepsWhatTheyReplaced(): void
    {
        $off = self::project();
        $this->runIde($off, null, [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($off): void {
                $this->connect($off);
                $this->assertNotContains('delete_file', $this->names(), 'off by default');
            },
        ]);

        $project = new TempProject([
            'src/A.php' => "<?php\n// needle one\necho 'a';\n",
            'src/B.php' => "<?php\n// b\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['mcp', 'local-history'], 'plugins' => ['mcp' => ['allowFileOps' => true]]], \JSON_THROW_ON_ERROR),
        ]);
        $root = (string) \realpath($project->dir);
        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $root): void {
                $this->connect($project);
                $history = new SnapshotStore($project->dir, new NativeClock());

                $this->assertSame('Created '.$root.'/src/New/C.php', $this->ok('create_file', ['path' => 'src/New/C.php', 'text' => "<?php\n"]));
                $this->assertSame("<?php\n", \file_get_contents($root.'/src/New/C.php'));
                $this->awaitPainted($tui, $terminal, 'test editing C.php');
                $this->refused('create_file', ['path' => 'src/B.php'], 'B.php already exists');
                $this->ok('replace_buffer', ['path' => 'src/New/C.php', 'text' => "<?php\n// c\n"]);
                $this->assertSame("<?php\n", $history->read($history->list($root.'/src/New/C.php')[0]['file'] ?? ''), 'a replace keeps the version before it too, newest first');
                // A file not there yet is judged by its folder's real place: through a link out, refused.
                $outside = new TempProject([]);
                \symlink($outside->dir, $project->path('out'));
                $this->refused('create_file', ['path' => 'out/escaped.php', 'text' => 'x'], 'out/escaped.php is outside the project');
                $this->assertFileDoesNotExist($outside->path('escaped.php'));
                \unlink($project->path('out'));

                // The first edit keeps the version before it.
                $this->ok('edit_buffer', ['oldText' => '// needle one', 'newText' => '// agent']);
                $this->ok('edit_buffer', ['oldText' => '// agent', 'newText' => '// agent again']);
                $this->assertSame(
                    ["<?php\n// needle one\necho 'a';\n"],
                    \array_map(static fn (array $row): ?string => $history->read($row['file']), $history->list($root.'/src/A.php')),
                    'once per agent and file',
                );

                // A move takes the tab, its unsaved edit and its history along.
                $this->assertSame(\sprintf('Moved %1$s/src/A.php to %1$s/lib/A2.php', $root), $this->ok('move_file', ['from' => 'src/A.php', 'to' => 'lib/A2.php']));
                $this->assertSame(
                    [['path' => $root.'/src/New/C.php', 'dirty' => true], ['path' => $root.'/lib/A2.php', 'dirty' => true]],
                    $this->json('context')['openFiles'],
                    'C, from the replace above, and A under its new name',
                );
                $this->awaitPainted($tui, $terminal, 'test editing A2.php');
                $this->assertStringContainsString('// agent again', $this->ok('read_buffer', ['path' => 'lib/A2.php']));
                $this->assertCount(1, $history->list($root.'/lib/A2.php'));
                $this->refused('move_file', ['from' => 'nowhere.php', 'to' => 'x.php'], 'is not there to rename');

                // A delete keeps the text in local history; unsaved edits and folders are refused.
                $this->refused('delete_file', ['path' => 'lib/A2.php'], 'unsaved changes');
                $this->refused('delete_file', ['path' => 'src/New'], 'is a folder');
                $this->assertStringContainsString('restorable from Recent Changes', $this->ok('delete_file', ['path' => 'src/B.php']));
                $this->assertFileDoesNotExist($root.'/src/B.php');
                $this->assertSame(["<?php\n// b\n"], \array_map(static fn (array $row): ?string => $history->read($row['file']), $history->list($root.'/src/B.php')));

                // What the status cell's menu does: local history's own list, asked for by id.
                Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new CommandRunRequested('local-history:recent_changes'));
                $this->awaitPainted($tui, $terminal, 'B.php');
                $this->assertStringContainsString('Recent Changes', \implode("\n", $this->screenGrid($tui, $terminal)));
            },
        ]);
    }

    public function testSearchFindsTextAcrossTheProjectLikeFindInFiles(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $root): void {
                $this->connect($project);
                $this->assertContains('search', $this->names());

                $found = $this->json('search', ['query' => 'needle']);
                $this->assertSame(
                    [
                        ['path' => $root.'/lib/C.txt', 'line' => 1, 'text' => 'needle three'],
                        ['path' => $root.'/src/A.php', 'line' => 2, 'text' => '// needle one'],
                        ['path' => $root.'/src/B.php', 'line' => 2, 'text' => '// Needle two'],
                    ],
                    $this->sorted($found['matches']),
                    'case-insensitive by default, like the Find in Files box',
                );
                $this->assertFalse($found['truncated']);
                $project->write('lib/Many.txt', \str_repeat("hay\n", times: 600));
                $many = $this->json('search', ['query' => 'hay']);
                $this->assertSame([ProjectSearch::MAX_RESULTS, true], [\count($many['matches']), $many['truncated']], 'a full page says there may be more');
                $this->assertStringContainsString('unsaved edits', $found['note']);

                $this->assertSame(['// Needle two'], \array_column($this->json('search', ['query' => 'Needle', 'caseSensitive' => true])['matches'], 'text'));
                $this->assertSame(['needle three'], \array_column($this->json('search', ['query' => 'needle', 'include' => '*.txt'])['matches'], 'text'));
                $this->assertCount(2, $this->json('search', ['query' => 'needle', 'paths' => ['src']])['matches']);
                $this->assertCount(3, $this->json('search', ['query' => 'needle', 'paths' => ['.']])['matches'], 'the root itself is a scope');
                $this->assertSame(['// needle one'], \array_column($this->json('search', ['query' => 'needle\s+one', 'regex' => true])['matches'], 'text'));
                $this->assertSame([], $this->json('search', ['query' => 'need', 'wholeWord' => true])['matches']);

                $this->refused('search', ['query' => ' '], 'query is required');
                $this->refused('search', ['query' => 'x', 'regex' => 'yes'], 'regex is true or false');
                $this->refused('search', ['query' => 'x', 'paths' => 'src'], 'paths is a list of paths');
                $this->refused('search', ['query' => 'x', 'paths' => ['src', 3]], 'paths is a list of paths');
                $this->refused('search', ['query' => 'x', 'paths' => ['../elsewhere']], 'is outside the project');
            },
        ]);
    }

    public function testSavingIsOffUntilTheUserAllowsItAndNeverOverwritesAnotherWriter(): void
    {
        $off = self::project();
        $this->runIde($off, $off->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($off): void {
                $this->connect($off);
                $this->assertNotContains('save_buffer', $this->names(), 'off by default');
                $this->assertNotContains('rename', $this->names(), 'it rewrites files that are not open: a save too');
            },
        ]);

        $project = self::project(['allowSave' => true]);
        $a = (string) \realpath($project->path('src/A.php'));
        $b = (string) \realpath($project->path('src/B.php'));
        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                $this->connect($project);
                $this->ok('replace_buffer', ['text' => "<?php\n// saved by the agent\n"]);

                $this->assertSame('Saved '.$a, $this->ok('save_buffer'));
                $this->assertSame("<?php\n// saved by the agent\n", \file_get_contents($a), 'on disk now');
                $this->assertFalse($this->json('context')['openFiles'][0]['dirty']);
                $this->assertSame('Saved '.$a, $this->ok('save_buffer', ['path' => 'src/A.php']), 'nothing to save is not a failure');
                $this->refused('save_buffer', ['path' => 'src/B.php'], $b.' was not saved: it is not open in the editor');

                // Another writer changed the file while the buffer held edits.
                $this->ok('replace_buffer', ['text' => "<?php\n// mine\n"]);
                Services::resolve($ide, BufferManager::class)->get($a)->diskChangedUnderEdit = true;
                $this->refused('save_buffer', [], 'it changed on disk since it was opened');
                $this->assertSame("<?php\n// saved by the agent\n", \file_get_contents($a), 'the other writer\'s file is untouched');
            },
        ]);

        $empty = self::project(['allowSave' => true]);
        $this->runIde($empty, null, [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($empty): void {
                $this->connect($empty);
                $this->refused('save_buffer', [], 'No file is open');
            },
        ]);
    }

    public function testWhatPublishesIsOffUntilTheUserAllowsIt(): void
    {
        foreach ([[[], false], [['allowPublish' => true], true]] as [$mcp, $offered]) {
            $project = new TempProject([
                'src/A.php' => "<?php\n",
                '.porta.json' => \json_encode(['enabledPlugins' => ['mcp', 'review', 'diff', 'git'], 'plugins' => ['mcp' => $mcp]], \JSON_THROW_ON_ERROR),
            ]);
            $this->runIde($project, $project->path('src/A.php'), [
                100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $offered): void {
                    $this->connect($project);
                    $names = $this->names();
                    $this->assertContains('review_threads', $names, 'reading the review is always on');
                    $publishing = \array_values(\array_intersect(['review_reply', 'review_resolve', 'review_comment'], $names));
                    $this->assertSame($offered ? ['review_reply', 'review_resolve', 'review_comment'] : [], $publishing);
                },
            ]);
        }
    }

    public function testARestrictedProjectsRunsAreNoAgentsToStartEvenWithRunsAllowedEverywhere(): void
    {
        // Allowed in the user's own config, for every project: what a
        // restricted project cannot take away, and still not enough.
        $project = self::project(['allowRuns' => true], ['vendor/bin/phpunit' => "#!/bin/sh\nexit 0\n"]);
        $global = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->untrustProjects();
        \mkdir($this->untrustingHome->path('.porta'));
        \file_put_contents($this->untrustingHome->path('.porta/config.json'), \json_encode($global, \JSON_THROW_ON_ERROR));
        \unlink($project->path('.porta.json'));

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                $this->connect($project);
                $names = $this->names();
                $this->assertContains('search', $names, 'the agent is connected, and offered what reads');
                $this->assertSame([], \array_values(\array_intersect(['run_configurations', 'run', 'run_tests', 'run_output'], $names)), 'but nothing that runs the project');
            },
        ]);
    }

    public function testRunsAndTestsAreOffUntilTheUserAllowsThemAndRunWithoutTakingTheScreen(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // the fake phpunit is a /bin/sh script
        $off = self::project();
        $this->runIde($off, $off->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($off): void {
                $this->connect($off);
                $this->assertNotContains('run', $this->names(), 'off by default');
            },
        ]);

        $project = self::project(['allowRuns' => true, 'runTimeout' => 2], [
            'src/Slow.php' => "<?php\nsleep(20);\n",
            'vendor/bin/phpunit' => "#!/bin/sh\necho \"phpunit got: \$*\"\nexit 1\n",
        ]);
        \chmod($project->path('vendor/bin/phpunit'), permissions: 0o755);
        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                $this->connect($project);
                $focused = $tui->getFocus();

                $this->assertSame(
                    [['label' => 'Run current file: A.php', 'command' => \PHP_BINARY.' '.\realpath($project->path('src/A.php'))]],
                    $this->json('run_configurations'),
                );
                $ran = $this->json('run', ['label' => 'Run current file: A.php']);
                $this->assertSame(['label' => 'Run current file: A.php', 'running' => false, 'exitCode' => 0], \array_intersect_key($ran, ['label' => 1, 'running' => 1, 'exitCode' => 1]));
                $this->assertStringContainsString('a', $ran['output']);
                $this->assertSame($focused, $tui->getFocus(), 'the run did not take the focus');

                $tests = $this->json('run_tests', ['path' => 'src', 'filter' => 'testIt']);
                $this->assertSame(1, $tests['exitCode']);
                $this->assertStringContainsString('phpunit got: '.\realpath($project->path('src')).' --filter testIt', $tests['output']);
                $this->assertSame(['label' => 'phpunit', 'output' => $tests['output']], \array_intersect_key($this->json('run_output', ['label' => 'phpunit']), ['label' => 1, 'output' => 1]));
                $this->assertStringContainsString('phpunit got: ', $this->json('run_tests')['output'], 'all of them');

                $started = $this->json('run_tests', ['wait' => false]);
                $this->assertSame(['label' => 'phpunit', 'running' => true, 'note' => 'Started — read it with run_output'], $started);

                $this->ok('open_file', ['path' => 'src/Slow.php']);
                $slow = $this->json('run', ['label' => 'Run current file: Slow.php']);
                $this->assertTrue($slow['running']);
                $this->assertSame('Still running after 2s — read it with run_output', $slow['note']);
                // A call still waiting when its run is replaced is told so.
                $waiting = $this->send('tools/call', ['name' => 'run', 'arguments' => ['label' => 'Run current file: Slow.php']]);
                $replaced = $this->json('run', ['label' => 'Run current file: Slow.php', 'wait' => false]);
                $this->assertTrue($replaced['running']);
                $answer = \json_decode($this->answer($waiting)['result']['content'][0]['text'], associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame('Stopped before it finished: the run was replaced or its tab closed', $answer['note']);

                $this->refused('run', ['label' => 'nope'], 'No run configuration "nope" — there are: Run current file: Slow.php');
                $this->refused('run_output', ['label' => 'nope'], 'No run "nope" has been started');
                $this->refused('run_tests', ['wait' => 'no'], 'wait is true or false');
                $this->assertInstanceOf(TerminalSession::class, Services::resolve($ide, RunController::class)->runSession('phpunit'), 'the runs are tabs in the terminal panel');
            },
        ]);

        $bare = self::project(['allowRuns' => true]);
        $this->runIde($bare, null, [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($bare): void {
                $this->connect($bare);
                $this->refused('run_tests', [], 'This project has no vendor/bin/phpunit');
            },
        ]);
    }

    /**
     * grep walks directories in its own order.
     *
     * @param list<array{path: string, line: int, text: string}> $matches
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    private function sorted(array $matches): array
    {
        \usort($matches, static fn (array $a, array $b): int => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $matches;
    }
}
