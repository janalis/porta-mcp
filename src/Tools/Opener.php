<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Plugin\Api\PluginApi;

/**
 * Where an agent's change to a file happens: in a tab BEHIND the one the user
 * is on, so they see it without being taken there — or, with the
 * `followAgent` setting, in front, the tab brought forward as the user's own
 * open would.
 */
final readonly class Opener
{
    /** @param \Closure(): bool $follow whether to bring the file forward, read per call */
    public function __construct(
        private PluginApi $api,
        private \Closure $follow,
    ) {}

    /**
     * The file to change, open as text: $path, or the active file when null.
     * Throws when there is none, or it is not a file or not text.
     */
    public function open(?string $path): string
    {
        $target = $path ?? $this->api->activeFile() ?? throw new \RuntimeException('No text file to edit — no file is open');
        $buffers = $this->api->openBuffers();
        if (null === $buffers->text($target) && !\is_file($target)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a file', $target));
        }
        $follow = ($this->follow)();
        if ($follow && $target !== $this->api->activeFile()) {
            $this->api->openFile($target);
        }
        if (!$follow) {
            $buffers->open($target);
        }

        return null !== $buffers->text($target) ? $target : throw new \RuntimeException(\sprintf('No text file to edit — %s is not one', $target));
    }
}
