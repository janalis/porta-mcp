<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Server;

use Porta\Support\LineFramer;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * The IDE's end of the MCP connection: a unix socket on the IDE's own event
 * loop, one {@see Session} per client that connects.
 *
 * A unix socket rather than TCP because the only clients it is for run as the
 * same user on the same machine — `porta mcp`, launched by an agent — and a
 * socket file answers who may connect with a file mode, 0600, where a port is
 * open to every local user and would need a token. It is bound with the
 * umask tightened, so there is no moment when the file is wider than that.
 *
 * Every watcher is UNREFERENCED: a listening server must never be what keeps
 * the loop alive once the IDE has decided to quit.
 */
final class SocketServer
{
    /** @var resource|null */
    private $server;

    private ?string $acceptWatcher = null;

    private ?string $path = null;

    private int $next = 0;

    /** @var array<int, array{stream: resource, watcher: string, writer: NonBlockingWriter, session: Session}> */
    private array $connections = [];

    /**
     * @param \Closure(\Closure(array<string, mixed>): void, string): Session $sessions builds the session for a new client, given how to send to it and its connection id
     * @param \Closure(string): void                                     $log      a connection that failed, in a sentence
     */
    public function __construct(
        private readonly SocketPath $paths,
        private readonly \Closure $sessions,
        private readonly \Closure $log,
    ) {}

    /** Start listening; the reason when it cannot, null when it is. */
    public function start(): ?string
    {
        if (null !== $this->server) {
            return null;
        }
        $existing = $this->paths->current();
        if (\file_exists($existing)) {
            // A live peer means another Porta already serves this project, and
            // taking its socket would strand its clients. A refused connect is
            // a crashed one's leftover: that file is ours to replace.
            // @mago-expect lint:no-error-control-operator -- a refused connect warns; the false return is the answer being asked for
            $peer = @\stream_socket_client('unix://'.$existing, timeout: 0.5);
            if (false !== $peer) {
                \fclose($peer);

                return 'Another Porta is already serving MCP for this project';
            }
            \unlink($existing);
        }
        $path = $this->paths->forServer();
        $umask = \umask(0o077);
        try {
            $errno = 0;
            $error = '';
            // @mago-expect lint:no-error-control-operator -- a bind failure warns as well as returning false; the message is carried in $error
            $server = @\stream_socket_server('unix://'.$path, $errno, $error);
        } finally {
            \umask($umask);
        }
        if (false === $server) {
            $this->paths->release();

            return \sprintf('MCP could not listen at %s: %s', $path, $error);
        }
        \stream_set_blocking($server, enable: false);
        $this->server = $server;
        $this->path = $path;
        $this->acceptWatcher = EventLoop::unreference(EventLoop::onReadable($server, fn () => $this->accept($server)));

        return null;
    }

    /** Stop listening and hang up on every client; the socket file goes too. */
    public function stop(): void
    {
        foreach (\array_keys($this->connections) as $id) {
            $this->drop($id);
        }
        if (null !== $this->acceptWatcher) {
            EventLoop::cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
        if (null !== $this->server) {
            \fclose($this->server);
            $this->server = null;
        }
        // Only what THIS server made: one that never started — another Porta
        // owned the project — must leave that one's socket and pointer alone.
        if (null === $this->path) {
            return;
        }
        if (\file_exists($this->path)) {
            \unlink($this->path);
        }
        $this->path = null;
        $this->paths->release();
    }

    /** Where it listens, or null while it does not. */
    public function path(): ?string
    {
        return $this->path;
    }

    public function clientCount(): int
    {
        return \count($this->connections);
    }

    /** Tell every client the tool set moved. */
    public function toolsChanged(): void
    {
        foreach ($this->connections as $connection) {
            $connection['session']->toolsChanged();
        }
    }

    /** @param resource $server */
    private function accept($server): void
    {
        // @mago-expect lint:no-error-control-operator -- a client that gave up between readable and accept warns; false is handled
        $stream = @\stream_socket_accept($server, timeout: 0);
        if (false === $stream) {
            // The kernel took back a connection it had announced: a race no test can force.
            // @codeCoverageIgnoreStart
            return;

            // @codeCoverageIgnoreEnd
        }
        \stream_set_blocking($stream, enable: false);
        $id = $this->next++;
        $writer = new NonBlockingWriter($stream, function (string $reason) use ($id): void {
            ($this->log)('MCP client dropped: '.$reason);
            $this->drop($id);
        });
        $session = ($this->sessions)(static function (array $message) use ($writer): void {
            $writer->write(\json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }, 'mcp-'.$id);
        $framer = new LineFramer($this->log);
        $watcher = EventLoop::unreference(EventLoop::onReadable($stream, function () use ($id, $stream, $framer, $session): void {
            $bytes = \fread($stream, length: 65_536);
            if (false === $bytes || '' === $bytes && \feof($stream)) {
                $this->drop($id);

                return;
            }
            foreach ($framer->feed($bytes) as $line) {
                $session->receive($line);
            }
        }));
        $this->connections[$id] = ['stream' => $stream, 'watcher' => $watcher, 'writer' => $writer, 'session' => $session];
    }

    /**
     * Called once per connection: its read watcher and its writer — the only
     * two callers besides stop() — are both dead after the first call.
     */
    private function drop(int $id): void
    {
        $connection = $this->connections[$id];
        unset($this->connections[$id]);
        EventLoop::cancel($connection['watcher']);
        $connection['writer']->close();
        \fclose($connection['stream']);
        $connection['session']->close();
    }
}
