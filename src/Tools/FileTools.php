<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Porta\Plugin\Api\ToolEffect;

/**
 * Files themselves as tools: creating one, renaming or moving one, deleting
 * one — each as the IDE does it, so an open tab follows its file with its
 * unsaved edits, and every store keyed by path hears of it.
 *
 * Revertable through local history: a delete hands the file's text to it,
 * and Recent Changes brings the file back; a move carries the file's history
 * along; a created file goes the way any file goes. A file with unsaved edits
 * in the editor is never deleted — those are the user's.
 *
 * Behind the `allowFileOps` setting, off until the user turns it on.
 */
final readonly class FileTools
{
    /** The tools that create, move and delete — what the `allowFileOps` setting hides. */
    public const array FILE_OPS = ['create_file', 'move_file', 'delete_file'];

    public function __construct(
        private PluginApi $api,
        private Presence $presence,
    ) {}

    public function register(): void
    {
        $path = static fn (string $what): array => ['type' => 'string', 'description' => $what.', project-relative or absolute'];
        $this->api->registerTool(
            'create_file',
            'Create a file with the given text — its folders too — on disk. It is not opened; refused when the file exists.',
            ['type' => 'object', 'properties' => ['path' => $path('The new file'), 'text' => ['type' => 'string', 'description' => 'Its content; empty when omitted']], 'required' => ['path']],
            $this->create(...),
            effect: ToolEffect::Writes,
        );
        $this->api->registerTool(
            'move_file',
            'Rename or move a file or folder; a tab open on it follows, unsaved edits and all, and its local history moves with it. Refused when something is already at the target.',
            ['type' => 'object', 'properties' => ['from' => $path('The file or folder'), 'to' => $path('Where it goes')], 'required' => ['from', 'to']],
            $this->move(...),
            effect: ToolEffect::Writes,
        );
        $this->api->registerTool(
            'delete_file',
            'Delete a file. Its text is kept in local history, where the user can restore it from Recent Changes. Refused for a folder, and for a file with unsaved edits in the editor — ask the user instead.',
            ['type' => 'object', 'properties' => ['path' => $path('The file')], 'required' => ['path']],
            $this->delete(...),
            effect: ToolEffect::Writes,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function create(array $arguments, ToolCaller $caller): string
    {
        $read = new Arguments($arguments, $this->api->projectRoot());
        $path = $read->requiredPath();
        $text = \is_string($arguments['text'] ?? null) ? $arguments['text'] : '';
        self::refused($this->api->openBuffers()->create($path, $text));
        $this->presence->editing($caller, $path, -1);

        return 'Created '.$path;
    }

    /** @param array<string, mixed> $arguments */
    private function move(array $arguments, ToolCaller $caller): string
    {
        $read = new Arguments($arguments, $this->api->projectRoot());
        $from = $read->requiredPathAt('from');
        $to = $read->requiredPathAt('to');
        self::refused($this->api->openBuffers()->move($from, $to));
        if (\is_file($to)) {
            $this->presence->editing($caller, $to, -1);
        }

        return \sprintf('Moved %s to %s', $from, $to);
    }

    /** @param array<string, mixed> $arguments */
    private function delete(array $arguments): string
    {
        $path = new Arguments($arguments, $this->api->projectRoot())->requiredPath();
        self::refused($this->api->openBuffers()->delete($path));

        return \sprintf('Deleted %s — its text is in local history, restorable from Recent Changes', $path);
    }

    private static function refused(?string $why): void
    {
        if (null !== $why) {
            throw new \RuntimeException($why);
        }
    }
}
