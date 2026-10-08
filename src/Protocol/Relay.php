<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Protocol;

use Porta\McpPlugin\Server\Session;

/**
 * The little of the protocol `porta mcp` has to know so that its client
 * outlives the IDE: the client connects ONCE, when its session starts, and
 * the IDE comes and goes.
 *
 * - While no IDE is up, the bridge answers for it: `initialize` and `ping`
 *   as a server with no tools yet, any other request with an error saying
 *   what to open.
 * - When an IDE comes up after the client's handshake — its first, or a
 *   restarted one — that IDE's fresh {@see Session} is handed the client's
 *   own `initialize` under a private id, its answer is swallowed, and the
 *   client is told the tool list changed, so it lists the new one.
 * - When the IDE goes, every call it had not answered is answered with an
 *   error; the client would otherwise wait on it forever.
 *
 * Lines in, lines out: the bridge moves the bytes, this decides what they say.
 */
final class Relay
{
    /** The id the replayed `initialize` travels under — no client's. */
    public const string REPLAY_ID = 'porta-relay-reinit';

    /** @var array<string, mixed>|null the client's `initialize` params, once it sent them */
    private ?array $handshake = null;

    private bool $initialized = false;

    /** @var array<string, int|string> requests the IDE has not answered, keyed like {@see Session} keys a call */
    private array $pending = [];

    /** @var array<string, true> which of $pending is an `initialize` */
    private array $initializing = [];

    public function __construct(
        private readonly string $root,
        private readonly string $version,
    ) {}

    /** A client line on its way to the IDE: noted, so the IDE going can be answered for. */
    public function toIde(string $line): void
    {
        $message = self::decode($line);
        $method = \is_string($message['method'] ?? null) ? $message['method'] : null;
        $id = self::validId($message['id'] ?? null);
        if ('initialize' === $method) {
            $this->handshake = self::params($message);
        }
        if ('notifications/initialized' === $method) {
            $this->initialized = true;
        }
        if (null !== $method && null !== $id) {
            $this->pending[self::key($id)] = $id;
            if ('initialize' === $method) {
                $this->initializing[self::key($id)] = true;
            }
        }
    }

    /** An IDE line: false when it is the answer to the replay, which is not the client's. */
    public function fromIde(string $line): bool
    {
        $message = self::decode($line);
        $id = self::validId($message['id'] ?? null);
        if (null === $id || \array_key_exists('method', $message)) {
            return true;
        }
        if (self::REPLAY_ID === $id) {
            return false;
        }
        unset($this->pending[self::key($id)], $this->initializing[self::key($id)]);

        return true;
    }

    /**
     * A client line while no IDE is up: the answer to send back, or null
     * when it is a notification, which is owed none.
     */
    public function offline(string $line): ?string
    {
        if (null === self::decodable($line)) {
            return self::error(null, Session::PARSE_ERROR, 'Parse error');
        }
        $message = self::decode($line);
        $method = \is_string($message['method'] ?? null) ? $message['method'] : null;
        $id = self::validId($message['id'] ?? null);
        if ('notifications/initialized' === $method) {
            $this->initialized = true;
        }
        if (null === $method || null === $id) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->answerInitialize($id, self::params($message)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => []]),
            default => self::error($id, Session::INVALID_REQUEST, $this->absent()),
        };
    }

    /**
     * The IDE went: an answer for every call it still owed. An `initialize`
     * it never answered is answered here, so the client's handshake survives.
     *
     * @return list<string>
     */
    public function lost(): array
    {
        $answers = [];
        foreach ($this->pending as $key => $id) {
            $answers[] = \array_key_exists($key, $this->initializing)
                ? $this->answerInitialize($id, $this->handshake ?? [])
                : self::error($id, Session::INVALID_REQUEST, 'Porta closed while this call ran — it may have done part of it');
        }
        $this->pending = [];
        $this->initializing = [];

        return $answers;
    }

    /**
     * An IDE came up: what to tell it, and what to tell the client, so that
     * it serves a client which shook hands before it was there. Nothing
     * either way when the client has not shaken hands yet — its own
     * `initialize` will reach this IDE.
     *
     * @return array{ide: list<string>, client: list<string>}
     */
    public function resume(): array
    {
        if (null === $this->handshake) {
            return ['ide' => [], 'client' => []];
        }
        $ide = [self::encode(['jsonrpc' => '2.0', 'id' => self::REPLAY_ID, 'method' => 'initialize', 'params' => $this->handshake])];
        if ($this->initialized) {
            $ide[] = self::encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        }

        return ['ide' => $ide, 'client' => [self::encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed'])]];
    }

    /** Why a call cannot be served right now. */
    public function absent(): string
    {
        return \sprintf('Porta is not open on %s — open it there with the mcp plugin enabled; its tools appear here once it is', $this->root);
    }

    /** @param array<string, mixed> $params */
    private function answerInitialize(int|string $id, array $params): string
    {
        $this->handshake = $params;
        $asked = \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

        return self::result($id, [
            'protocolVersion' => \in_array($asked, Session::PROTOCOL_VERSIONS, strict: true) ? $asked : Session::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'porta', 'title' => 'Porta', 'version' => $this->version],
            'instructions' => $this->absent(),
        ]);
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function params(array $message): array
    {
        /** @var array<string, mixed> */
        return \is_array($message['params'] ?? null) ? $message['params'] : [];
    }

    /** @return array<array-key, mixed> the message, or [] when the line is not a JSON object */
    private static function decode(string $line): array
    {
        return self::decodable($line) ?? [];
    }

    /** @return array<array-key, mixed>|null */
    private static function decodable(string $line): ?array
    {
        // @mago-expect analysis:mixed-assignment -- decoded JSON is untyped; only an array is kept
        // @noinspection JsonEncodingApiUsageInspection -- a peer's line that is not JSON is dropped, not thrown: it comes off the network
        $message = \json_decode($line, associative: true);

        return \is_array($message) ? $message : null;
    }

    private static function validId(mixed $id): int|string|null
    {
        return \is_int($id) || \is_string($id) ? $id : null;
    }

    private static function key(int|string $id): string
    {
        return \get_debug_type($id).':'.$id;
    }

    /** @param array<string, mixed>|\stdClass $result */
    private static function result(int|string $id, array|\stdClass $result): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private static function error(int|string|null $id, int $code, string $message): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param array<string, mixed> $message */
    private static function encode(array $message): string
    {
        return \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
