<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\DiagnosticSeverity;
use Porta\Event\ChangeProposed;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\McpPlugin\Ide\WebSocketFrames;
use Porta\McpPlugin\Tests\Support\TalksClaudeCode;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Porta as the IDE a Claude Code CLI connects to: announced in the CLI's
 * config directory, upgraded only with the token, telling the CLI which file
 * is open and what is selected, answering the CLI's own IDE tools.
 */
final class McpClaudeCodeE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksClaudeCode;
    use UsesBundledPlugins;

    private TempProject $config;

    private string|false $previous;

    #[\Override]
    protected function setUp(): void
    {
        $this->config = new TempProject();
        $this->previous = \getenv('CLAUDE_CONFIG_DIR');
        \putenv('CLAUDE_CONFIG_DIR='.$this->config->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        \putenv(false === $this->previous ? 'CLAUDE_CONFIG_DIR' : 'CLAUDE_CONFIG_DIR='.$this->previous);
    }

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n// a\necho 'a';\n",
            'src/B.php' => "<?php\n// second\n",
            '.porta.json' => '{"enabledPlugins":["mcp"]}',
        ]);
    }

    /** @param array<string, mixed> $message */
    private static function isSelection(array $message, string $file): bool
    {
        /** @var array{filePath?: string} $params */
        $params = $message['params'] ?? [];

        return 'selection_changed' === ($message['method'] ?? null) && \str_ends_with($params['filePath'] ?? '', $file);
    }

    public function testTheCliSeesTheOpenFileAndSelectionAndOpensFilesHere(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);
        $a = $root.'/src/A.php';

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($root, $a): void {
                $lock = $this->lock($this->config->dir);
                $this->assertSame([$root], $lock['folders']);
                $this->assertSame((string) $lock['port'], \getenv('CLAUDE_CODE_SSE_PORT'), 'a claude started in the terminal connects by itself');
                $this->assertSame('true', \getenv('ENABLE_IDE_INTEGRATION'));

                [, $refused] = $this->upgrade($lock['port'], self::upgradeRequest($lock['port'], 'wrong'));
                $this->assertStringStartsWith('HTTP/1.1 401', $refused, 'no token, no connection');

                $answer = $this->connectCli($this->config->dir);
                $this->assertStringStartsWith('HTTP/1.1 101', $answer);
                $this->assertStringContainsString("Sec-WebSocket-Protocol: mcp\r\n", $answer, 'the subprotocol it asked for');

                $this->cliNotify('ide_connected', ['pid' => 1]);
                $selection = $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/A.php'), 'the open file');
                $this->assertSame(
                    [
                        'text' => '',
                        'filePath' => $a,
                        'fileUrl' => 'file://'.$a,
                        'selection' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0], 'isEmpty' => true],
                    ],
                    $selection['params'],
                );
                $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/A.php'), 'the same, said again on the heartbeat');

                $this->assertSame(
                    [['success' => true, 'folders' => [['name' => \basename($root), 'uri' => 'file://'.$root, 'path' => $root]], 'rootPath' => $root], false],
                    $this->cliCall('getWorkspaceFolders'),
                );
                $this->assertSame([['tabs' => [['uri' => 'file://'.$a, 'isActive' => true, 'label' => 'A.php', 'languageId' => 'php', 'isDirty' => false]]], false], $this->cliCall('getOpenEditors'));
                $this->assertTrue($this->cliCall('getCurrentSelection')[0]['success']);
                $this->assertSame($a, $this->cliCall('getLatestSelection')[0]['filePath']);
                $this->assertSame([['success' => true, 'filePath' => $a, 'isDirty' => false, 'isUntitled' => false], false], $this->cliCall('checkDocumentDirty', ['filePath' => $a]));
                $this->assertFalse($this->cliCall('checkDocumentDirty', ['filePath' => $root.'/src/B.php'])[0]['success'], 'not open');
                $this->assertTrue($this->cliCall('saveDocument', ['filePath' => $a])[0]['success'], 'nothing to save is saved');
                $this->assertFalse($this->cliCall('saveDocument', ['filePath' => $root.'/src/B.php'])[0]['success']);
                $this->assertSame([[], false], $this->cliCall('getDiagnostics', ['uri' => 'file://'.$a]));
                Services::resolve($ide, BufferService::class)->applyDiagnostics(
                    Uri::fromPath($a),
                    [
                        2 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'Unused', 'col' => 5, 'endCol' => 8, 'source' => 'phpstan']],
                        0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0]],
                    ],
                    'test',
                );
                $this->assertSame(
                    [
                        [[
                            'uri' => 'file://'.$a,
                            'diagnostics' => [
                                [
                                    'message' => 'Unused',
                                    'severity' => 'Warning',
                                    'range' => ['start' => ['line' => 2, 'character' => 5], 'end' => ['line' => 2, 'character' => 5]],
                                    'source' => 'phpstan',
                                ],
                                ['message' => 'Boom', 'severity' => 'Error', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]]],
                            ],
                        ]],
                        false,
                    ],
                    $this->cliCall('getDiagnostics'),
                    'every open file, as VS Code reports them',
                );
                $this->assertSame(['CLOSED_0_DIFF_TABS', false], $this->cliCall('closeAllDiffTabs'));
                $this->assertSame(['filePath is required', true], $this->cliCall('checkDocumentDirty'));
                $this->assertSame(['File not found: '.$root.'/nope.php', true], $this->cliCall('openFile', ['filePath' => $root.'/nope.php']));

                // The CLI opens a file here, at the text it names; the selection follows.
                $this->assertSame(['Opened file: '.$root.'/src/B.php', false], $this->cliCall('openFile', ['filePath' => $root.'/src/B.php', 'startText' => 'second']));
                $moved = $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/B.php'), 'the selection in the file just opened');
                $this->assertSame(1, $moved['params']['selection']['start']['line']);
                $this->cliCall('openFile', ['filePath' => $a]);
                $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/A.php'), 'back to A');
            },
            200 => function (Tui $tui, $terminal, Ide $ide) use ($a): void {
                $tui->handleInput("\x1b[1;2B"); // shift+down: the first line selected
                $selected = $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/A.php') && false === $m['params']['selection']['isEmpty'], 'the selection');
                $this->assertSame("<?php\n", $selected['params']['text']);
                $this->assertSame(['line' => 1, 'character' => 0], $selected['params']['selection']['end']);

                // "Send selection to Claude Code": an @ mention of those lines.
                Services::resolve($ide, CommandRegistry::class)->run('mcp:mention_in_claude');
                $mention = $this->cliAwait(static fn (array $m): bool => 'at_mentioned' === ($m['method'] ?? null), 'the mention');
                $this->assertSame(['filePath' => $a, 'lineStart' => 0, 'lineEnd' => 1], $mention['params']);
                $tui->handleInput("\x1b[B"); // the selection cleared
                $this->cliAwait(static fn (array $m): bool => self::isSelection($m, 'src/A.php') && true === $m['params']['selection']['isEmpty'], 'no selection');
                Services::resolve($ide, CommandRegistry::class)->run('mcp:mention_in_claude');
                $this->assertSame(['filePath' => $a], $this->cliAwait(static fn (array $m): bool => 'at_mentioned' === ($m['method'] ?? null), 'a mention of the file')['params']);

                // The transport's own frames.
                $this->cliWrite(self::clientFrame('beat', WebSocketFrames::PING));
                $this->assertSame('beat', $this->cliAwaitFrame(WebSocketFrames::PONG, 'the pong'));
                $this->cliWrite(self::clientFrame('ignored', WebSocketFrames::PONG).self::clientFrame("\x03\xE8bye", WebSocketFrames::CLOSE));
                $this->assertSame("\x03\xE8", $this->cliAwaitFrame(WebSocketFrames::CLOSE, 'the close answered'));
                $this->await($this->cliClosed(...), what: 'the hang-up');

                Services::resolve($ide, CommandRegistry::class)->run('mcp:mention_in_claude');
                $this->assertContains('No Claude Code is connected — run /ide in claude, or start claude in the terminal', Toasts::all($ide));
            },
        ]);

        $this->assertSame([], \glob($this->config->dir.'/ide/*.lock'), 'the lock file goes with the IDE');
        $this->assertFalse(\getenv('CLAUDE_CODE_SSE_PORT'));
    }

    public function testAClientThatMisbehavesIsHungUpOnAndTheSettingTurnsItOffAndOn(): void
    {
        $project = self::project();

        $this->runIde($project, null, [
            100 => function (Tui $tui, $terminal, Ide $ide): void {
                $lock = $this->lock($this->config->dir);

                // A handshake in pieces, with a frame riding its last one.
                $request = self::upgradeRequest($lock['port'], $lock['token'], '');
                $stream = \stream_socket_client('tcp://127.0.0.1:'.$lock['port'], timeout: 1.0);
                $this->assertNotFalse($stream);
                \stream_set_blocking($stream, enable: false);
                $this->cli = $stream;
                $this->cliFrames = new WebSocketFrames();
                $this->cliWrite(\substr($request, offset: 0, length: 40));
                $this->cliWrite(\substr($request, offset: 40).self::clientFrame('{"jsonrpc":"2.0","id":7,"method":"ping"}'));
                $head = '';
                $this->await(static function () use ($stream, &$head): bool {
                    $head .= (string) \fread($stream, length: 8192);

                    return \str_contains($head, "\r\n\r\n");
                }, what: 'the split handshake answered');
                $this->assertStringStartsWith('HTTP/1.1 101', $head);
                $this->assertStringNotContainsString('Sec-WebSocket-Protocol', $head, 'not asked, not named');
                $this->cliInbox = $this->cliFrames->feed(\explode("\r\n\r\n", $head, limit: 2)[1]);
                $this->assertSame(7, $this->cliAwait(static fn (array $m): bool => 7 === ($m['id'] ?? null), 'the ping that rode the handshake')['id']);
                $this->assertSame([['success' => false, 'message' => 'No active editor found'], false], $this->cliCall('getCurrentSelection'), 'nothing open');
                $this->assertSame([['success' => false, 'message' => 'No active editor found'], false], $this->cliCall('getLatestSelection'));

                // A frame past the cap: not a message this server is for.
                $this->cliWrite("\x81\xFF".\pack('J', 20_000_000).'mask');
                $this->await($this->cliClosed(...), what: 'the oversized frame hung up on');

                // Headers that never end.
                [$endless] = $this->upgrade($lock['port'], \str_repeat('X', times: 20_000));
                $this->cli = $endless;
                $this->await($this->cliClosed(...), what: 'the endless handshake hung up on');

                // Turned off live: unannounced, unlistened; on again: a new lock.
                $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                $settings = Probe::get(Probe::get($lifecycle, 'plugins'), 'settings');
                $this->assertInstanceOf(PluginSettings::class, $settings);
                $settings->change('mcp', 'claudeCode', false);
                $lifecycle->onPluginSettingChanged('mcp', 'claudeCode', false);
                $this->assertSame([], \glob($this->config->dir.'/ide/*.lock'));
                $this->assertFalse(\getenv('CLAUDE_CODE_SSE_PORT'));
                $settings->change('mcp', 'claudeCode', true);
                $lifecycle->onPluginSettingChanged('mcp', 'claudeCode', true);
                $this->assertCount(1, (array) \glob($this->config->dir.'/ide/*.lock'));
            },
        ]);
    }

    public function testWhereItCannotBeAnnouncedTheUserIsTold(): void
    {
        \putenv('CLAUDE_CONFIG_DIR='.$this->config->write('not-a-dir', 'x'));
        $project = self::project();

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide): void {
                $this->assertContains('The Claude Code connection could not be announced: '.$this->config->dir.'/not-a-dir/ide could not be created', Toasts::all($ide));

                $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                $settings = Probe::get(Probe::get($lifecycle, 'plugins'), 'settings');
                $this->assertInstanceOf(PluginSettings::class, $settings);
                $settings->change('mcp', 'claudeCode', false);
                $lifecycle->onPluginSettingChanged('mcp', 'claudeCode', false);
                $settings->change('mcp', 'claudeCode', true);
                $lifecycle->onPluginSettingChanged('mcp', 'claudeCode', true);
                $this->assertCount(2, \array_filter(Toasts::all($ide), static fn (string $toast): bool => \str_contains($toast, 'could not be announced')), 'turned on again, told again');
            },
        ]);
    }

    /**
     * Send an openDiff and wait until Porta asks the user about it; the
     * request's id, to read the verdict with.
     */
    private function propose(Tui $tui, VirtualTerminal $terminal, string $path, string $text, string $tab): int
    {
        $id = ++$this->cliId;
        $terminal->clearOutput();
        $this->cliWrite(self::clientFrame(\json_encode(
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'openDiff', 'arguments' => ['old_file_path' => $path, 'new_file_path' => $path, 'new_file_contents' => $text, 'tab_name' => $tab]],
            ],
            \JSON_THROW_ON_ERROR,
        )));
        $this->awaitPainted($tui, $terminal, 'Claude proposes changes to '.\basename($path));

        return $id;
    }

    /** @return list<string> the verdict's text blocks */
    private function verdict(int $id): array
    {
        /** @var array{result: array{content: list<array{text: string}>}} $answer */
        $answer = $this->cliAwait(static fn (array $m): bool => $id === ($m['id'] ?? null), 'the verdict');

        return \array_column($answer['result']['content'], 'text');
    }

    public function testAProposedChangeIsShownInTheFileAndWaitsForTheUsersVerdict(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// one\n// two\n// three\n",
            'src/B.php' => "<?php\n",
            '.porta.json' => '{"enabledPlugins":["mcp"]}',
        ]);
        $root = (string) \realpath($project->dir);
        $a = $root.'/src/A.php';

        $this->runIde($project, $project->path('src/B.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root, $a): void {
                $this->connectCli($this->config->dir);
                $buffers = Services::resolve($ide, BufferManager::class);
                $offered = [];
                Services::resolve($ide, EventDispatcherInterface::class)->addListener(ChangeProposed::class, static function (ChangeProposed $proposal) use (&$offered): void {
                    $offered[] = [$proposal->path, $proposal->proposedPath, (string) \file_get_contents($proposal->proposedPath)];
                });
                $this->assertSame(['new_file_path is required', true], $this->cliCall('openDiff'));
                $this->assertSame(['new_file_contents is required', true], $this->cliCall('openDiff', ['new_file_path' => $a]));
                $this->assertTrue(
                    $this->cliCall('openDiff', ['new_file_path' => $a.'/inside/a/file.php', 'new_file_contents' => 'x', 'tab_name' => 'x'])[1],
                    'a file that cannot be made is the caller\'s error',
                );
                $this->assertSame(
                    ['src already exists', true],
                    $this->cliCall('openDiff', ['new_file_path' => $root.'/src', 'new_file_contents' => 'x', 'tab_name' => 'x']),
                    'a folder is not a file to propose',
                );

                // The same text proposed: shown, nothing to change, still the user's call.
                $id = $this->propose($tui, $terminal, $root.'/src/B.php', "<?php\n", 'B.php (same)');
                $this->assertFalse($buffers->get($root.'/src/B.php')?->isDirty());
                $this->awaitPainted($tui, $terminal, 'No difference: the proposal is the text as it is');
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\r");
                $this->assertSame(['DIFF_REJECTED', 'B.php (same)'], $this->verdict($id));

                // Accepted, after the user touched it up: the file is saved as it stands.
                $id = $this->propose($tui, $terminal, $a, "<?php\n// one\n// TWO\n// three\n", 'A.php (edit)');
                $this->assertSame($a, $buffers->active()?->path, 'brought forward to be read');
                $this->assertSame("<?php\n// one\n// TWO\n// three\n", $buffers->get($a)?->document->getText());
                $this->assertSame(2, $buffers->get($a)?->document->cursorLine, 'the caret on the change');
                $this->assertSame(
                    [2 => [['col' => 0, 'label' => Glyphs::AGENT.' Claude Code', 'slot' => 0]]],
                    Services::resolve($ide, CodeEditorWidget::class)->decorations->remoteCaretsIn($a),
                    'Claude Code shows at its change, as any agent does',
                );
                $this->assertSame([$root.'/src/B.php', $a], \array_column($offered, 0), 'each proposal offered side by side: the file as it is…');
                $this->assertSame(['A.php', "<?php\n// one\n// TWO\n// three\n"], [\basename($offered[1][1]), $offered[1][2]], '…and a copy of the proposal, under its name');
                $this->awaitPainted($tui, $terminal, '- // two');
                $this->awaitPainted($tui, $terminal, '+ // TWO');
                $this->assertStringContainsString('// one', \implode("\n", $this->screenGrid($tui, $terminal)), 'with the line before it');
                $buffers->get($a)?->document->insert('!');
                $tui->handleInput("\r"); // Accept — the first choice
                $this->assertSame(['FILE_SAVED', "<?php\n// one\n!// TWO\n// three\n"], $this->verdict($id));
                $this->assertSame("<?php\n// one\n!// TWO\n// three\n", \file_get_contents($a), 'saved');
                $this->assertFileDoesNotExist($offered[1][1], 'the copy goes with the verdict');
                $this->assertDirectoryDoesNotExist(\dirname($offered[1][1]));

                // Rejected: the old text back.
                $id = $this->propose($tui, $terminal, $a, "<?php\n", 'A.php (cut)');
                $this->assertSame("<?php\n", $buffers->get($a)?->document->getText(), 'lines at the end go, newline and all');
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\r"); // Reject
                $this->assertSame(['DIFF_REJECTED', 'A.php (cut)'], $this->verdict($id));
                $this->assertSame("<?php\n// one\n!// TWO\n// three\n", $buffers->get($a)?->document->getText());
                $this->assertFalse($buffers->get($a)?->isDirty(), 'back to what is on disk');

                // A new file: proposed, turned down, gone again.
                $c = $root.'/src/New/C.php';
                $id = $this->propose($tui, $terminal, $c, "<?php\n// new\n", 'C.php');
                $this->assertFileExists($c);
                $tui->handleInput("\x1b");
                $this->assertTrue(Services::resolve($ide, CommandRegistry::class)->has('mcp:review_claude_change'));
                $terminal->clearOutput();
                Services::resolve($ide, CommandRegistry::class)->run('mcp:review_claude_change');
                $this->awaitPainted($tui, $terminal, 'Claude proposes changes to C.php');
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\r");
                $this->assertSame(['DIFF_REJECTED', 'C.php'], $this->verdict($id));
                $this->assertFileDoesNotExist($c);

                // A second proposal under the same tab replaces the first.
                $first = $this->propose($tui, $terminal, $a, "<?php\n// first\n", 'A.php');
                $tui->handleInput("\x1b");
                $second = $this->propose($tui, $terminal, $a, "<?php\n// second\n", 'A.php');
                $this->assertSame(['DIFF_REJECTED', 'A.php'], $this->verdict($first));
                $this->assertSame("<?php\n// second\n", $buffers->get($a)?->document->getText(), 'shown from the file as it was');
                $this->assertSame(['CLOSED_1_DIFF_TABS', false], $this->cliCall('closeAllDiffTabs'));
                $this->assertSame(['DIFF_REJECTED', 'A.php'], $this->verdict($second));
                $saved = \file_get_contents($a);
                $tui->handleInput("\r"); // Accept, on a question whose proposal is gone
                $this->assertSame($saved, \file_get_contents($a), 'a stale answer changes nothing');
                $this->assertSame(['TAB_CLOSED', false], $this->cliCall('close_tab', ['tab_name' => 'A.php']));
                Services::resolve($ide, CommandRegistry::class)->run('mcp:review_claude_change');
                $this->assertContains('No change from Claude is waiting', Toasts::all($ide));

                // Unsaved edits here are not what the CLI proposed against.
                $buffers->get($a)?->document->insert('x');
                $this->cliWrite(self::clientFrame(\json_encode(
                    [
                        'jsonrpc' => '2.0',
                        'id' => 99,
                        'method' => 'tools/call',
                        'params' => ['name' => 'openDiff', 'arguments' => ['old_file_path' => $a, 'new_file_path' => $a, 'new_file_contents' => 'x', 'tab_name' => 'dirty']],
                    ],
                    \JSON_THROW_ON_ERROR,
                )));
                $this->assertSame(['DIFF_REJECTED', 'dirty'], $this->verdict(99));
                $this->assertContains('Claude proposed a change to A.php, which has unsaved edits here — save or discard them, then ask again', Toasts::all($ide));

                // Side by side turned off: the file and the dock alone.
                $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                $settings = Probe::get(Probe::get($lifecycle, 'plugins'), 'settings');
                $this->assertInstanceOf(PluginSettings::class, $settings);
                $settings->change('mcp', 'claudeSideBySide', false);
                $before = \count($offered);
                $id = $this->propose($tui, $terminal, $root.'/src/B.php', "<?php\n// quiet\n", 'B.php (quiet)');
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\r");
                $this->assertSame(['DIFF_REJECTED', 'B.php (quiet)'], $this->verdict($id));
                $this->assertCount($before, $offered, 'nothing offered side by side');

                // The client going takes its pending proposal with it.
                $buffers->get($a)?->document->undo();
                $this->propose($tui, $terminal, $root.'/src/B.php', "<?php\n// gone\n", 'B.php');
                $this->assertIsResource($this->cli);
                \fclose($this->cli);
                $this->await(static fn (): bool => "<?php\n" === $buffers->get($root.'/src/B.php')?->document->getText(), what: 'the proposal withdrawn');
                $editor = Services::resolve($ide, CodeEditorWidget::class);
                $this->await(static fn (): bool => [] === $editor->decorations->remoteCaretsIn($a), what: 'its marks gone with it');
            },
        ]);
    }
}
