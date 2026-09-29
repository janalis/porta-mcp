<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Symfony\Component\Filesystem\Path;

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
 * A path is project-relative or absolute, and must be inside the project:
 * this is the user's IDE, and a caller has no business opening their
 * `~/.ssh` in it.
 */
final readonly class CoreTools
{
    /** The tools that change a buffer — what the `allowEdits` setting hides. */
    public const array EDITS = ['replace_buffer', 'insert_text'];

    private const array NO_ARGUMENTS = ['type' => 'object', 'properties' => []];

    private const array SEVERITIES = [1 => 'error', 2 => 'warning', 3 => 'information', 4 => 'hint'];

    public function __construct(
        private PluginApi $api,
    ) {}

    public function register(): void
    {
        $path = static fn (string $what): array => ['type' => 'string', 'description' => $what.', project-relative or absolute'];
        $this->api->registerTool(
            'context',
            'What the user has in front of them: the project root, the active file and caret, the files selected in the tree, and every open tab with whether it has unsaved edits.',
            self::NO_ARGUMENTS,
            $this->context(...),
        );
        $this->api->registerTool(
            'read_buffer',
            'The text of a file OPEN in the editor, unsaved edits included — the disk copy may be older. Without a path, the active file.',
            ['type' => 'object', 'properties' => ['path' => $path('An open file')]],
            $this->readBuffer(...),
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
        );
        $this->api->registerTool(
            'diagnostics',
            'Errors and warnings the language servers and analysers reported for the open files — or one of them — with 1-based positions. Files that are not open have none here.',
            ['type' => 'object', 'properties' => ['path' => $path('An open file')]],
            $this->diagnostics(...),
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
        );
        $this->api->registerTool(
            'replace_buffer',
            'Replace the whole text of a file in the editor (opening it first if needed) as ONE undoable edit. Not saved to disk: the user reviews it and saves.',
            [
                'type' => 'object',
                'properties' => ['path' => $path('The file; the active one when omitted'), 'text' => ['type' => 'string']],
                'required' => ['text'],
            ],
            $this->replaceBuffer(...),
        );
        $this->api->registerTool(
            'insert_text',
            'Insert text at the caret of the active file, as one undoable edit. Not saved to disk.',
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            $this->insertText(...),
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
            'openFiles' => $this->api->openFiles(),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function readBuffer(array $arguments): string
    {
        $path = $this->optionalPath($arguments) ?? $this->api->activeFile() ?? throw new \RuntimeException('No file is open');
        $text = $this->api->bufferText($path);

        return $text ?? throw new \RuntimeException(\sprintf('%s is not open in the editor — open_file it first, or read it from disk', $path));
    }

    /** @param array<string, mixed> $arguments */
    private function openFile(array $arguments): string
    {
        $path = $this->optionalPath($arguments) ?? throw new \InvalidArgumentException('path is required');
        if (!\is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a file', $path));
        }
        $this->api->openFile($path);
        $line = self::position($arguments, 'line');
        if (null !== $line) {
            $this->api->jumpTo($line - 1, (self::position($arguments, 'column') ?? 1) - 1);
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
        foreach ($this->api->diagnostics($this->optionalPath($arguments)) as $diagnostic) {
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
    private function replaceBuffer(array $arguments): string
    {
        $text = self::text($arguments);
        $path = $this->optionalPath($arguments);
        if (null !== $path && $path !== $this->api->activeFile()) {
            if (null === $this->api->bufferText($path) && !\is_file($path)) {
                throw new \InvalidArgumentException(\sprintf('%s is not a file', $path));
            }
            $this->api->openFile($path);
        }
        $target = $this->api->activeFile();
        if (null === $target || null === $this->api->bufferText($target) || null !== $path && $path !== $target) {
            throw new \RuntimeException('No text file to replace — the active tab is not one');
        }
        $this->api->replaceDocument($text);

        return \sprintf('Replaced the text of %s — unsaved; the user can undo it in one step', $target);
    }

    /** @param array<string, mixed> $arguments */
    private function insertText(array $arguments): string
    {
        $text = self::text($arguments);
        $target = $this->api->activeFile();
        if (null === $target || null === $this->api->bufferText($target)) {
            throw new \RuntimeException('No text file is active to insert into');
        }
        $this->api->insertAtCaret($text);

        return 'Inserted into '.$target.' — unsaved';
    }

    /**
     * The `path` argument as an absolute path inside the project, or null when
     * none was given. Symlinks are followed before the check, so a link in the
     * project pointing out of it is outside too.
     *
     * @param array<string, mixed> $arguments
     */
    private function optionalPath(array $arguments): ?string
    {
        if (null === ($arguments['path'] ?? null) || '' === $arguments['path']) {
            return null;
        }
        if (!\is_string($arguments['path'])) {
            throw new \InvalidArgumentException('path is a string');
        }
        $raw = $arguments['path'];
        $root = $this->api->projectRoot();
        $realRoot = \realpath($root);
        $root = false === $realRoot ? $root : $realRoot;
        $path = Path::makeAbsolute($raw, $root);
        $real = \realpath($path);
        $path = false === $real ? $path : $real;
        if (!Path::isBasePath($root, $path)) {
            throw new \InvalidArgumentException(\sprintf('%s is outside the project', $raw));
        }

        return $path;
    }

    /** @param array<string, mixed> $arguments */
    private static function position(array $arguments, string $key): ?int
    {
        if (null === ($arguments[$key] ?? null)) {
            return null;
        }
        if (!\is_int($arguments[$key]) || $arguments[$key] < 1) {
            throw new \InvalidArgumentException($key.' is a positive integer');
        }

        return $arguments[$key];
    }

    /** @param array<string, mixed> $arguments */
    private static function text(array $arguments): string
    {
        if (!\is_string($arguments['text'] ?? null)) {
            throw new \InvalidArgumentException('text is required');
        }

        return $arguments['text'];
    }
}
