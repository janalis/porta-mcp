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
        return $this->pathAt('path');
    }

    /** A path argument by name, absolute and inside the project, or null when it was not given. */
    private function pathAt(string $key): ?string
    {
        if (null === ($this->arguments[$key] ?? null) || '' === $this->arguments[$key]) {
            return null;
        }
        if (!\is_string($this->arguments[$key])) {
            throw new \InvalidArgumentException($key.' is a string');
        }

        return $this->inside($this->arguments[$key]);
    }

    /** {@see pathAt()}, required. */
    public function requiredPathAt(string $key): string
    {
        return $this->pathAt($key) ?? throw new \InvalidArgumentException($key.' is required');
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
        $path = self::resolved(Path::makeAbsolute($raw, $root));
        if (null === $path || !Path::isBasePath($root, $path)) {
            throw new \InvalidArgumentException(\sprintf('%s is outside the project', $raw));
        }

        return $path;
    }

    /**
     * $path with every link in it followed. What is not there yet — a file to
     * create, a move's target, the folders a create makes — is resolved
     * through the deepest folder that is, so a project behind a symlink
     * spells it the way it spells everything else, and a link in the project
     * to a folder outside it is outside, however deep the new path goes.
     * Null through a link to nothing: what is made through it lands wherever
     * it points.
     */
    private static function resolved(string $path): ?string
    {
        $missing = '';
        $real = \realpath($path);
        while (false === $real) {
            if (\is_link($path)) {
                return null;
            }
            $missing = '/'.\basename($path).$missing;
            $path = \dirname($path);
            $real = \realpath($path);
        }

        return $real.$missing;
    }

    public function requiredPath(): string
    {
        return $this->requiredPathAt('path');
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
