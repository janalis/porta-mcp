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

    /** A file's type, in a stat's mode, and the type that is a socket. */
    private const int TYPE = 0o170_000;

    private const int SOCKET = 0o140_000;

    private string $tempDir;

    private int $user;

    /** @param int|null $user whose socket alone is believed: this process's */
    public function __construct(
        private string $root,
        ?string $tempDir = null,
        ?int $user = null,
    ) {
        $this->tempDir = $tempDir ?? \sys_get_temp_dir();
        // Without ext-posix, the owner of the script running: the user's own install.
        $this->user = $user ?? (\function_exists('posix_geteuid') ? \posix_geteuid() : (int) \getmyuid());
    }

    /** The pointer file, which exists only while a long-rooted project is listening. */
    public function pointer(): string
    {
        return $this->root.'/.porta/mcp.sock.path';
    }

    /**
     * Where the IDE binds, with the pointer written when that is not the
     * default — a fresh name on every call. Creates `.porta/` when the
     * project has none yet; when it cannot, the default, whose bind then
     * fails saying why.
     */
    public function forServer(): string
    {
        $default = $this->root.'/.porta/mcp.sock';
        $dir = \dirname($default);
        // @mago-expect lint:no-error-control-operator -- a folder that cannot be made fails the bind at the default, which says so; the warning would only repeat it
        $made = \is_dir($dir) || @\mkdir($dir, recursive: true) || \is_dir($dir);
        if (!$made || \strlen($default) <= self::MAX_LENGTH) {
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
     *
     * The pointer is a file in the project, and a project is something one
     * clones — so only a pointer to a name of OUR making is believed: an
     * absolute `porta-mcp-<16 hex>.sock`, in whatever temp directory. Anything
     * else could aim the bridge, or the clean-up of a dead socket, anywhere.
     * And only to a socket that is there and the user's own, itself and not
     * through a link: in a `/tmp` every user shares, someone else could
     * listen at the name a cloned pointer gives, or put a link there that
     * passes for the user's and is aimed elsewhere once believed. Answered
     * with its folder resolved, so no link is followed later either. A
     * socket of the user's in sticky `/tmp` is not someone else's to swap.
     */
    public function current(): string
    {
        $pointer = \is_file($this->pointer()) ? \trim((string) \file_get_contents($this->pointer())) : '';
        $folder = 1 === \preg_match('~^/(?:[^/]+/)*porta-mcp-[0-9a-f]{16}\\.sock$~D', $pointer) ? \realpath(\dirname($pointer)) : false;
        $socket = false === $folder ? '' : $folder.'/'.\basename($pointer);
        $entry = '' !== $socket && \file_exists($socket) ? \lstat($socket) : false;
        $ours = false !== $entry && self::SOCKET === ($entry['mode'] & self::TYPE) && $entry['uid'] === $this->user;

        return $ours ? $socket : $this->root.'/.porta/mcp.sock';
    }

    /** The pointer goes with the socket, so a stale one never sends a bridge somewhere empty. */
    public function release(): void
    {
        if (\is_file($this->pointer())) {
            \unlink($this->pointer());
        }
    }
}
