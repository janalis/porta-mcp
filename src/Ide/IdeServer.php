<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Porta\McpPlugin\Server\Session;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * The IDE end of Claude Code's own IDE connection: the one VS Code and the
 * JetBrains plugins speak, so the CLI shows the file open here and the lines
 * selected, and can open a file in this editor.
 *
 * MCP over a WebSocket on 127.0.0.1 — never another interface — at a port
 * the kernel picks, announced in a {@see LockFile} with a random token the
 * client has to present as `x-claude-code-ide-authorization` on the upgrade.
 * One {@see Session} per client, as the unix-socket server has; what this adds
 * is the transport and {@see broadcast()}, the IDE speaking first.
 *
 * The port is exported to this process's environment as
 * `CLAUDE_CODE_SSE_PORT`, so a `claude` started in Porta's own terminal —
 * which inherits it — connects without being asked.
 *
 * Every watcher is UNREFERENCED, like the other server's.
 */
final class IdeServer
{
    /** Longer than any real upgrade request; a peer still sending headers past it is not one. */
    private const int MAX_HANDSHAKE = 16_384;

    /** Seconds a client has to present its token before it is hung up on. */
    public const float HANDSHAKE_TIMEOUT = 10.0;

    /** @var resource|null */
    private $server;

    private ?string $acceptWatcher = null;

    private ?int $port = null;

    private string $token = '';

    private int $next = 0;

    /** A client said it is ready to hear from the IDE since {@see takeNewcomer()} was last asked. */
    private bool $newcomer = false;

    /** @var array<int, array{stream: resource, watcher: string, writer: NonBlockingWriter, session: Session|null, frames: WebSocketFrames, handshake: string, deadline: string}> */
    private array $connections = [];

    /**
     * @param list<string>                                                       $folders  the project folders the lock file names
     * @param \Closure(\Closure(array<string, mixed>): void, string): Session $sessions builds the session for a new client, given how to send to it and its connection id
     * @param \Closure(string): void                                             $log      a connection that failed, in a sentence
     */
    public function __construct(
        private readonly LockFile $lock,
        private readonly array $folders,
        private readonly \Closure $sessions,
        private readonly \Closure $log,
        private readonly float $handshakeTimeout = self::HANDSHAKE_TIMEOUT,
    ) {}

    /** Start listening; the reason when it cannot, null when it is. */
    public function start(): ?string
    {
        return null === $this->server ? $this->listen() : null;
    }

    /** {@see start()}, not listening yet. */
    private function listen(): ?string
    {
        $errno = 0;
        $error = '';
        // @mago-expect lint:no-error-control-operator -- a bind failure warns as well as returning false; the message is carried in $error
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $server) {
            // Binding an ephemeral port on loopback fails only when the
            // system is out of ports or sockets: nothing a test can arrange.
            // @codeCoverageIgnoreStart
            return \sprintf('The Claude Code connection could not listen: %s', $error);

            // @codeCoverageIgnoreEnd
        }

        return $this->announce($server);
    }

    /**
     * Say where $server listens — in the lock file the CLI looks for, and to
     * the processes started from here — and take its connections.
     *
     * @param resource $server
     */
    private function announce($server): ?string
    {
        $name = (string) \stream_socket_get_name($server, remote: false);
        $port = (int) \substr($name, (int) \strrpos($name, needle: ':') + 1);
        $token = \bin2hex(\random_bytes(16));
        $this->lock->sweep();
        $why = $this->lock->write($port, $this->folders, $token);
        if (null !== $why) {
            \fclose($server);

            return 'The Claude Code connection could not be announced: '.$why;
        }
        \stream_set_blocking($server, enable: false);
        $this->server = $server;
        $this->port = $port;
        $this->token = $token;
        \putenv('CLAUDE_CODE_SSE_PORT='.$port);
        \putenv('ENABLE_IDE_INTEGRATION=true');
        $this->acceptWatcher = EventLoop::unreference(EventLoop::onReadable($server, fn () => $this->accept($server)));

        return null;
    }

    /** Stop listening, hang up on every client, take the lock file back. */
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
        if (null !== $this->port) {
            $this->lock->remove($this->port);
            \putenv('CLAUDE_CODE_SSE_PORT');
            \putenv('ENABLE_IDE_INTEGRATION');
            $this->port = null;
        }
    }

    /** Where it listens, or null while it does not. */
    public function port(): ?int
    {
        return $this->port;
    }

    /** Whether a client has become ready to hear from the IDE since the last ask — and forget it. */
    public function takeNewcomer(): bool
    {
        $newcomer = $this->newcomer;
        $this->newcomer = false;

        return $newcomer;
    }

    public function clientCount(): int
    {
        return \count(\array_filter($this->connections, static fn (array $connection): bool => null !== $connection['session']));
    }

    /**
     * Say something to every connected client, unasked: a JSON-RPC
     * notification — `selection_changed`, `at_mentioned`.
     *
     * @param array<string, mixed> $params
     */
    public function broadcast(string $method, array $params): void
    {
        $frame = WebSocketFrames::encode(\json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
        foreach ($this->connections as $connection) {
            if (null === $connection['session']) {
                continue; // still shaking hands
            }
            $connection['writer']->write($frame);
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
            ($this->log)('Claude Code client dropped: '.$reason);
            $this->drop($id);
        });
        $watcher = EventLoop::unreference(EventLoop::onReadable($stream, function () use ($id, $stream): void {
            $bytes = \fread($stream, length: 65_536);
            if (false === $bytes || ('' === $bytes && \feof($stream))) {
                $this->drop($id);

                return;
            }
            $this->received($id, $bytes);
        }));
        // A client that connects and never says who it is holds a socket for
        // nothing: it has its time to present the token, and no more.
        $deadline = EventLoop::unreference(EventLoop::delay($this->handshakeTimeout, function () use ($id): void {
            // Cancelled when the connection goes, so it is still here.
            if (null !== $this->connections[$id]['session']) {
                return;
            }
            ($this->log)('Claude Code client dropped: no handshake in time');
            $this->drop($id);
        }));
        $this->connections[$id] = ['stream' => $stream, 'watcher' => $watcher, 'writer' => $writer, 'session' => null, 'frames' => new WebSocketFrames(), 'handshake' => '', 'deadline' => $deadline];
    }

    private function received(int $id, string $bytes): void
    {
        $connection = $this->connections[$id];
        if (null === $connection['session']) {
            $this->handshake($id, $connection['handshake'].$bytes);

            return;
        }
        try {
            $messages = $connection['frames']->feed($bytes);
        } catch (\LengthException $tooLarge) {
            ($this->log)('Claude Code client dropped: '.$tooLarge->getMessage());
            $this->drop($id);
            $messages = [];
        }
        foreach ($messages as [$opcode, $payload]) {
            // A frame before this one may have cost the connection — its
            // answer could not be written — and the rest have nobody to go to.
            if (!\array_key_exists($id, $this->connections)) {
                break;
            }
            // The CLI says `ide_connected` once its own handlers are in
            // place — anything the IDE said before that went unheard, so it
            // is the moment to say it again.
            if (WebSocketFrames::TEXT === $opcode && \str_contains($payload, '"ide_connected"')) {
                $this->newcomer = true;
            }
            match ($opcode) {
                WebSocketFrames::TEXT => $connection['session']->receive($payload),
                WebSocketFrames::PING => $connection['writer']->write(WebSocketFrames::encode($payload, WebSocketFrames::PONG)),
                WebSocketFrames::CLOSE => $this->close($id, $payload),
                default => null, // a pong, or binary: nothing this protocol sends
            };
        }
    }

    /**
     * The HTTP upgrade: answered with 101 once the request is whole and it
     * carries the token, refused with 401 and hung up on when it does not.
     */
    private function handshake(int $id, string $request): void
    {
        $end = \strpos($request, needle: "\r\n\r\n");
        if (false === $end) {
            $this->partial($id, $request);

            return;
        }
        /** @var array<string, string> $headers */
        $headers = [];
        foreach (\array_slice(\explode("\r\n", \substr($request, offset: 0, length: $end)), offset: 1) as $line) {
            [$name, $value] = \array_pad(\explode(':', $line, limit: 2), length: 2, value: '');
            $headers[\strtolower(\trim($name))] = \trim($value);
        }
        $key = $headers['sec-websocket-key'] ?? '';
        if ('' === $key || !\hash_equals($this->token, $headers['x-claude-code-ide-authorization'] ?? '')) {
            $this->connections[$id]['writer']->write("HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            $this->connections[$id]['writer']->flushBlocking();
            $this->drop($id);

            return;
        }
        $writer = $this->connections[$id]['writer'];
        // The CLI asks for the `mcp` subprotocol, and a WebSocket client drops
        // a connection whose answer does not name the one it asked for. Its
        // offer of compression is left unanswered, which declines it.
        $protocols = \array_map(\trim(...), \explode(',', $headers['sec-websocket-protocol'] ?? ''));
        $protocol = \in_array('mcp', $protocols, strict: true) ? "Sec-WebSocket-Protocol: mcp\r\n" : '';
        $writer->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".WebSocketFrames::accept($key)."\r\n".$protocol."\r\n");
        $this->connections[$id]['session'] = ($this->sessions)(static function (array $message) use ($writer): void {
            $writer->write(WebSocketFrames::encode(\json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)));
        }, 'ide-'.$id);
        $rest = \substr($request, $end + 4);
        if ('' !== $rest) {
            $this->received($id, $rest);
        }
    }

    /** An upgrade request not whole yet: kept for the rest, unless it is already too long to be one. */
    private function partial(int $id, string $request): void
    {
        if (\strlen($request) > self::MAX_HANDSHAKE) {
            $this->drop($id);

            return;
        }
        $this->connections[$id]['handshake'] = $request;
    }

    /** The client closed: close back, as the protocol asks, and hang up. */
    private function close(int $id, string $payload): void
    {
        $this->connections[$id]['writer']->write(WebSocketFrames::encode(\substr($payload, offset: 0, length: 2), WebSocketFrames::CLOSE));
        $this->connections[$id]['writer']->flushBlocking();
        $this->drop($id);
    }

    private function drop(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if (null === $connection) {
            // Its writer failed while flushing a goodbye — a 401, a close —
            // and dropped it already: a peer gone between two writes, which
            // no test can time.
            // @codeCoverageIgnoreStart
            return;

            // @codeCoverageIgnoreEnd
        }
        unset($this->connections[$id]);
        EventLoop::cancel($connection['watcher']);
        EventLoop::cancel($connection['deadline']);
        $connection['writer']->close();
        \fclose($connection['stream']);
        $connection['session']?->close();
    }
}
