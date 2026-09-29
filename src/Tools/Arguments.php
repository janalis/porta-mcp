<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Symfony\Component\Filesystem\Path;

/**
 * A tool call's arguments, read with the checks every tool needs: a path is
 * project-relative or absolute and INSIDE the project, a position is a
 * positive integer. A bad argument throws, which the server turns into an
 * error the caller reads and can correct.
 *
 * A path is resolved through symlinks before it is judged, so a link in the
 * project pointing out of it is outside too: this is the user's IDE, and a
 * caller has no business opening their `~/.ssh` in it.
 */
final readonly class Arguments
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        private array $arguments,
        private string $root,
    ) {}

    /** The `path` argument as an absolute path inside the project, or null when none was given. */
    public function path(): ?string
    {
        if (null === ($this->arguments['path'] ?? null) || '' === $this->arguments['path']) {
            return null;
        }
        if (!\is_string($this->arguments['path'])) {
            throw new \InvalidArgumentException('path is a string');
        }
        $raw = $this->arguments['path'];
        $realRoot = \realpath($this->root);
        $root = false === $realRoot ? $this->root : $realRoot;
        $path = Path::makeAbsolute($raw, $root);
        $real = \realpath($path);
        $path = false === $real ? $path : $real;
        if (!Path::isBasePath($root, $path)) {
            throw new \InvalidArgumentException(\sprintf('%s is outside the project', $raw));
        }

        return $path;
    }

    public function requiredPath(): string
    {
        return $this->path() ?? throw new \InvalidArgumentException('path is required');
    }

    /** A 1-based line or column, or null when it was not given. */
    public function position(string $key): ?int
    {
        if (null === ($this->arguments[$key] ?? null)) {
            return null;
        }
        if (!\is_int($this->arguments[$key]) || $this->arguments[$key] < 1) {
            throw new \InvalidArgumentException($key.' is a positive integer');
        }

        return $this->arguments[$key];
    }

    public function requiredPosition(string $key): int
    {
        return $this->position($key) ?? throw new \InvalidArgumentException($key.' is required');
    }

    /** A string argument that must be there — empty is allowed, for a text that clears. */
    public function text(string $key): string
    {
        if (!\is_string($this->arguments[$key] ?? null)) {
            throw new \InvalidArgumentException($key.' is required');
        }

        return $this->arguments[$key];
    }
}
