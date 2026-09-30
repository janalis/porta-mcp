<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Porta\Lsp\Uri;
use Porta\Plugin\Api\PluginApi;

/**
 * What Claude Code shows as "In A.php" or "3 lines selected": the file in
 * front and the selection in it — or the caret, as an empty one — in the
 * shape the CLI reads from `selection_changed`: 0-based lines, columns in
 * UTF-16 units as the protocol counts them.
 *
 * {@see tick()} is called on a short timer: it tells a server's clients
 * when the answer moved, when a client has just said it is ready, and every
 * couple of seconds regardless, since the CLI forgets it now and then. No editor event says "the selection changed" — one would fire per
 * keystroke — so the question is asked instead, a few times a second, and
 * costs a comparison when nothing moved.
 */
final class SelectionFeed
{
    /**
     * Ticks between two tellings of an unchanged selection. The CLI forgets
     * what it was told whenever it rebuilds its view of the connection — at
     * connect, and after — so an unchanged selection is said again now and
     * then, as an editor whose caret keeps moving would say it anyway.
     */
    public const int HEARTBEAT = 8;

    /** @var array<string, mixed>|null what was last sent */
    private ?array $sent = null;

    private int $quiet = 0;

    /** @var array<string, mixed>|null the last selection there was, for getLatestSelection */
    private ?array $latest = null;

    public function __construct(
        private readonly PluginApi $api,
    ) {}

    /** Tell $server's clients, when the selection moved or a client is new to it. */
    public function tick(IdeServer $server): void
    {
        $clients = $server->clientCount();
        $current = $this->current();
        // Someone new has heard nothing yet; and silence past the heartbeat
        // may have been forgotten.
        $due = $server->takeNewcomer() || ++$this->quiet >= self::HEARTBEAT;
        if (null === $current || 0 === $clients || $current === $this->sent && !$due) {
            return;
        }
        $this->sent = $current;
        $this->quiet = 0;
        $server->broadcast('selection_changed', $current);
    }

    /**
     * The selection in the file in front, the caret as an empty one — null
     * with no text file in front.
     *
     * @return array{text: string, filePath: string, fileUrl: string, selection: array{start: array{line: int, character: int}, end: array{line: int, character: int}, isEmpty: bool}}|null
     */
    public function current(): ?array
    {
        $path = $this->api->activeFile();
        $text = null === $path ? null : $this->api->openBuffers()->text($path);
        if (null === $path || null === $text) {
            return null;
        }
        $lines = \explode("\n", $text);
        $selected = $this->api->openBuffers()->selection($path);
        $caret = $this->api->caretPosition() ?? ['line' => 0, 'column' => 0];
        [$line, $column, $endLine, $endColumn] = null === $selected
            ? [$caret['line'], $caret['column'], $caret['line'], $caret['column']]
            : [$selected['line'], $selected['column'], $selected['endLine'], $selected['endColumn']];
        // UTF-16 units, as the protocol counts: the bytes of the line up to
        // the column, re-encoded, halved.
        $unit = static fn (int $l, int $c): array => [
            'line' => $l,
            'character' => \intdiv(\strlen((string) \mb_convert_encoding(\substr($lines[$l] ?? '', offset: 0, length: $c), to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')), num2: 2),
        ];
        $this->latest = [
            'text' => $selected['text'] ?? '',
            'filePath' => $path,
            'fileUrl' => Uri::fromPath($path),
            'selection' => ['start' => $unit($line, $column), 'end' => $unit($endLine, $endColumn), 'isEmpty' => null === $selected],
        ];

        return $this->latest;
    }

    /** @return array<string, mixed>|null the last selection there was — current, or the last before the editor emptied */
    public function latest(): ?array
    {
        return $this->current() ?? $this->latest;
    }
}
