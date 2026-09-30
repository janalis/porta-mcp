<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Porta\Event\ChangeProposed;
use Porta\McpPlugin\Server\TextBlocks;
use Porta\McpPlugin\Tools\Presence;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * Claude Code's `openDiff`: the CLI proposes a file's new text and WAITS for
 * the user to take it or leave it.
 *
 * Porta shows the proposal where the user reads code: in the file itself,
 * brought forward, the changed lines replaced as one edit, the caret on the
 * first of them — and asks. Accept saves the file as it now stands, with
 * whatever the user touched up first, and answers `FILE_SAVED` and that text;
 * reject puts the old text back and answers `DIFF_REJECTED`. The answers are
 * the ones the CLI reads from VS Code, block by block.
 *
 * A file with unsaved edits here is not the text the CLI proposed against,
 * so its proposal is turned down at once rather than laid over the user's
 * work. The question can be dismissed; {@see review()} asks it again. A
 * new proposal under a pending one's tab, the client going, and
 * `closeAllDiffTabs` each reject what is pending.
 *
 * The proposal is an agent's edit like any other: {@see Presence} keeps the
 * file as it was for local history and shows the agent at the change.
 */
final class DiffReview
{
    /** The dock list the change is spelled out in: what goes, what comes, around it. */
    public const string LIST = 'claude-proposal';

    /** Unchanged lines shown before and after the change, for where it is. */
    private const int CONTEXT = 2;

    /** @var array<string, array{path: string, original: string, created: bool, copy: string|null, suspension: Suspension<TextBlocks>}> tab name => the proposal waiting for the user */
    private array $pending = [];

    /** @param \Closure(): bool $sideBySide whether to offer the proposal to a side-by-side view too, read per proposal */
    public function __construct(
        private readonly PluginApi $api,
        private readonly Presence $presence,
        private readonly \Closure $sideBySide,
    ) {}

    /**
     * Show the proposal and wait for the user's verdict — the tool call
     * suspends its fiber until then.
     *
     * @param array<string, mixed> $arguments
     */
    public function open(array $arguments, ToolCaller $caller): TextBlocks
    {
        $path = self::string($arguments, 'new_file_path') ?? self::string($arguments, 'old_file_path') ?? throw new \InvalidArgumentException('new_file_path is required');
        $proposed = self::string($arguments, 'new_file_contents') ?? throw new \InvalidArgumentException('new_file_contents is required');
        $tab = self::string($arguments, 'tab_name') ?? $path;
        if (\array_key_exists($tab, $this->pending)) {
            $this->decide($tab, accept: false); // replaced by this one
        }
        $created = !\is_file($path);
        $buffers = $this->api->openBuffers();
        if ($created) {
            $why = $buffers->create($path);
            if (null !== $why) {
                throw new \RuntimeException($why);
            }
        }
        $path = (string) \realpath($path);
        if (\in_array(['path' => $path, 'dirty' => true], $buffers->files(), strict: true)) {
            $this->api->notify(
                $this->api->trans('Claude proposed a change to %file%, which has unsaved edits here — save or discard them, then ask again', ['%file%' => \basename($path)]),
                ToastSeverity::Warn,
                6.0,
            );

            return new TextBlocks(['DIFF_REJECTED', $tab]);
        }
        $this->api->openFile($path);
        $original = (string) $buffers->text($path);
        // Seen as any agent's edit is: the file as it was, kept for recovery;
        // the agent's caret and mark where it changes the text.
        $this->presence->before($caller, $path, $original);
        $first = $this->replace($path, $original, $proposed);
        $this->spell($path, $tab, $original, $proposed);
        $this->api->jumpTo($first);
        $this->presence->editing($caller, $path, $first);
        /** @var Suspension<TextBlocks> $suspension */
        $suspension = EventLoop::getSuspension();
        $this->pending[$tab] = ['path' => $path, 'original' => $original, 'created' => $created, 'copy' => $this->offer($path, $proposed), 'suspension' => $suspension];
        $this->ask($tab);

        return $suspension->suspend();
    }

    /** Ask again about the oldest proposal still waiting; false when none is. */
    public function review(): bool
    {
        $tab = \array_key_first($this->pending);
        if (null === $tab) {
            return false;
        }
        $this->api->openFile($this->pending[$tab]['path']);
        $this->ask($tab);

        return true;
    }

    /** Turn every waiting proposal down — the client went, or asked for its tabs closed; how many there were. */
    public function rejectAll(): int
    {
        $count = \count($this->pending);
        foreach (\array_keys($this->pending) as $tab) {
            $this->decide($tab, accept: false);
        }

        return $count;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the verdict IS the one fact: keep or put back
    public function decide(string $tab, bool $accept): void
    {
        $proposal = $this->pending[$tab] ?? null;
        if (null === $proposal) {
            return; // decided already, or never asked
        }
        unset($this->pending[$tab]);
        $this->api->updateList(self::LIST, []);
        if (null !== $proposal['copy']) {
            \unlink($proposal['copy']);
            \rmdir(\dirname($proposal['copy']));
        }
        $buffers = $this->api->openBuffers();
        $path = $proposal['path'];
        if ($accept) {
            $buffers->save($path);
            $proposal['suspension']->resume(new TextBlocks(['FILE_SAVED', (string) $buffers->text($path)]));

            return;
        }
        $this->replace($path, (string) $buffers->text($path), $proposal['original']);
        if ($proposal['created']) {
            $buffers->delete($path);
        }
        $proposal['suspension']->resume(new TextBlocks(['DIFF_REJECTED', $tab]));
    }

    private function ask(string $tab): void
    {
        $file = \basename($this->pending[$tab]['path']);
        $this->api->pick(
            $this->api->trans('Claude proposes changes to %file%', ['%file%' => $file]),
            [
                'accept' => $this->api->trans('Accept — keep and save'),
                'reject' => $this->api->trans('Reject — put the old text back'),
            ],
            fn (string $choice) => $this->decide($tab, 'accept' === $choice),
        );
    }

    /**
     * Hand the proposal to whatever shows two texts side by side
     * ({@see ChangeProposed}): a copy under the file's own name, in a folder
     * of its own, so the comparison is titled with it. The copy, or null
     * when side by side is off.
     */
    private function offer(string $path, string $proposed): ?string
    {
        if (!($this->sideBySide)()) {
            return null;
        }
        $folder = \sys_get_temp_dir().'/porta-proposal-'.\bin2hex(\random_bytes(6));
        \mkdir($folder, permissions: 0o700);
        $copy = $folder.'/'.\basename($path);
        \file_put_contents($copy, $proposed);
        $this->api->emit(new ChangeProposed($path, $copy));

        return $copy;
    }

    /**
     * The change as a unified hunk in the dock — the lines it takes out,
     * which the file in front no longer shows, then the ones it puts in, with
     * a little of what is around — each row a way to the line it is about.
     */
    private function spell(string $path, string $tab, string $from, string $to): void
    {
        $change = LineChange::between($from, $to);
        $rows = [['path' => '', 'line' => 0, 'text' => $tab]];
        if (null === $change) {
            $rows[] = ['path' => '', 'line' => 0, 'text' => $this->api->trans('No difference: the proposal is the text as it is')];
            $this->api->showList(self::LIST, $rows);

            return;
        }
        $old = \explode("\n", $from);
        $new = \explode("\n", $to);
        $head = $change->first;
        $row = static fn (int $line, string $mark, string $text): array => ['path' => $path, 'line' => $line, 'text' => $mark.' '.$text];
        for ($line = \max(0, $head - self::CONTEXT); $line < $head; ++$line) {
            $rows[] = $row($line, mark: ' ', text: $old[$line]);
        }
        foreach ($change->removed as $text) {
            $rows[] = $row($head, mark: '-', text: $text);
        }
        foreach ($change->added as $offset => $text) {
            $rows[] = $row($head + $offset, mark: '+', text: $text);
        }
        $after = $head + \count($change->added);
        for ($line = $after; $line < \min(\count($new), $after + self::CONTEXT); ++$line) {
            $rows[] = $row($line, mark: ' ', text: $new[$line]);
        }
        $this->api->showList(self::LIST, $rows);
    }

    /** Make $path's text $to as ONE edit over the lines that differ; the first of them (0-based). */
    private function replace(string $path, string $from, string $to): int
    {
        $change = LineChange::between($from, $to);
        if (null === $change) {
            return 0;
        }
        $this->api->openBuffers()->edit($path, $change->start[0], $change->start[1], $change->end[0], $change->end[1], $change->text);

        return $change->first;
    }

    /** @param array<string, mixed> $arguments */
    private static function string(array $arguments, string $key): ?string
    {
        return \is_string($arguments[$key] ?? null) ? $arguments[$key] : null;
    }
}
