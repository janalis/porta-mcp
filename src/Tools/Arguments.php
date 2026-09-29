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

        return $this->inside($this->arguments['path']);
    }

    /**
     * A list-of-paths argument, each inside the project; null when it was not
     * given.
     *
     * @return list<string>|null
     */
    public function paths(string $key): ?array
    {
        if (null === ($this->arguments[$key] ?? null)) {
            return null;
        }
        $raw = \is_array($this->arguments[$key]) ? $this->arguments[$key] : [0];
        $strings = \array_filter($raw, static fn (mixed $one): bool => \is_string($one) && '' !== $one);
        if (!\array_is_list($raw) || \count($strings) !== \count($raw)) {
            throw new \InvalidArgumentException($key.' is a list of paths');
        }

        return \array_values(\array_map($this->inside(...), $strings));
    }

    /** A yes/no argument; absent is no. */
    public function flag(string $key): bool
    {
        return $this->flagOr($key, false);
    }

    /** A yes/no argument, $default when absent. */
    public function flagOr(string $key, bool $default): bool
    {
        if (null === ($this->arguments[$key] ?? null)) {
            return $default;
        }
        if (!\is_bool($this->arguments[$key])) {
            throw new \InvalidArgumentException($key.' is true or false');
        }

        return $this->arguments[$key];
    }

    /** The project root, resolved, so every path is compared with one spelling of it. */
    public function root(): string
    {
        $real = \realpath($this->root);

        return false === $real ? $this->root : $real;
    }

    private function inside(string $raw): string
    {
        $root = $this->root();
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
