<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Porta\Lsp\Uri;
use Porta\McpPlugin\Server\TextBlocks;
use Porta\McpPlugin\Tools\Presence;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Porta\Plugin\Api\ToolEffect;

/**
 * The tools Claude Code calls on an IDE it is connected to — its own names
 * and shapes, the ones VS Code's extension answers, so the CLI needs nothing
 * Porta-specific: open a file, the selection, the open editors, the project
 * folder, diagnostics, whether a file has unsaved edits, save one — and show
 * a proposed change for the user to accept or reject ({@see DiffReview}).
 *
 * Kept apart from {@see PluginApi::registerTool()}: these are this
 * connection's own protocol, not tools an agent on `porta mcp` should see
 * twice under other names. Rows in the shape a
 * {@see \Porta\McpPlugin\Server\ToolCatalog} reads.
 *
 * Answers are JSON text, as the CLI expects them; paths are absolute, as it
 * sends them.
 */
final readonly class IdeTools
{
    private const array SEVERITIES = [1 => 'Error', 2 => 'Warning', 3 => 'Information', 4 => 'Hint'];

    /** @param string $root the project folder, resolved, as the lock file names it */
    public function __construct(
        private PluginApi $api,
        private SelectionFeed $selection,
        private string $root,
        private DiffReview $diffs,
        private Presence $presence,
    ) {}

    /** @return list<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>|TextBlocks), effect: ToolEffect}> */
    public function rows(): array
    {
        $file = ['type' => 'object', 'properties' => ['filePath' => ['type' => 'string']], 'required' => ['filePath']];
        $none = ['type' => 'object', 'properties' => []];

        return [
            $this->row(
                'openFile',
                'Open a file in the editor, optionally at the first line holding startText.',
                [
                    'type' => 'object',
                    'properties' => [
                        'filePath' => ['type' => 'string'],
                        'preview' => ['type' => 'boolean'],
                        'startText' => ['type' => 'string'],
                        'endText' => ['type' => 'string'],
                        'selectToEndOfLine' => ['type' => 'boolean'],
                        'makeFrontmost' => ['type' => 'boolean'],
                    ],
                    'required' => ['filePath'],
                ],
                $this->openFile(...),
                ToolEffect::Edits,
            ),
            $this->row('getCurrentSelection', 'The selection in the file in front.', $none, fn (array $arguments): array => $this->selectionOr($this->selection->current()), ToolEffect::Reads),
            $this->row('getLatestSelection', 'The last selection there was.', $none, fn (array $arguments): array => $this->selectionOr($this->selection->latest()), ToolEffect::Reads),
            $this->row('getOpenEditors', 'The open tabs.', $none, $this->openEditors(...), ToolEffect::Reads),
            $this->row('getWorkspaceFolders', 'The project folder.', $none, $this->workspaceFolders(...), ToolEffect::Reads),
            $this->row(
                'getDiagnostics',
                'Diagnostics for a file, or every open one.',
                ['type' => 'object', 'properties' => ['uri' => ['type' => 'string']]],
                $this->diagnostics(...),
                ToolEffect::Reads,
            ),
            $this->row('checkDocumentDirty', 'Whether an open file has unsaved edits.', $file, $this->dirty(...), ToolEffect::Reads),
            $this->row('saveDocument', 'Save an open file.', $file, $this->save(...), ToolEffect::Writes),
            $this->row(
                'openDiff',
                'Show a proposed new text for a file and wait for the user to accept or reject it.',
                [
                    'type' => 'object',
                    'properties' => [
                        'old_file_path' => ['type' => 'string'],
                        'new_file_path' => ['type' => 'string'],
                        'new_file_contents' => ['type' => 'string'],
                        'tab_name' => ['type' => 'string'],
                    ],
                    'required' => ['old_file_path', 'new_file_path', 'new_file_contents', 'tab_name'],
                ],
                $this->diffs->open(...),
                ToolEffect::Writes,
            ),
            $this->row(
                'close_tab',
                'Close a tab this connection opened.',
                ['type' => 'object', 'properties' => ['tab_name' => ['type' => 'string']]],
                static fn (array $arguments): string => 'TAB_CLOSED',
                ToolEffect::Edits,
            ),
            $this->row(
                'closeAllDiffTabs',
                'Turn down every proposal still waiting for the user.',
                $none,
                fn (array $arguments): string => \sprintf('CLOSED_%d_DIFF_TABS', $this->diffs->rejectAll()),
                ToolEffect::Edits,
            ),
        ];
    }

    /**
     * @param array<string, mixed>                                               $schema
     * @param \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>|TextBlocks) $handler
     *
     * @return array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>|TextBlocks), effect: ToolEffect}
     */
    private function row(string $id, string $description, array $schema, \Closure $handler, ToolEffect $effect): array
    {
        return ['plugin' => 'mcp', 'id' => $id, 'description' => $description, 'inputSchema' => $schema, 'handler' => $handler, 'effect' => $effect];
    }

    /**
     * @param array<string, mixed>|null $selection
     *
     * @return array<string, mixed>
     */
    private function selectionOr(?array $selection): array
    {
        return null === $selection ? ['success' => false, 'message' => 'No active editor found'] : ['success' => true] + $selection;
    }

    /** @param array<string, mixed> $arguments */
    private function openFile(array $arguments, ToolCaller $caller): string
    {
        $path = self::path($arguments['filePath'] ?? null);
        if (!\is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('File not found: %s', $path));
        }
        $this->api->openFile($path);
        $start = \is_string($arguments['startText'] ?? null) ? $arguments['startText'] : '';
        $text = '' === $start ? null : $this->api->openBuffers()->text($path);
        $at = null === $text ? false : \strpos($text, $start);
        $line = false === $at || null === $text ? 0 : \substr_count($text, needle: "\n", offset: 0, length: $at);
        if ($line > 0) {
            $this->api->jumpTo($line);
        }
        $this->presence->reading($caller, $path, $line);

        return 'Opened file: '.$path;
    }

    /** @return array{tabs: list<array{uri: string, isActive: bool, label: string, languageId: string, isDirty: bool}>} */
    private function openEditors(): array
    {
        $active = $this->api->activeFile();

        return ['tabs' => \array_map(static fn (array $file): array => [
            'uri' => Uri::fromPath($file['path']),
            'isActive' => $file['path'] === $active,
            'label' => \basename($file['path']),
            'languageId' => \strtolower(\pathinfo($file['path'], \PATHINFO_EXTENSION)),
            'isDirty' => $file['dirty'],
        ], $this->api->openBuffers()->files())];
    }

    /** @return array{success: true, folders: list<array{name: string, uri: string, path: string}>, rootPath: string} */
    private function workspaceFolders(): array
    {
        return ['success' => true, 'folders' => [['name' => \basename($this->root), 'uri' => Uri::fromPath($this->root), 'path' => $this->root]], 'rootPath' => $this->root];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{uri: string, diagnostics: list<array{message: string, severity: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, source?: string}>}>
     */
    private function diagnostics(array $arguments): array
    {
        $uri = \is_string($arguments['uri'] ?? null) && '' !== $arguments['uri'] ? $arguments['uri'] : null;
        $byFile = [];
        foreach ($this->api->codeIntelligence()->diagnostics(null === $uri ? null : Uri::toPath($uri) ?? $uri) as $row) {
            $at = ['line' => $row['line'], 'character' => $row['column']];
            $byFile[$row['path']][] =
                ['message' => $row['message'], 'severity' => self::SEVERITIES[$row['severity']] ?? 'Hint', 'range' => ['start' => $at, 'end' => $at]]
                + (\array_key_exists('source', $row) ? ['source' => $row['source']] : []);
        }
        $files = [];
        foreach ($byFile as $path => $diagnostics) {
            $files[] = ['uri' => Uri::fromPath($path), 'diagnostics' => $diagnostics];
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, bool|string>
     */
    private function dirty(array $arguments): array
    {
        $path = self::path($arguments['filePath'] ?? null);
        foreach ($this->api->openBuffers()->files() as $file) {
            if ($path === $file['path']) {
                return ['success' => true, 'filePath' => $path, 'isDirty' => $file['dirty'], 'isUntitled' => false];
            }
        }

        return ['success' => false, 'message' => 'Document not open: '.$path];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, bool|string>
     */
    private function save(array $arguments): array
    {
        $path = self::path($arguments['filePath'] ?? null);
        $why = $this->api->openBuffers()->save($path);

        return (
            null === $why
                ? ['success' => true, 'filePath' => $path, 'saved' => true, 'message' => 'Document saved successfully']
                : ['success' => false, 'filePath' => $path, 'saved' => false, 'message' => $why]
        );
    }

    /** An absolute path as the CLI sends it, resolved the way buffers are keyed. */
    private static function path(mixed $raw): string
    {
        if (!\is_string($raw) || '' === $raw) {
            throw new \InvalidArgumentException('filePath is required');
        }
        $real = \realpath($raw);

        return false === $real ? $raw : $real;
    }
}
