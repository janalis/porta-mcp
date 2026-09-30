<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Event\AgentActivity;
use Porta\Event\AgentEditing;
use Porta\Event\AgentLeft;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Porta\Ui\Glyphs;
use Porta\Workbench\AgentActivityKind;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;

/**
 * What the user sees of the agents: every tool that reads or changes a file
 * reports it here, and this tells the IDE ({@see AgentActivity}), which puts a
 * caret in the agent's colour with its name where it is at work and marks the
 * file's tab and tree row, until it goes quiet.
 *
 * It also keeps what each agent did last, for the status bar's cell.
 *
 * Behind two settings, read on every report: `showAgents` — nothing is shown
 * at all without it — and `showAgentCursor`, the caret and name in the editor.
 * Turning either off takes back what is on screen at once.
 */
final class Presence
{
    /** @var array<string, array{name: string, kind: AgentActivityKind, path: string}> caller id => what it did last */
    private array $last = [];

    /** @var array<string, array<string, true>> caller id => the files it has changed since it connected */
    private array $changed = [];

    /**
     * @param \Closure(): bool  $show   whether to show agents at all
     * @param \Closure(): bool  $cursor whether to show their caret and name in the editor
     * @param \Closure(): float $fade   seconds of quiet before an agent's marks go
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $show,
        private readonly \Closure $cursor,
        private readonly \Closure $fade,
    ) {}

    /** $caller is reading $path, at a 0-based line and byte column. */
    public function reading(ToolCaller $caller, string $path, int $line = 0, int $column = 0): void
    {
        $this->report($caller, $path, AgentActivityKind::Reading, $line, $column);
    }

    /** $caller is changing $path, at a 0-based line and byte column. */
    public function editing(ToolCaller $caller, string $path, int $line = 0, int $column = 0): void
    {
        $this->report($caller, $path, AgentActivityKind::Editing, $line, $column);
    }

    /**
     * $caller is about to change $path, which holds $text now: the first time
     * it does so since it connected, what the file held is handed on for
     * recovery ({@see AgentEditing}) — whatever the settings say about SHOWING
     * agents, since this is not showing.
     */
    public function before(ToolCaller $caller, string $path, string $text): void
    {
        if (\array_key_exists($path, $this->changed[$caller->id] ?? [])) {
            return;
        }
        $this->changed[$caller->id][$path] = true;
        $this->api->emit(new AgentEditing($path, $text));
    }

    /** $caller is gone: everything shown for it goes, and its next session keeps a fresh "before". */
    public function left(ToolCaller $caller): void
    {
        unset($this->last[$caller->id], $this->changed[$caller->id]);
        $this->api->emit(new AgentLeft($caller->id));
        $this->api->refreshStatusSegments();
    }

    /** A setting moved: what is on screen is taken back, and the next report shows it the new way. */
    public function reset(): void
    {
        foreach (\array_keys($this->last) as $agent) {
            $this->api->emit(new AgentLeft($agent));
        }
        $this->last = [];
        $this->api->refreshStatusSegments();
    }

    /** The status bar's cell: who is at work on what, or nothing while no agent is. */
    public function statusCell(): ?StatusCell
    {
        if ([] === $this->last) {
            return null;
        }
        $lines = \array_map($this->doing(...), \array_values($this->last));
        $text = 1 === \count($lines) ? $lines[0] : $this->api->trans('%count% agents', ['%count%' => \count($lines)]);

        return new StatusCell(Glyphs::AGENT.' '.$text, StatusTone::Accent, tooltip: \implode("\n", $lines));
    }

    private function report(ToolCaller $caller, string $path, AgentActivityKind $kind, int $line, int $column): void
    {
        if (!($this->show)()) {
            return;
        }
        $this->last[$caller->id] = ['name' => $caller->name, 'kind' => $kind, 'path' => $path];
        // A negative line is the file without a caret: marked on its tab and
        // row, nothing drawn in the text.
        $this->api->emit(new AgentActivity($caller->id, $caller->name, $path, $kind, ($this->cursor)() ? $line : -1, $column, ($this->fade)()));
        $this->api->refreshStatusSegments();
    }

    /** @param array{name: string, kind: AgentActivityKind, path: string} $agent */
    private function doing(array $agent): string
    {
        $file = \basename($agent['path']);

        return (
            AgentActivityKind::Editing === $agent['kind']
                ? $this->api->trans('%agent% editing %file%', ['%agent%' => $agent['name'], '%file%' => $file])
                : $this->api->trans('%agent% reading %file%', ['%agent%' => $agent['name'], '%file%' => $file])
        );
    }
}
