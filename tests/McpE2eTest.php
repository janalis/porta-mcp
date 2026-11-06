<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\OpenFocus;
use Porta\Event\AgentEditing;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\McpPlugin\Tests\Support\TalksMcp;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * An agent's session against a real IDE: the socket the plugin opened at
 * startup, the handshake, and every core tool — what it answers, and how it
 * refuses.
 */
final class McpE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use RunsIde;
    use TalksMcp;
    use UsesBundledPlugins;

    private static function project(string $porta = '{"enabledPlugins":["mcp"]}'): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\necho 'a';\n",
            'src/B.php' => "<?php\necho 'b';\n",
            'img.png' => "\x89PNG",
            '.porta.json' => $porta,
        ]);
    }

    public function testAnAgentReadsNavigatesAndEditsTheIde(): void
    {
        $project = self::project();
        $a = (string) \realpath($project->path('src/A.php'));
        $b = (string) \realpath($project->path('src/B.php'));

        $this->runIde($project, null, [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                $this->connect($project);
                $names = \array_column($this->request('tools/list')['result']['tools'], 'name');
                $this->assertSame(
                    [
                        'context',
                        'selection',
                        'read_buffer',
                        'open_file',
                        'diagnostics',
                        'notify',
                        'edit_buffer',
                        'replace_buffer',
                        'insert_text',
                        'search',
                        'definition',
                        'references',
                        'hover',
                        'symbols',
                        'outline',
                        'format',
                    ],
                    $names,
                );

                // Nothing open yet.
                $context = $this->json('context');
                $this->assertSame(\realpath($project->dir), \realpath((string) $context['projectRoot']));
                $this->assertNull($context['activeFile']);
                $this->assertNull($context['caret']);
                $this->assertSame([], $context['selectedFiles']);
                $this->assertSame([], $context['openFiles']);
                $this->refused('read_buffer', [], 'No file is open');
                $this->refused('insert_text', ['text' => 'x'], 'No text file is active');
                $this->refused('replace_buffer', ['text' => 'x'], 'No text file to edit');

                // Opening: at a line and column, and the refusals.
                $this->assertSame('Opened '.$a, $this->ok('open_file', ['path' => 'src/A.php', 'line' => 2, 'column' => 3]));
                $this->assertSame(['line' => 2, 'column' => 3], $this->json('context')['caret'], '1-based, both ways');
                $this->ok('open_file', ['path' => $b, 'line' => 2]);
                $this->assertSame(['line' => 2, 'column' => 1], $this->json('context')['caret'], 'the column defaults to the first');
                $this->ok('open_file', ['path' => 'src/A.php']);
                $this->assertSame($a, $this->json('context')['activeFile']);
                $this->refused('open_file', [], 'path is required');
                $this->refused('open_file', ['path' => ''], 'path is required');
                $this->refused('open_file', ['path' => 'src'], 'is not a file');
                $this->refused('open_file', ['path' => 'src/A.php', 'line' => 0], 'line is a positive integer');
                $this->refused('open_file', ['path' => 'src/A.php', 'line' => '2'], 'line is a positive integer');
                $this->refused('open_file', ['path' => 7], 'path is a string');
                $this->refused('open_file', ['path' => '../outside.php'], '../outside.php is outside the project');
                $this->refused('open_file', ['path' => '/etc/hosts'], 'is outside the project');
                // A link inside the project is judged by where it LEADS.
                \symlink('/etc/hosts', $project->path('hosts.txt'));
                $this->refused('open_file', ['path' => 'hosts.txt'], 'hosts.txt is outside the project');

                $this->assertSame(
                    [['path' => $a, 'dirty' => false], ['path' => $b, 'dirty' => false]],
                    $this->json('context')['openFiles'],
                );

                // Reading: the active file, another open one, one not open.
                $this->assertSame("<?php\necho 'a';\n", $this->ok('read_buffer'));
                $this->assertSame("<?php\necho 'b';\n", $this->ok('read_buffer', ['path' => 'src/B.php']));
                $project->write('src/C.php', "<?php\n");
                $this->refused('read_buffer', ['path' => 'src/C.php'], 'is not open in the editor');
            },
            200 => function (Tui $tui, $terminal, Ide $ide) use ($a, $b): void {
                // Editing: never saved, and undone in one step.
                $this->assertStringContainsString('unsaved', $this->ok('replace_buffer', ['text' => "<?php\n// new\n"]));
                $this->assertSame("<?php\n// new\n", $this->ok('read_buffer'));
                $this->assertSame("<?php\necho 'a';\n", \file_get_contents($a), 'the disk is untouched');
                $this->assertTrue($this->json('context')['openFiles'][0]['dirty']);
                $tui->handleInput("\x1a"); // ctrl+z
                $this->assertSame("<?php\necho 'a';\n", $this->ok('read_buffer'), 'one undo put it all back');

                $this->ok('replace_buffer', ['path' => 'src/B.php', 'text' => "<?php\n// b\n"]);
                $this->assertSame($a, $this->json('context')['activeFile'], 'replacing another file leaves the user\'s tab in front');
                $this->assertSame("<?php\n// b\n", $this->ok('read_buffer', ['path' => $b]));
                $this->ok('replace_buffer', ['path' => 'src/B.php', 'text' => "<?php\n// b2\n"]);
                $this->assertSame("<?php\n// b2\n", $this->ok('read_buffer', ['path' => 'src/B.php']), 'an open file behind, by path');
                $this->ok('replace_buffer', ['path' => 'src/C.php', 'text' => "<?php\n// c\n"]);
                $this->assertSame("<?php\n// c\n", $this->ok('read_buffer', ['path' => 'src/C.php']), 'a closed file is opened first');
                $this->refused('replace_buffer', ['path' => 'src/D.php', 'text' => 'x'], 'is not a file');
                $this->refused('replace_buffer', ['path' => 'img.png', 'text' => 'x'], 'img.png is not one');
                $this->refused('replace_buffer', [], 'text is required');

                $this->ok('open_file', ['path' => 'src/A.php', 'line' => 1, 'column' => 1]);
                $this->assertStringEndsWith(' — unsaved', $inserted = $this->ok('insert_text', ['text' => '// top'."\n"]));
                $this->assertStringStartsWith('Inserted into /', $inserted, 'naming the file');
                $this->assertSame("// top\n<?php\necho 'a';\n", $this->ok('read_buffer'));
            },
            300 => function (Tui $tui, $terminal, Ide $ide) use ($a, $b): void {
                // Diagnostics: whatever the sources published, 1-based.
                $buffers = Services::resolve($ide, BufferService::class);
                $buffers->applyDiagnostics(
                    Uri::fromPath($a),
                    [2 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Undefined', 'col' => 5, 'endCol' => 8, 'code' => 42, 'source' => 'phpstan']]],
                    'phpstan',
                );
                $buffers->applyDiagnostics(Uri::fromPath($b), [0 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'Unused', 'col' => 0, 'endCol' => 0]]], 'lsp');

                $this->assertSame(
                    [
                        ['path' => $a, 'line' => 3, 'column' => 6, 'endColumn' => 8, 'severity' => 'error', 'message' => 'Undefined', 'source' => 'phpstan', 'code' => '42'],
                        ['path' => $b, 'line' => 1, 'column' => 1, 'endColumn' => 1, 'severity' => 'hint', 'message' => 'Unused'],
                    ],
                    $this->json('diagnostics'),
                    'a finding with no width ends on the column it starts on',
                );
                $this->assertCount(1, $this->json('diagnostics', ['path' => 'src/B.php']));

                // Telling the user something.
                $this->assertSame('Shown', $this->ok('notify', ['message' => 'Tests pass']));
                foreach (['success', 'warn', 'error'] as $severity) {
                    $this->ok('notify', ['message' => 'said '.$severity, 'severity' => $severity]);
                }
                $this->assertContains('said error', Toasts::all($ide));
                $this->assertContains('Tests pass', Toasts::all($ide));
                $this->refused('notify', ['message' => ' '], 'message is required');
            },
        ]);

        $this->assertFileDoesNotExist($project->path('.porta/mcp.sock'), 'the socket goes with the IDE');
    }

    public function testAnAgentReadsPartOfAFileChangesOnePieceAndSeesTheSelection(): void
    {
        $project = self::project();
        $a = (string) \realpath($project->path('src/A.php'));

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a): void {
                $this->connect($project);
                $kept = [];
                Services::resolve($ide, EventDispatcherInterface::class)->addListener(AgentEditing::class, static function (AgentEditing $before) use (&$kept): void {
                    $kept[] = [$before->path, $before->previousText];
                });
                $this->ok('insert_text', ['text' => '// top'."\n"]);
                $this->assertSame([[$a, "<?php\necho 'a';\n"]], $kept, 'the version the user had, kept before the first insert');

                // Part of it, numbered.
                $this->assertSame("2\t<?php\n3\techo 'a';", $this->ok('read_buffer', ['startLine' => 2, 'endLine' => 3, 'lineNumbers' => true]));
                $this->assertSame("echo 'a';\n", $this->ok('read_buffer', ['startLine' => 3]), 'to the end');
                $this->assertSame("1\t// top\n2\t<?php\n3\techo 'a';\n4\t", $this->ok('read_buffer', ['lineNumbers' => true]));
                $this->refused('read_buffer', ['startLine' => 9], 'has 4 lines');
                $this->refused('read_buffer', ['startLine' => 3, 'endLine' => 2], 'lines 3 to 2 are not among them');

                // One exact piece, one undo step.
                $this->assertSame('Edited '.$a.' at line 3 — unsaved; the user can undo it in one step', $this->ok('edit_buffer', ['oldText' => "'a'", 'newText' => "'é'\n// more"]));
                $this->assertSame("// top\n<?php\necho 'é'\n// more;\n", $this->ok('read_buffer'));
                $this->ok('edit_buffer', ['oldText' => "'é'\n// more;", 'newText' => 'done;']);
                $this->assertSame("// top\n<?php\necho done;\n", $this->ok('read_buffer'), 'a range across lines, after a multibyte one');
                $this->assertSame("<?php\necho 'a';\n", \file_get_contents($a), 'the disk is untouched');
                $tui->handleInput("\x1a"); // ctrl+z
                $this->assertSame("// top\n<?php\necho 'é'\n// more;\n", $this->ok('read_buffer'), 'each edit is one undo step');
                $this->refused('edit_buffer', ['oldText' => 'nowhere', 'newText' => 'x'], 'oldText is not in '.$a);
                $this->refused('edit_buffer', ['oldText' => '//', 'newText' => 'x'], 'oldText occurs 2 times');
                $this->refused('edit_buffer', ['oldText' => '', 'newText' => 'x'], 'oldText is empty');
                $this->refused('edit_buffer', ['oldText' => 'x'], 'newText is required');
                $this->ok('edit_buffer', ['path' => 'src/B.php', 'oldText' => "'b'", 'newText' => "'b3'"]);
                $this->assertSame("<?php\necho 'b3';\n", $this->ok('read_buffer', ['path' => 'src/B.php']), 'a closed file, opened behind');
                $this->assertSame($a, $this->json('context')['activeFile'], 'the user\'s tab stays in front');
            },
            200 => function (Tui $tui, $terminal, Ide $ide): void {
                // What the user selected.
                $this->assertSame('Nothing is selected', $this->ok('selection'));
                $this->ok('open_file', ['path' => 'src/B.php', 'line' => 2, 'column' => 7]);
                $tui->handleInput("\x1b[1;2C"); // shift+right
                $tui->handleInput("\x1b[1;2C");
                $this->assertSame(
                    ['text' => 'b3', 'start' => ['line' => 2, 'column' => 7], 'end' => ['line' => 2, 'column' => 9]],
                    $this->json('selection'),
                );
            },
        ]);
    }

    public function testAnAgentAsksTheLanguageServerAboutAnyFile(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nclass A {}\n",
            'src/B.php' => "<?php\nnew A();\n",
            'src/U.php' => "<?php\n\$é = new A();\n",
            'README.md' => "# hi\n",
            '.porta.json' => \json_encode(
                [
                    'enabledPlugins' => ['mcp'],
                    'lsp' => ['php' => \PHP_BINARY.' '.__DIR__.'/fixtures/code-lsp-server.php'], // php only: README.md has none
                    'plugins' => ['mcp' => ['lspTimeout' => 1]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $a = (string) \realpath($project->path('src/A.php'));
        $b = (string) \realpath($project->path('src/B.php'));

        // B is open in the editor; A never is — it is lent to the server.
        $this->runIde($project, $project->path('src/B.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                // The timeout is one second, and a server still starting is
                // not the case under test.
                $this->awaitLspSession($ide);
                $this->connect($project);
                $names = \array_column($this->request('tools/list')['result']['tools'], 'name');
                foreach (['definition', 'references', 'hover', 'symbols'] as $tool) {
                    $this->assertContains($tool, $names);
                }

                $this->assertSame(
                    [['line' => 2, 'column' => 7, 'path' => $a, 'text' => 'class A {}']],
                    $this->json('definition', ['path' => 'src/B.php', 'line' => 2, 'column' => 5]),
                    '1-based both ways, with the line\'s text',
                );
                $this->assertSame(
                    ['class A {}', 'new A();'],
                    \array_column($this->json('references', ['path' => 'src/A.php', 'line' => 2, 'column' => 7]), 'text'),
                    'asked about a file nobody has open',
                );
                $this->assertStringContainsString('The one class.', $this->ok('hover', ['path' => 'src/A.php', 'line' => 2]));
                $this->assertSame('The language server has nothing to say about this position', $this->ok('hover', ['path' => 'src/A.php', 'line' => 1]));

                // The agent's caret sits on the character the protocol column
                // names: `$é =` is four UTF-16 units and five bytes.
                $this->ok('hover', ['path' => 'src/U.php', 'line' => 2, 'column' => 5]);
                $this->assertSame(
                    [1 => [['col' => 5, 'label' => Glyphs::AGENT.' test', 'slot' => 0]]],
                    Services::resolve($ide, CodeEditorWidget::class)->decorations->remoteCaretsIn((string) \realpath($project->path('src/U.php'))),
                );

                $symbols = $this->json('symbols', ['query' => 'A']);
                $this->assertSame(['name' => 'A', 'kind' => 'class', 'path' => $a, 'line' => 2], $symbols[0]);
                $this->assertSame('symbol', $symbols[1]['kind'], 'a kind nobody named');

                // Every way it can say no.
                $this->refused('definition', ['path' => 'src/B.php', 'line' => 9], 'Index not ready');
                $this->refused('hover', ['path' => 'src/B.php', 'line' => 9], 'Index not ready');
                $this->refused('symbols', ['query' => 'boom'], 'Index not ready');
                $this->refused('hover', ['path' => 'src/B.php', 'line' => 10], 'The language server did not answer within 1s');
                $this->refused('definition', ['path' => 'README.md', 'line' => 1], 'No language server is configured for README.md');
                $this->refused('definition', ['path' => 'src/B.php'], 'line is required');
                $this->refused('references', ['line' => 1], 'path is required');
                $this->refused('symbols', [], 'query is required');
                $this->assertSame([['path' => $b, 'dirty' => false]], $this->json('context')['openFiles'], 'no tab was opened for A');
            },
        ]);
    }

    public function testAnAgentOutlinesAFileFormatsItAndRenamesASymbol(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nclass A {}\n",
            'src/B.php' => "<?php\nnew A();\n",
            'src/C.php' => "<?php\nnew A();\n",
            'src/E.php' => "<?php\n// e\n",
            'README.md' => "# hi\n",
            '.porta.json' => \json_encode(
                [
                    'enabledPlugins' => ['mcp'],
                    'lsp' => ['php' => \PHP_BINARY.' '.__DIR__.'/fixtures/code-lsp-server.php'],
                    'plugins' => ['mcp' => ['lspTimeout' => 5, 'allowSave' => true]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $a = (string) \realpath($project->path('src/A.php'));
        $b = (string) \realpath($project->path('src/B.php'));
        $c = (string) \realpath($project->path('src/C.php'));

        $this->runIde($project, $project->path('src/B.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                $this->awaitLspSession($ide);
                $this->connect($project);
                $this->assertContains('rename', \array_column($this->request('tools/list')['result']['tools'], 'name'), 'edits and saves allowed');

                $this->assertSame(
                    [
                        ['name' => 'A', 'kind' => 'class', 'line' => 2, 'column' => 7, 'depth' => 0],
                        ['name' => 'run', 'kind' => 'method', 'line' => 2, 'column' => 10, 'depth' => 1],
                    ],
                    $this->json('outline', ['path' => 'src/A.php']),
                    'a file nobody has open, nested, 1-based',
                );
                $this->refused('outline', [], 'path is required');
                $this->refused('outline', ['path' => 'README.md'], 'No language server is configured for README.md');

                // Formatting: the open file, by its own indentation, one undo step.
                $this->assertSame('Formatted '.$b.' — unsaved; the user can undo it in one step', $this->ok('format'));
                $this->assertSame("<?php\n// formatted, tabSize 4\nnew A();\n", $this->ok('read_buffer'));
                $tui->handleInput("\x1a"); // ctrl+z
                $this->assertSame("<?php\nnew A();\n", $this->ok('read_buffer'));
                $this->assertSame($a.' is already formatted', $this->ok('format', ['path' => 'src/A.php']), 'a closed file is opened first');
                $this->assertSame($b, $this->json('context')['activeFile'], 'opened behind the user\'s tab');
                $this->assertContains($a, \array_column($this->json('context')['openFiles'], 'path'));
                $this->refused('format', ['path' => 'src/D.php'], 'is not a file');
                $this->refused('format', ['path' => 'README.md'], 'No language server is configured for README.md');
            },
            200 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b, $c): void {
                // Renaming: open files edited and unsaved, the others rewritten.
                // The user has E open, which the rename does not touch; and a
                // fresh agent, for which A and B were never kept.
                $e = (string) \realpath(\dirname($a).'/E.php');
                Services::resolve($ide, BufferService::class)->openFile($e, OpenFocus::Background);
                $this->connect($project);
                $kept = [];
                Services::resolve($ide, EventDispatcherInterface::class)->addListener(AgentEditing::class, static function (AgentEditing $before) use (&$kept): void {
                    $kept[$before->path] = $before->previousText;
                });
                $renamed = $this->json('rename', ['path' => 'src/A.php', 'line' => 2, 'column' => 7, 'newName' => 'Z']);
                \ksort($kept);
                $this->assertSame(
                    [$a => "<?php\nclass A {}\n", $b => "<?php\nnew A();\n"],
                    $kept,
                    'each open file the rename changed, kept as the user had it; E, untouched, keeps its first keep for a real edit',
                );
                $this->assertSame(3, $renamed['files']);
                $this->assertSame([], $renamed['skipped']);
                $this->assertStringContainsString('Undo Refactoring', $renamed['note']);
                $this->assertSame("<?php\nclass Z {}\n", $this->ok('read_buffer', ['path' => $a]));
                $this->assertSame("<?php\nnew Z();\n", $this->ok('read_buffer', ['path' => $b]));
                $this->assertSame("<?php\nclass A {}\n", \file_get_contents($a), 'an open file stays unsaved');
                $this->assertSame("<?php\nnew Z();\n", \file_get_contents($c), 'a file that is not open is rewritten');

                $this->refused('rename', ['path' => 'src/A.php', 'line' => 2, 'newName' => 'boom'], 'Index not ready');
                $this->refused('rename', ['path' => 'src/A.php', 'line' => 2, 'newName' => 'nothing'], 'cannot rename anything at this position');
                $this->refused('rename', ['path' => 'src/A.php', 'line' => 2, 'newName' => ' '], 'newName is required');
            },
        ]);
    }

    public function testOtherPluginsToolsAreServedAndALiveChangeIsAnnounced(): void
    {
        $this->skipWithoutGit();
        $project = self::project('{"enabledPlugins":["mcp","diff","git"]}');
        $project->initGit('main');
        $project->commitAll('first');
        $project->write('src/A.php', "<?php\necho 'changed';\n");

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                $this->connect($project);
                $names = \array_column($this->request('tools/list')['result']['tools'], 'name');
                $this->assertContains('git_status', $names, 'another plugin\'s tool, prefixed with its name');
                $this->assertContains('git_diff', $names);
                $this->assertSame("## main\n M src/A.php\n", $this->ok('git_status'), 'a tool that waits on a subprocess, over the wire');

                // A setting the tool list depends on, changed live.
                $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                // Neither the manager nor its settings is a public service.
                $settings = Probe::get(Probe::get($lifecycle, 'plugins'), 'settings');
                $this->assertInstanceOf(PluginSettings::class, $settings, 'the manager holds the plugin settings');
                $settings->change('mcp', 'allowEdits', false);
                $lifecycle->onPluginSettingChanged('mcp', 'allowEdits', false);
                $this->awaitMessage(static fn (array $m): bool => 'notifications/tools/list_changed' === ($m['method'] ?? null), 'the setting change announced');
                $this->assertNotContains('insert_text', \array_column($this->request('tools/list')['result']['tools'], 'name'));

                // A plugin that offers tools, disabled live.
                $lifecycle->disable('git');
                $this->awaitMessage(static fn (array $m): bool => 'notifications/tools/list_changed' === ($m['method'] ?? null), 'the disable announced');
                $this->assertNotContains('git_status', \array_column($this->request('tools/list')['result']['tools'], 'name'));
            },
        ]);
    }

    public function testWhenAnotherPortaServesTheProjectItSaysSoAndLeavesItBe(): void
    {
        $project = self::project();
        \mkdir($project->path('.porta'));
        $other = \stream_socket_server('unix://'.$project->path('.porta/mcp.sock'));
        $this->assertNotFalse($other);

        $this->runIde($project, null, [
            100 => function (Tui $tui, $terminal, Ide $ide): void {
                $this->assertContains('Another Porta is already serving MCP for this project', Toasts::all($ide));
            },
        ]);

        $this->assertFileExists($project->path('.porta/mcp.sock'), 'the other one keeps its socket, even after this IDE quits');
        \fclose($other);
    }

    public function testWithEditsTurnedOffTheEditToolsAreNotThere(): void
    {
        $project = self::project('{"enabledPlugins":["mcp"],"plugins":{"mcp":{"allowEdits":false}}}');

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                $this->connect($project);
                $names = \array_column($this->request('tools/list')['result']['tools'], 'name');
                $this->assertNotContains('replace_buffer', $names);
                $this->assertNotContains('insert_text', $names);
                $this->assertNotContains('edit_buffer', $names);
                $this->assertNotContains('format', $names);
                $this->assertNotContains('rename', $names);
                $this->assertContains('read_buffer', $names);

                $answer = $this->request('tools/call', ['name' => 'insert_text', 'arguments' => ['text' => 'x']]);
                $this->assertSame('Unknown tool: insert_text', $answer['error']['message'] ?? null);
                $this->assertSame("<?php\necho 'a';\n", $this->ok('read_buffer'), 'nothing was inserted');
            },
        ]);
    }
}
