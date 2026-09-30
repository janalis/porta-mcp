<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\McpPlugin\Tests\Support\TalksMcp;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * An agent at work, as the user sees it: a caret in its colour with its name
 * at the end of the line it reads or edits, a star on the file's tab, who is
 * doing what in the status bar — gone after the fade, when it leaves, or when
 * the user turns it off.
 */
final class McpPresenceE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksMcp;
    use UsesBundledPlugins;

    private const string LABEL = Glyphs::AGENT.' test';

    /** @param array<string, mixed> $mcp */
    private static function project(array $mcp = []): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n// first\necho 'a';\n",
            'src/B.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['mcp'], 'plugins' => ['mcp' => $mcp]], \JSON_THROW_ON_ERROR),
        ]);
    }

    private function screen(Tui $tui, VirtualTerminal $terminal): string
    {
        $this->frame($tui);

        return \implode("\n", $this->screenGrid($tui, $terminal));
    }

    /** The screen row that shows $text, or '' when none does. */
    private function rowWith(Tui $tui, VirtualTerminal $terminal, string $text): string
    {
        return \array_find(\explode("\n", $this->screen($tui, $terminal)), static fn (string $row): bool => \str_contains($row, $text)) ?? '';
    }

    public function testAnAgentShowsWhereItEditsAndReadsThenFades(): void
    {
        $project = self::project(['agentFade' => 2]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                $this->connect($project);

                $this->ok('edit_buffer', ['oldText' => '// first', 'newText' => '// edited']);
                $this->await(fn (): bool => \str_contains($this->rowWith($tui, $terminal, '// edited'), self::LABEL), what: 'the name at the edited line');
                $this->assertStringContainsString(Glyphs::AGENT.' test editing A.php', $this->screen($tui, $terminal), 'the status bar says who does what');
                $this->assertStringContainsString('A.php '.Glyphs::AGENT, $this->screen($tui, $terminal), 'the tab carries the star');

                $this->ok('read_buffer', ['startLine' => 3]);
                $this->await(fn (): bool => \str_contains($this->rowWith($tui, $terminal, "echo 'a'"), self::LABEL), what: 'the name moved to the line read');
                $this->assertStringNotContainsString(self::LABEL, $this->rowWith($tui, $terminal, '// edited'), 'one caret per agent and file');
                $this->assertStringContainsString('test reading A.php', $this->screen($tui, $terminal));

                $this->await(fn (): bool => !\str_contains($this->rowWith($tui, $terminal, "echo 'a'"), self::LABEL), timeout: 8.0, what: 'the fade after two quiet seconds');
            },
        ]);
    }

    public function testLeavingOrTurningItOffTakesItBack(): void
    {
        $project = self::project(['showAgentCursor' => false]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                $this->connect($project);
                $this->ok('edit_buffer', ['oldText' => '// first', 'newText' => '// edited']);
                $this->ok('edit_buffer', ['path' => 'src/B.php', 'oldText' => '<?php', 'newText' => '<?php // b']);
                $this->await(fn (): bool => \str_contains($this->screen($tui, $terminal), 'B.php '.Glyphs::AGENT), what: 'the background tab starred');
                $this->assertStringContainsString('A.php '.Glyphs::AGENT, $this->screen($tui, $terminal), 'and the one in front');
                $this->assertStringNotContainsString(self::LABEL, $this->rowWith($tui, $terminal, '// edited'), 'no cursor with showAgentCursor off');

                // Turned off live: what is on screen goes at once.
                $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                $settings = Probe::get(Probe::get($lifecycle, 'plugins'), 'settings');
                $this->assertInstanceOf(PluginSettings::class, $settings);
                $settings->change('mcp', 'showAgents', false);
                $lifecycle->onPluginSettingChanged('mcp', 'showAgents', false);
                $this->await(fn (): bool => !\str_contains($this->screen($tui, $terminal), Glyphs::AGENT), what: 'the marks gone with the setting');
                $settings->change('mcp', 'showAgents', true);
                $lifecycle->onPluginSettingChanged('mcp', 'showAgents', true);
                $this->ok('read_buffer');
                $this->await(fn (): bool => \str_contains($this->screen($tui, $terminal), 'A.php '.Glyphs::AGENT), what: 'back with the next report');

                // The status cell's menu: go where an agent is, or stop seeing them.
                $cell = \array_find(
                    Probe::get($lifecycle, 'plugins')->statusSegments(),
                    static fn (mixed $segment): bool => $segment instanceof StatusCell && \str_contains($segment->text, Glyphs::AGENT),
                );
                $this->assertInstanceOf(StatusCell::class, $cell);
                $this->assertNotNull($cell->menu);
                $items = ($cell->menu)();
                $this->assertSame(['Go to A.php (test)', '', 'Stop showing agents'], \array_map(static fn (MenuItem $item): string => $item->label, $items));
                $this->ok('edit_buffer', ['path' => 'src/B.php', 'oldText' => '// b', 'newText' => '// b again']);
                $items = ($cell->menu)();
                $this->assertNotNull($items[0]->run);
                ($items[0]->run)();
                $this->assertSame((string) \realpath($project->path('src/B.php')), $this->json('context')['activeFile'], 'gone to the file the agent is on');
                $this->assertNotNull($items[2]->run);
                ($items[2]->run)();
                $this->await(fn (): bool => !\str_contains($this->screen($tui, $terminal), Glyphs::AGENT), what: 'the marks gone from the menu');
                $settings->change('mcp', 'showAgents', true);

                $this->assertIsResource($this->client);
                \fclose($this->client);
                $this->client = null;
                $this->await(fn (): bool => !\str_contains($this->screen($tui, $terminal), Glyphs::AGENT), what: 'everything gone with the client');
            },
        ]);

        $off = self::project(['showAgents' => false]);
        $this->runIde($off, $off->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($off): void {
                $this->connect($off);
                $this->ok('edit_buffer', ['oldText' => '// first', 'newText' => '// edited']);
                $this->await(fn (): bool => \str_contains($this->screen($tui, $terminal), '// edited'), what: 'the edit on screen');
                $this->assertStringNotContainsString(Glyphs::AGENT, $this->screen($tui, $terminal), 'no star, no name, no status with showAgents off');
            },
        ]);
    }
}
