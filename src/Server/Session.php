<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Server;

use Revolt\EventLoop;

/**
 * One client's conversation: JSON-RPC 2.0 in, the MCP server half of it out.
 *
 * Only what a tools server speaks — `initialize`, `ping`, `tools/list`,
 * `tools/call`, and the `notifications/tools/list_changed` it sends when the
 * set moves. Everything else a client may ask gets "method not found", which
 * is the protocol's own way of saying a capability was not advertised.
 *
 * A tool call runs in its own FIBER. The handler may then suspend on a Revolt
 * suspension until a subprocess answers, and the loop goes on painting the
 * IDE meanwhile; the answer is sent whenever it comes, so two calls may
 * overlap and answer out of order, which JSON-RPC's ids exist for.
 *
 * A call the client CANCELS (`notifications/cancelled`) is not answered: the
 * protocol asks exactly that, and the handler — a git process, a forge
 * request — cannot be stopped from here, only forgotten. A call that carries
 * a `progressToken` gets a `notifications/progress` every
 * {@see HEARTBEAT} seconds until it answers, so a client that resets its
 * timeout on progress does not give up on a slow forge.
 */
final class Session
{
    /** The versions this server can speak, newest first; a client asking for another gets the newest. */
    public const array PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** What a client may put in front of its model: the one thing about this server a tool list cannot say. */
    private const string INSTRUCTIONS = 'The Porta IDE the user has open on this project. Its buffers may hold unsaved edits the files on disk do not: read them with read_buffer. Lines and columns are 1-based.';

    public const int PARSE_ERROR = -32_700;
    public const int INVALID_REQUEST = -32_600;
    public const int METHOD_NOT_FOUND = -32_601;
    public const int INVALID_PARAMS = -32_602;

    /** Seconds between two progress notifications for a call still running. */
    public const float HEARTBEAT = 2.0;

    /** Until the client says `notifications/initialized`, nothing unsolicited is sent. */
    private bool $initialized = false;

    /** @var array<string, true> calls not yet answered, keyed by {@see key()} — a cancelled one leaves */
    private array $calls = [];

    /**
     * @param \Closure(array<string, mixed>): void $send      puts one message on the wire
     * @param float                                $heartbeat seconds between two progress notifications
     */
    public function __construct(
        private readonly \Closure $send,
        private readonly ToolCatalog $catalog,
        private readonly string $version,
        private readonly float $heartbeat = self::HEARTBEAT,
    ) {}

    /** One line off the wire. */
    public function receive(string $line): void
    {
        try {
            // @mago-expect analysis:mixed-assignment -- decoded JSON is untyped; the checks below narrow every part that is read
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->error(null, self::PARSE_ERROR, 'Parse error');

            return;
        }
        if (!\is_array($message) || \array_is_list($message)) {
            $this->error(null, self::INVALID_REQUEST, 'Expected one JSON-RPC message object');

            return;
        }
        $id = self::validId($message['id'] ?? null);
        $method = \is_string($message['method'] ?? null) ? $message['method'] : null;
        if (null === $method) {
            // A response to something this server never asks, or garbage: a
            // request with no method is the only one of those owed an answer.
            if (null !== ($message['id'] ?? null) && !\array_key_exists('result', $message) && !\array_key_exists('error', $message)) {
                $this->error($id, self::INVALID_REQUEST, 'A request needs a method');
            }

            return;
        }
        if (!\array_key_exists('id', $message)) {
            $this->notified($method, \is_array($message['params'] ?? null) ? $message['params'] : []);

            return;
        }
        if (null === $id) {
            $this->error(null, self::INVALID_REQUEST, 'A request id is a string or an integer');

            return;
        }
        if (null !== ($message['params'] ?? null) && !\is_array($message['params'])) {
            $this->error($id, self::INVALID_PARAMS, 'params must be an object');

            return;
        }
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->catalog->describe()]),
            'tools/call' => $this->call($id, $params),
            default => $this->error($id, self::METHOD_NOT_FOUND, 'Method not found: '.$method),
        };
    }

    /** The tool set moved: tell the client to list it again, once it is listening. */
    public function toolsChanged(): void
    {
        if ($this->initialized) {
            ($this->send)(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);
        }
    }

    /** @param array<array-key, mixed> $params */
    private function notified(string $method, array $params): void
    {
        if ('notifications/initialized' === $method) {
            $this->initialized = true;
        }
        $cancelled = self::validId($params['requestId'] ?? null);
        if ('notifications/cancelled' === $method && null !== $cancelled) {
            unset($this->calls[self::key($cancelled)]);
        }
    }

    /** A call's key: an id is a string OR an integer, and 1 and "1" are two calls. */
    private static function key(int|string $id): string
    {
        return \get_debug_type($id).':'.$id;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

        return [
            'protocolVersion' => \in_array($asked, self::PROTOCOL_VERSIONS, strict: true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'porta', 'title' => 'Porta', 'version' => $this->version],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /** @param array<array-key, mixed> $params */
    private function call(int|string $id, array $params): void
    {
        $name = \is_string($params['name'] ?? null) ? $params['name'] : null;
        if (null === $name || null !== ($params['arguments'] ?? null) && !\is_array($params['arguments'])) {
            $this->error($id, self::INVALID_PARAMS, 'tools/call takes a tool name and an arguments object');

            return;
        }
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $handler = $this->catalog->find($name);
        if (null === $handler) {
            $this->error($id, self::INVALID_PARAMS, 'Unknown tool: '.$name);

            return;
        }
        $key = self::key($id);
        $this->calls[$key] = true;
        $heartbeat = $this->heartbeat($params, $name);
        /** @var array<string, mixed> $arguments */
        $fiber = new \Fiber(function () use ($id, $key, $heartbeat, $handler, $arguments): void {
            $failed = false;
            try {
                $answer = $handler($arguments);
                $text = \is_string($answer)
                    ? $answer
                    : \json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (\Throwable $error) {
                // The tool's failure is the CALLER's to read, not a protocol
                // error: the model sees it and can correct its arguments.
                $text = $error->getMessage();
                $failed = true;
            }
            if (null !== $heartbeat) {
                EventLoop::cancel($heartbeat);
            }
            if (!\array_key_exists($key, $this->calls)) {
                return; // cancelled: the client no longer wants an answer
            }
            unset($this->calls[$key]);
            $this->result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $failed]);
        });
        $fiber->start();
    }

    /**
     * Start telling the client a call is still running, when it asked to be
     * told; the timer to cancel, or null when it did not ask.
     *
     * @param array<array-key, mixed> $params
     */
    private function heartbeat(array $params, string $tool): ?string
    {
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $token = \is_string($meta['progressToken'] ?? null) || \is_int($meta['progressToken'] ?? null) ? $meta['progressToken'] : null;
        if (null === $token) {
            return null;
        }
        $beat = 0;

        return EventLoop::unreference(EventLoop::repeat($this->heartbeat, function () use ($token, $tool, &$beat): void {
            ($this->send)([
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => $token, 'progress' => ++$beat, 'message' => $tool.' is still running'],
            ]);
        }));
    }

    private static function validId(mixed $id): int|string|null
    {
        return \is_int($id) || \is_string($id) ? $id : null;
    }

    /** @param array<string, mixed>|\stdClass $result */
    private function result(int|string $id, array|\stdClass $result): void
    {
        ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(int|string|null $id, int $code, string $message): void
    {
        ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
