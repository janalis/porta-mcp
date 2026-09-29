<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\DiagnosticSeverity;
use Porta\Ide;
use Porta\Lsp\Uri;
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
    use UsesBundledPlugins;

    /** @var resource|null */
    private $client;

    private int $nextId = 0;

    private string $received = '';

    private static function project(string $porta = '{"enabledPlugins":["mcp"]}'): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\necho 'a';\n",
            'src/B.php' => "<?php\necho 'b';\n",
            'img.png' => "\x89PNG",
            '.porta.json' => $porta,
        ]);
    }

    private function connect(TempProject $project): void
    {
        $client = \stream_socket_client('unix://'.$project->path('.porta/mcp.sock'), timeout: 1.0);
        $this->assertNotFalse($client, 'the plugin listens once the IDE is up');
        \stream_set_blocking($client, enable: false);
        $this->client = $client;
        $this->request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        \fwrite($client, '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n");
    }

    /**
     * Send one request and pump the loop until its answer arrives.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        $this->assertIsResource($this->client);
        $id = ++$this->nextId;
        \fwrite($this->client, \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR)."\n");

        return $this->awaitMessage(static fn (array $message): bool => $id === ($message['id'] ?? null), $method.' #'.$id);
    }

    /**
     * @param \Closure(array<string, mixed>): bool $matches
     *
     * @return array<string, mixed>
     */
    private function awaitMessage(\Closure $matches, string $what): array
    {
        $client = $this->client;
        $found = null;
        $this->await(function () use ($client, $matches, &$found): bool {
            $chunk = \fread($client, length: 65_536);
            $this->received .= false === $chunk ? '' : $chunk;
            while (false !== ($end = \strpos($this->received, needle: "\n"))) {
                $line = \substr($this->received, offset: 0, length: $end);
                $this->received = \substr($this->received, $end + 1);
                $message = \json_decode($line, associative: true);
                if (\is_array($message) && $matches($message)) {
                    $found = $message;

                    return true;
                }
            }

            return false;
        }, what: $what);

        /** @var array<string, mixed> $found */
        return $found;
    }

    /**
     * Call a tool; its text, and whether it was an error.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: bool}
     */
    private function call(string $tool, array $arguments = []): array
    {
        $answer = $this->request('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $this->assertArrayHasKey('result', $answer, $tool.' answered with a result, not a protocol error');
        /** @var array{content: list<array{text: string}>, isError: bool} $result */
        $result = $answer['result'];

        return [$result['content'][0]['text'], $result['isError']];
    }

    /** @param array<string, mixed> $arguments */
    private function ok(string $tool, array $arguments = []): string
    {
        [$text, $error] = $this->call($tool, $arguments);
        $this->assertFalse($error, $tool.' failed: '.$text);

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function json(string $tool, array $arguments = []): array
    {
        $decoded = \json_decode($this->ok($tool, $arguments), associative: true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $arguments */
    private function refused(string $tool, array $arguments, string $says): void
    {
        [$text, $error] = $this->call($tool, $arguments);
        $this->assertTrue($error, $tool.' should have refused, and answered: '.$text);
        $this->assertStringContainsString($says, $text);
    }

    public function testAnAgentReadsNavigatesAndEditsTheIde(): void
    {
        $project = self::project();
        $a = \realpath($project->path('src/A.php'));
        $b = \realpath($project->path('src/B.php'));

        $this->runIde($project, null, [
            100 => function (Tui $tui, $terminal, Ide $ide) use ($project, $a, $b): void {
                $this->connect($project);
                $names = \array_column($this->request('tools/list')['result']['tools'], 'name');
                $this->assertSame(['context', 'read_buffer', 'open_file', 'diagnostics', 'notify', 'replace_buffer', 'insert_text', 'definition', 'references', 'hover', 'symbols'], $names);

                // Nothing open yet.
                $context = $this->json('context');
                $this->assertSame(\realpath($project->dir), \realpath((string) $context['projectRoot']));
                $this->assertNull($context['activeFile']);
                $this->assertNull($context['caret']);
                $this->assertSame([], $context['openFiles']);
                $this->refused('read_buffer', [], 'No file is open');
                $this->refused('insert_text', ['text' => 'x'], 'No text file is active');
                $this->refused('replace_buffer', ['text' => 'x'], 'No text file to replace');

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
                $this->assertSame($b, $this->json('context')['activeFile'], 'replacing another file brought it forward');
                $this->assertSame("<?php\n// b\n", $this->ok('read_buffer', ['path' => $b]));
                $this->ok('replace_buffer', ['path' => 'src/B.php', 'text' => "<?php\n// b2\n"]);
                $this->assertSame("<?php\n// b2\n", $this->ok('read_buffer'), 'the active file by path');
                $this->ok('replace_buffer', ['path' => 'src/C.php', 'text' => "<?php\n// c\n"]);
                $this->assertSame("<?php\n// c\n", $this->ok('read_buffer', ['path' => 'src/C.php']), 'a closed file is opened first');
                $this->refused('replace_buffer', ['path' => 'src/D.php', 'text' => 'x'], 'is not a file');
                $this->refused('replace_buffer', ['path' => 'img.png', 'text' => 'x'], 'the active tab is not one');
                $this->refused('replace_buffer', [], 'text is required');

                $this->ok('open_file', ['path' => 'src/A.php', 'line' => 1, 'column' => 1]);
                $this->assertStringContainsString('unsaved', $this->ok('insert_text', ['text' => '// top'."\n"]));
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
                $buffers->applyDiagnostics(Uri::fromPath($b), [0 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'Unused', 'col' => 0, 'endCol' => 1]]], 'lsp');

                $this->assertSame(
                    [
                        ['path' => $a, 'line' => 3, 'column' => 6, 'severity' => 'error', 'message' => 'Undefined', 'source' => 'phpstan', 'code' => '42'],
                        ['path' => $b, 'line' => 1, 'column' => 1, 'severity' => 'hint', 'message' => 'Unused'],
                    ],
                    $this->json('diagnostics'),
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

    public function testAnAgentAsksTheLanguageServerAboutAnyFile(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nclass A {}\n",
            'src/B.php' => "<?php\nnew A();\n",
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

    public function testOtherPluginsToolsAreServedAndALiveChangeIsAnnounced(): void
    {
        $this->skipWithoutGit();
        $project = self::project('{"enabledPlugins":["mcp","git"]}');
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
                $this->assertContains('read_buffer', $names);

                $answer = $this->request('tools/call', ['name' => 'insert_text', 'arguments' => ['text' => 'x']]);
                $this->assertSame('Unknown tool: insert_text', $answer['error']['message'] ?? null);
                $this->assertSame("<?php\necho 'a';\n", $this->ok('read_buffer'), 'nothing was inserted');
            },
        ]);
    }
}
