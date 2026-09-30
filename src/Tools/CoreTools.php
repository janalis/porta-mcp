<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Porta\Plugin\Api\ToolEffect;

/**
 * The IDE itself as tools: what is open and where the caret is, the buffers'
 * text as the user sees it, what the language servers found, and the verbs to
 * show a file or change a buffer.
 *
 * Registered through {@see PluginApi::registerTool()} like any other plugin's,
 * so the server has one kind of tool, not two.
 *
 * The wire is 1-based — lines and columns as every editor, compiler and grep
 * prints them, which is what a model reading them expects — and the API is
 * 0-based, so every crossing converts here and nowhere else.
 *
 * Arguments are read through {@see Arguments}, which keeps every path inside
 * the project.
 */
final readonly class CoreTools
{
    /** The tools that change a buffer — what the `allowEdits` setting hides. */
    public const array EDITS = ['edit_buffer', 'replace_buffer', 'insert_text'];

    /** The tool that writes to disk — what the `allowSave` setting hides. */
    public const string SAVE = 'save_buffer';

    private const array NO_ARGUMENTS = ['type' => 'object', 'properties' => []];

    private const array SEVERITIES = [1 => 'error', 2 => 'warning', 3 => 'information', 4 => 'hint'];

    public function __construct(
        private PluginApi $api,
        private Opener $opener,
        private Presence $presence,
    ) {}

    public function register(): void
    {
        $path = static fn (string $what): array => ['type' => 'string', 'description' => $what.', project-relative or absolute'];
        $this->api->registerTool(
            'context',
            'What the user has in front of them: the project root, the active file and caret, the files selected in the tree, and every open tab with whether it has unsaved edits.',
            self::NO_ARGUMENTS,
            $this->context(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'selection',
            'What the user has selected in the active file: the text, and where it starts and ends (1-based line and column) — or that nothing is.',
            self::NO_ARGUMENTS,
            $this->selection(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'read_buffer',
            'The text of a file OPEN in the editor, unsaved edits included — the disk copy may be older. Without a path, the active file; startLine and endLine (1-based, inclusive) read part of it.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => $path('An open file'),
                    'startLine' => ['type' => 'integer', 'minimum' => 1],
                    'endLine' => ['type' => 'integer', 'minimum' => 1],
                    'lineNumbers' => ['type' => 'boolean', 'description' => 'Prefix each line with its number and a tab'],
                ],
            ],
            $this->readBuffer(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'open_file',
            'Open a file in the editor and bring it to the front, optionally at a line and column (1-based).',
            [
                'type' => 'object',
                'properties' => [
                    'path' => $path('The file'),
                    'line' => ['type' => 'integer', 'minimum' => 1],
                    'column' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['path'],
            ],
            $this->openFile(...),
            effect: ToolEffect::Edits,
        );
        $this->api->registerTool(
            'diagnostics',
            'Errors and warnings the language servers and analysers reported for the open files — or one of them — with 1-based positions. Files that are not open have none here.',
            ['type' => 'object', 'properties' => ['path' => $path('An open file')]],
            $this->diagnostics(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'notify',
            'Show the user a short message in the IDE.',
            [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'severity' => ['type' => 'string', 'enum' => ['info', 'success', 'warn', 'error']],
                ],
                'required' => ['message'],
            ],
            $this->notify(...),
            effect: ToolEffect::Edits,
        );
        $this->api->registerTool(
            'edit_buffer',
            'Replace one exact piece of a file\'s text in the editor (opening it behind the user\'s tab if needed) as ONE undoable edit: oldText must occur exactly once in the text as read_buffer shows it — include enough of the lines around it. Not saved to disk: the user reviews it and saves.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => $path('The file; the active one when omitted'),
                    'oldText' => ['type' => 'string', 'description' => 'The text to replace, exactly, whitespace included'],
                    'newText' => ['type' => 'string'],
                ],
                'required' => ['oldText', 'newText'],
            ],
            $this->editBuffer(...),
            effect: ToolEffect::Edits,
        );
        $this->api->registerTool(
            'replace_buffer',
            'Replace the whole text of a file in the editor (opening it behind the user\'s tab if needed) as ONE undoable edit. Not saved to disk: the user reviews it and saves.',
            [
                'type' => 'object',
                'properties' => ['path' => $path('The file; the active one when omitted'), 'text' => ['type' => 'string']],
                'required' => ['text'],
            ],
            $this->replaceBuffer(...),
            effect: ToolEffect::Edits,
        );
        $this->api->registerTool(
            'insert_text',
            'Insert text at the caret of the active file, as one undoable edit. Not saved to disk.',
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            $this->insertText(...),
            effect: ToolEffect::Edits,
        );
        $this->api->registerTool(
            self::SAVE,
            'Save an open file to disk, as the user\'s Ctrl+S does. A file that changed on disk since it was opened is refused, never overwritten — tell the user instead. Without a path, the active file.',
            ['type' => 'object', 'properties' => ['path' => $path('An open file')]],
            $this->saveBuffer(...),
            effect: ToolEffect::Writes,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function context(array $arguments): array
    {
        $caret = $this->api->caretPosition();

        return [
            'projectRoot' => $this->api->projectRoot(),
            'activeFile' => $this->api->activeFile(),
            'caret' => null === $caret ? null : ['line' => $caret['line'] + 1, 'column' => $caret['column'] + 1],
            'selectedFiles' => $this->api->selectedFiles(),
            'openFiles' => $this->api->openBuffers()->files(),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function readBuffer(array $arguments, ToolCaller $caller): string
    {
        $path = $this->arguments($arguments)->path() ?? $this->api->activeFile() ?? throw new \RuntimeException('No file is open');
        $text = $this->api->openBuffers()->text($path) ?? throw new \RuntimeException(\sprintf('%s is not open in the editor — open_file it first, or read it from disk', $path));
        $read = $this->arguments($arguments);
        $numbered = $read->flag('lineNumbers');
        $start = $read->position('startLine');
        $end = $read->position('endLine');
        $this->presence->reading($caller, $path, ($start ?? 1) - 1);
        if (null === $start && null === $end && !$numbered) {
            return $text;
        }
        $lines = \explode("\n", $text);
        $first = ($start ?? 1) - 1;
        $last = \min($end ?? \count($lines), \count($lines));
        if ($first >= \count($lines) || $last <= $first) {
            throw new \InvalidArgumentException(\sprintf('%s has %d lines — lines %d to %d are not among them', $path, \count($lines), $first + 1, $end ?? \count($lines)));
        }
        $slice = \array_slice($lines, $first, $last - $first);
        if ($numbered) {
            $slice = \array_map(static fn (string $line, int $index): string => ($first + $index + 1)."\t".$line, $slice, \array_keys($slice));
        }

        return \implode("\n", $slice);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{text: string, start: array{line: int, column: int}, end: array{line: int, column: int}}|string
     */
    private function selection(array $arguments, ToolCaller $caller): array|string
    {
        $active = $this->api->activeFile();
        $selection = null === $active ? null : $this->api->openBuffers()->selection($active);
        if (null !== $active && null !== $selection) {
            $this->presence->reading($caller, $active, $selection['line'], $selection['column']);
        }

        return (
            null === $selection
                ? 'Nothing is selected'
                : [
                    'text' => $selection['text'],
                    'start' => ['line' => $selection['line'] + 1, 'column' => $selection['column'] + 1],
                    'end' => ['line' => $selection['endLine'] + 1, 'column' => $selection['endColumn'] + 1],
                ]
        );
    }

    /** @param array<string, mixed> $arguments */
    private function openFile(array $arguments): string
    {
        $path = $this->arguments($arguments)->requiredPath();
        if (!\is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a file', $path));
        }
        $this->api->openFile($path);
        $line = $this->arguments($arguments)->position('line');
        if (null !== $line) {
            $this->api->jumpTo($line - 1, ($this->arguments($arguments)->position('column') ?? 1) - 1);
        }

        return 'Opened '.$path;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array<string, int|string>>
     */
    private function diagnostics(array $arguments): array
    {
        $rows = [];
        foreach ($this->api->codeIntelligence()->diagnostics($this->arguments($arguments)->path()) as $diagnostic) {
            $rows[] = [
                ...$diagnostic,
                'line' => $diagnostic['line'] + 1,
                'column' => $diagnostic['column'] + 1,
                'severity' => self::SEVERITIES[$diagnostic['severity']] ?? 'hint',
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $arguments */
    private function notify(array $arguments): string
    {
        $message = \is_string($arguments['message'] ?? null) ? $arguments['message'] : '';
        if ('' === \trim($message)) {
            throw new \InvalidArgumentException('message is required');
        }
        $severity = match ($arguments['severity'] ?? 'info') {
            'success' => ToastSeverity::Success,
            'warn' => ToastSeverity::Warn,
            'error' => ToastSeverity::Error,
            default => ToastSeverity::Info,
        };
        $this->api->notify($message, $severity, 5.0);

        return 'Shown';
    }

    /** @param array<string, mixed> $arguments */
    private function replaceBuffer(array $arguments, ToolCaller $caller): string
    {
        $text = $this->arguments($arguments)->text('text');
        $target = $this->opener->open($this->arguments($arguments)->path());
        $old = (string) $this->api->openBuffers()->text($target);
        [$endLine, $endColumn] = self::lineAndColumn($old, \strlen($old));
        $this->presence->before($caller, $target, $old);
        $this->change($target, 0, 0, $endLine, $endColumn, $text);
        $this->presence->editing($caller, $target);

        return \sprintf('Replaced the text of %s — unsaved; the user can undo it in one step', $target);
    }

    /** @param array<string, mixed> $arguments */
    private function editBuffer(array $arguments, ToolCaller $caller): string
    {
        $read = $this->arguments($arguments);
        $old = $read->text('oldText');
        $new = $read->text('newText');
        if ('' === $old) {
            throw new \InvalidArgumentException('oldText is empty — say what to replace, or use replace_buffer for the whole text');
        }
        $path = $this->opener->open($read->path());
        $text = (string) $this->api->openBuffers()->text($path);
        $at = \strpos($text, $old);
        if (false === $at) {
            throw new \InvalidArgumentException(\sprintf('oldText is not in %s — read_buffer shows its text as it is now, unsaved edits included', $path));
        }
        $count = \substr_count($text, $old);
        if ($count > 1) {
            throw new \InvalidArgumentException(\sprintf('oldText occurs %d times in %s — include more of the lines around the one to change', $count, $path));
        }
        [$line, $column] = self::lineAndColumn($text, $at);
        [$endLine, $endColumn] = self::lineAndColumn($text, $at + \strlen($old));
        $this->presence->before($caller, $path, $text);
        $this->change($path, $line, $column, $endLine, $endColumn, $new);
        // Where a typist who wrote it would have stopped: after the new text.
        [$newLines, $lastColumn] = self::lineAndColumn($new, \strlen($new));
        $this->presence->editing($caller, $path, $line + $newLines, 0 === $newLines ? $column + $lastColumn : $lastColumn);

        return \sprintf('Edited %s at line %d — unsaved; the user can undo it in one step', $path, $line + 1);
    }

    /** Replace a range of an open buffer, as one undo step. */
    private function change(string $path, int $line, int $column, int $endLine, int $endColumn, string $text): void
    {
        $why = $this->api->openBuffers()->edit($path, $line, $column, $endLine, $endColumn, $text);
        if (null !== $why) {
            // The range comes from the text just read, in the same fiber, so
            // it is a range of it; a refusal would still be the caller's to
            // read rather than swallowed.
            // @codeCoverageIgnoreStart
            throw new \RuntimeException($why);

            // @codeCoverageIgnoreEnd
        }
    }

    /** @return array{int, int} the 0-based line and byte column of a byte offset */
    private static function lineAndColumn(string $text, int $offset): array
    {
        $before = \substr($text, offset: 0, length: $offset);
        $newline = \strrpos($before, needle: "\n");

        return [\substr_count($before, needle: "\n"), false === $newline ? $offset : $offset - $newline - 1];
    }

    /** @param array<string, mixed> $arguments */
    private function saveBuffer(array $arguments): string
    {
        $path = $this->arguments($arguments)->path() ?? $this->api->activeFile() ?? throw new \RuntimeException('No file is open');
        $why = $this->api->openBuffers()->save($path);
        if (null !== $why) {
            throw new \RuntimeException(\sprintf('%s was not saved: %s', $path, $why));
        }

        return 'Saved '.$path;
    }

    /** @param array<string, mixed> $arguments */
    private function insertText(array $arguments, ToolCaller $caller): string
    {
        $text = $this->arguments($arguments)->text('text');
        $target = $this->api->activeFile();
        if (null === $target || null === $this->api->openBuffers()->text($target)) {
            throw new \RuntimeException('No text file is active to insert into');
        }
        $this->api->insertAtCaret($text);
        $caret = $this->api->caretPosition() ?? ['line' => 0, 'column' => 0];
        $this->presence->editing($caller, $target, $caret['line'], $caret['column']);

        return 'Inserted into '.$target.' — unsaved';
    }

    /** @param array<string, mixed> $arguments */
    private function arguments(array $arguments): Arguments
    {
        return new Arguments($arguments, $this->api->projectRoot());
    }
}
