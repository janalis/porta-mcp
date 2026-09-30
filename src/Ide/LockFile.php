<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

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

    public function remove(int $port): void
    {
        if (\is_file($this->path($port))) {
            \unlink($this->path($port));
        }
    }
}
