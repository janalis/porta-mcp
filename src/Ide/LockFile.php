<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Porta\Support\JsonFile;

/**
 * How Claude Code finds an IDE: a file per listening IDE in its config
 * directory's `ide/`, named for the port, saying which process, which
 * project folders, and the token a client must present.
 *
 * `$CLAUDE_CONFIG_DIR/ide` when that is set, else `~/.claude/ide` — the same
 * rule the CLI reads it by. Written through a temporary file and a rename, so
 * a CLI scanning the directory never reads half of one; readable by the user
 * alone, since it holds the token.
 */
final readonly class LockFile
{
    /** What the CLI calls this IDE, in `/ide` and its status line. */
    public const string IDE_NAME = 'Porta';

    public function __construct(
        private string $directory,
    ) {}

    /** The directory the CLI reads, from the environment it would read it from. */
    public static function directory(): string
    {
        $config = \getenv('CLAUDE_CONFIG_DIR');
        $home = \getenv('HOME');

        return (\is_string($config) && '' !== $config ? $config : (\is_string($home) ? $home : '').'/.claude').'/ide';
    }

    public function path(int $port): string
    {
        return $this->directory.'/'.$port.'.lock';
    }

    /**
     * Say this IDE listens on $port for $folders. Null once written, else why
     * not — a directory that cannot be made or written.
     *
     * @param list<string> $folders
     */
    public function write(int $port, array $folders, #[\SensitiveParameter] string $token): ?string
    {
        // @mago-expect lint:no-error-control-operator -- a directory that cannot be made warns; the checks around it are what is reported
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, permissions: 0o700, recursive: true) && !\is_dir($this->directory)) {
            return \sprintf('%s could not be created', $this->directory);
        }

        return $this->put($port, $folders, $token);
    }

    /**
     * {@see write()}, in a directory that is there.
     *
     * @param list<string> $folders
     */
    private function put(int $port, array $folders, #[\SensitiveParameter] string $token): ?string
    {
        $json = \json_encode(
            [
                'pid' => \getmypid(),
                'workspaceFolders' => $folders,
                'ideName' => self::IDE_NAME,
                'transport' => 'ws',
                'authToken' => $token,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );
        $temporary = $this->path($port).'.tmp';
        // @mago-expect lint:no-error-control-operator -- an unwritable directory warns; the false return is what is reported
        if (false === @\file_put_contents($temporary, $json)) {
            return \sprintf('%s is not writable', $this->directory);
        }
        \chmod($temporary, permissions: 0o600);
        \rename($temporary, $this->path($port));

        return null;
    }

    /**
     * Take back what a Porta that died without stopping — killed, crashed,
     * powered off — left behind: a lock naming this IDE and a process that
     * is no more would send the CLI to a port nobody listens on, or someone
     * else's. Another IDE's locks, and a live Porta's, are left alone.
     */
    public function sweep(): void
    {
        if (!\function_exists('posix_kill')) {
            // Without ext-posix there is no asking whether a process lives.
            // @codeCoverageIgnoreStart
            return;

            // @codeCoverageIgnoreEnd
        }
        $paths = \glob($this->directory.'/*.lock');
        foreach (false === $paths ? [] : $paths as $path) {
            // Another program's lock file, hand-edited or half-written: one that
            // is not JSON reads as none.
            $lock = JsonFile::read($path);
            $pid = null !== $lock && self::IDE_NAME === ($lock['ideName'] ?? null) && \is_int($lock['pid'] ?? null) ? $lock['pid'] : null;
            // ESRCH alone means gone: EPERM is a live process of another user's.
            if (null === $pid || \posix_kill($pid, signal: 0) || 3 !== \posix_get_last_error()) {
                continue;
            }
            \unlink($path);
        }
    }

    public function remove(int $port): void
    {
        if (\is_file($this->path($port))) {
            \unlink($this->path($port));
        }
    }
}
