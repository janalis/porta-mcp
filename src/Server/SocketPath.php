<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Server;

/**
 * Where the IDE of one project listens, agreed on by the two processes that
 * need it: the IDE that binds and `porta mcp`, which connects.
 *
 * The socket is `.porta/mcp.sock`, beside the project's other runtime files —
 * unless that path is too long to bind. A unix socket's path must fit
 * `sun_path`, 104 bytes on macOS and 108 on Linux, and a deeply nested
 * project root passes that easily. Such a project listens in the temp
 * directory instead, and leaves a POINTER at `.porta/mcp.sock.path` saying
 * where. Two reasons the bridge reads the pointer rather than working the
 * path out again: the temp directory is not the same for every process of a
 * user (a client may launch the bridge with its own TMPDIR), and the name is
 * RANDOM — on Linux the temp directory is `/tmp`, shared by every user, and
 * a name derived from the project could be taken first by someone else, who
 * would then be the one the bridge talked to.
 */
final readonly class SocketPath
{
    /** The longest path both kernels bind, with the terminator's byte to spare. */
    public const int MAX_LENGTH = 103;

    private string $tempDir;

    public function __construct(
        private string $root,
        ?string $tempDir = null,
    ) {
        $this->tempDir = $tempDir ?? \sys_get_temp_dir();
    }

    /** The pointer file, which exists only while a long-rooted project is listening. */
    public function pointer(): string
    {
        return $this->root.'/.porta/mcp.sock.path';
    }

    /**
     * Where the IDE binds, with the pointer written when that is not the
     * default — a fresh name on every call. Creates `.porta/` when the
     * project has none yet.
     */
    public function forServer(): string
    {
        $default = $this->root.'/.porta/mcp.sock';
        if (!\is_dir(\dirname($default))) {
            \mkdir(\dirname($default), recursive: true);
        }
        if (\strlen($default) <= self::MAX_LENGTH) {
            return $default;
        }
        $short = \rtrim($this->tempDir, characters: '/').'/porta-mcp-'.\bin2hex(\random_bytes(8)).'.sock';
        \file_put_contents($this->pointer(), $short);

        return $short;
    }

    /**
     * Where a running IDE listens, if one does: the pointer's path when there
     * is one, else the default. The bridge connects here, and a starting IDE
     * looks here first for another that already serves the project.
     */
    public function current(): string
    {
        $pointer = \is_file($this->pointer()) ? \trim((string) \file_get_contents($this->pointer())) : '';

        return '' !== $pointer ? $pointer : $this->root.'/.porta/mcp.sock';
    }

    /** The pointer goes with the socket, so a stale one never sends a bridge somewhere empty. */
    public function release(): void
    {
        if (\is_file($this->pointer())) {
            \unlink($this->pointer());
        }
    }
}
