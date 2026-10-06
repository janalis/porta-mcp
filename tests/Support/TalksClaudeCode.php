<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Support;

use Porta\McpPlugin\Ide\WebSocketFrames;

/**
 * A Claude Code CLI, as far as the IDE can tell, for an E2e: it finds the
 * lock file, upgrades with the token, speaks MCP in masked text frames and
 * reads the IDE's frames back — pumping the loop the IDE runs on while it
 * waits.
 */
trait TalksClaudeCode
{
    /** Satisfied by {@see \Porta\Tests\Support\PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /** @var resource|null */
    private $cli;

    private ?WebSocketFrames $cliFrames = null;

    private int $cliId = 0;

    /** @var list<array{0: int, 1: string}> frames read but not yet asked for */
    private array $cliInbox = [];

    /** @return array{port: int, token: string, folders: list<string>} the one lock file in $configDir */
    private function lock(string $configDir): array
    {
        $locks = (array) \glob($configDir.'/ide/*.lock');
        $this->assertCount(1, $locks, 'one IDE announced');
        /** @var array{workspaceFolders: list<string>, authToken: string} $lock */
        $lock = \json_decode((string) \file_get_contents((string) $locks[0]), associative: true, flags: \JSON_THROW_ON_ERROR);

        return ['port' => (int) \basename((string) $locks[0], suffix: '.lock'), 'token' => $lock['authToken'], 'folders' => $lock['workspaceFolders']];
    }

    /**
     * Open a TCP connection and send $request; the IDE's answer until its
     * headers end, or until it hangs up.
     *
     * @return array{0: resource, 1: string}
     */
    private function upgrade(int $port, string $request): array
    {
        $stream = \stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 1.0);
        if (false === $stream) {
            self::fail('the IDE server accepts the connection');
        }
        \stream_set_blocking($stream, enable: false);
        \fwrite($stream, $request);
        $answer = '';
        $this->await(static function () use ($stream, &$answer): bool {
            $answer .= (string) \fread($stream, length: 8192);

            return \str_contains($answer, "\r\n\r\n") || \feof($stream);
        }, what: 'the upgrade to be answered');

        return [$stream, $answer];
    }

    private static function upgradeRequest(int $port, #[\SensitiveParameter] string $token, string $extra = "Sec-WebSocket-Protocol: mcp\r\n"): string
    {
        return "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nx-claude-code-ide-authorization: {$token}\r\n{$extra}\r\n";
    }

    /** Connect as the CLI does and shake hands; the upgrade's answer. */
    private function connectCli(string $configDir): string
    {
        $lock = $this->lock($configDir);
        [$this->cli, $answer] = $this->upgrade($lock['port'], self::upgradeRequest($lock['port'], $lock['token']));
        $this->cliFrames = new WebSocketFrames();
        $this->cliInbox = [];
        $this->cliRequest('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'title' => 'Claude Code']]);
        $this->cliNotify('notifications/initialized');

        return $answer;
    }

    /** A client's frame: masked, as every client frame is. */
    private static function clientFrame(string $payload, int $opcode = WebSocketFrames::TEXT): string
    {
        $length = \strlen($payload);
        $head = \chr(0x80 | $opcode).match (true) {
            $length < 126 => \chr(0x80 | $length),
            $length < 65_536 => \chr(0x80 | 126).\pack('n', $length),
            default => \chr(0x80 | 127).\pack('J', $length),
        };

        return $head.'mask'.($payload ^ \str_repeat('mask', times: \max(1, \intdiv($length + 3, num2: 4))));
    }

    private function cliWrite(string $bytes): void
    {
        $this->assertIsResource($this->cli);
        \fwrite($this->cli, $bytes);
    }

    /** @param array<string, mixed> $params */
    private function cliNotify(string $method, array $params = []): void
    {
        $this->cliWrite(self::clientFrame(\json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the answer to it
     */
    private function cliRequest(string $method, array $params = []): array
    {
        $id = ++$this->cliId;
        $this->cliWrite(self::clientFrame(\json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR)));

        return $this->cliAwait(static fn (array $message): bool => $id === ($message['id'] ?? null), 'the answer to '.$method);
    }

    /**
     * A tool's answer, decoded when it is JSON.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: mixed, 1: bool} the answer, and whether it was an error
     */
    private function cliCall(string $tool, array $arguments = []): array
    {
        $answer = $this->cliRequest('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        /** @var array{content: list<array{text: string}>, isError: bool} $result */
        $result = $answer['result'];
        $text = $result['content'][0]['text'];
        // @mago-expect analysis:mixed-assignment -- a tool's JSON is untyped; the tests read it by key
        $decoded = \json_decode($text, associative: true);

        return [$decoded ?? $text, $result['isError']];
    }

    /**
     * The next text message $matches accepts — earlier ones are skipped.
     *
     * @param \Closure(array<string, mixed>): bool $matches
     *
     * @return array<string, mixed>
     */
    private function cliAwait(\Closure $matches, string $what): array
    {
        $found = null;
        $this->await(
            function () use ($matches, &$found): bool {
                $this->cliPump();
                while ([] !== $this->cliInbox) {
                    [$opcode, $payload] = \array_shift($this->cliInbox);
                    if (WebSocketFrames::TEXT !== $opcode) {
                        continue;
                    }
                    /** @var array<string, mixed> $message */
                    $message = \json_decode($payload, associative: true, flags: \JSON_THROW_ON_ERROR);
                    if ($matches($message)) {
                        $found = $message;

                        return true;
                    }
                }

                return false;
            },
            what: $what,
        );
        \assert(\is_array($found), description: 'await() returned, so the message was found');

        return $found;
    }

    /** The next frame of $opcode, control frames included. */
    private function cliAwaitFrame(int $opcode, string $what): string
    {
        $found = null;
        $this->await(function () use ($opcode, &$found): bool {
            $this->cliPump();
            while ([] !== $this->cliInbox) {
                [$got, $payload] = \array_shift($this->cliInbox);
                if ($opcode === $got) {
                    $found = $payload;

                    return true;
                }
            }

            return false;
        }, what: $what);

        return (string) $found;
    }

    /** Whether the IDE has hung up on the client. */
    private function cliClosed(): bool
    {
        $this->assertIsResource($this->cli);
        $this->cliPump();

        return \feof($this->cli);
    }

    private function cliPump(): void
    {
        $this->assertIsResource($this->cli);
        \assert(null !== $this->cliFrames, description: 'connectCli() or the test set the frame reader');
        $bytes = (string) \fread($this->cli, length: 65_536);
        if ('' !== $bytes) {
            $this->cliInbox = [...$this->cliInbox, ...$this->cliFrames->feed($bytes)];
        }
    }
}
