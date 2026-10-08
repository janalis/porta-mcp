<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Support;

use Porta\Tests\Support\TempProject;

/**
 * An MCP client for an E2e: connect to the socket the plugin opened in a
 * running IDE, do the handshake, call tools and read what they answered —
 * pumping the loop the IDE runs on while it waits, so the server can answer.
 *
 * @noinspection StaticInvocationViaThisInspection -- assertIsResource() is PHPUnit's, redeclared abstract so mago narrows through it; custos cannot see that
 */
trait TalksMcp
{
    /** Satisfied by {@see \Porta\Tests\Support\PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /**
     * Satisfied by PHPUnit's Assert on the composing class — declared so the
     * guards below narrow where an analyser reads the trait alone.
     */
    abstract public static function fail(string $message = ''): never;

    /** @phpstan-assert resource $actual */
    abstract public static function assertIsResource(mixed $actual, string $message = ''): void;

    /** @var resource|null */
    private $client;

    private int $nextId = 0;

    private string $received = '';

    private function connect(TempProject $project): void
    {
        $client = \stream_socket_client('unix://'.$project->path('.porta/mcp.sock'), timeout: 1.0);
        if (false === $client) {
            self::fail('the plugin listens once the IDE is up');
        }
        \stream_set_blocking($client, enable: false);
        $this->client = $client;
        $this->request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        \fwrite($client, '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n");
    }

    /**
     * Send one request and pump the loop until its answer arrives.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        return $this->answer($this->send($method, $params));
    }

    /**
     * Send a request WITHOUT waiting for it — for calls that must overlap —
     * and return its id for {@see answer()}.
     *
     * @param array<string, mixed> $params
     */
    private function send(string $method, array $params = []): int
    {
        $this->assertIsResource($this->client);
        $id = ++$this->nextId;
        \fwrite($this->client, \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR)."\n");

        return $id;
    }

    /**
     * Pump the loop until the answer to request $id arrives.
     *
     * @return array<string, mixed>
     */
    private function answer(int $id): array
    {
        return $this->awaitMessage(static fn (array $message): bool => $id === ($message['id'] ?? null), 'the answer to #'.$id);
    }

    /**
     * @param \Closure(array<string, mixed>): bool $matches
     *
     * @return array<string, mixed>
     */
    private function awaitMessage(\Closure $matches, string $what): array
    {
        $this->assertIsResource($this->client);
        $client = $this->client;
        $found = null;
        $this->await(function () use ($client, $matches, &$found): bool {
            $chunk = \fread($client, length: 65_536);
            $this->received .= false === $chunk ? '' : $chunk;
            while (false !== ($end = \strpos($this->received, needle: "\n"))) {
                $line = \substr($this->received, offset: 0, length: $end);
                $this->received = \substr($this->received, $end + 1);
                $message = \json_decode($line, associative: true);
                if (\is_array($message) && $matches($message)) {
                    $found = $message;

                    return true;
                }
            }

            return false;
        }, what: $what);

        /** @var array<string, mixed> $found */
        return $found;
    }

    /**
     * Call a tool; its text, and whether it was an error.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: bool}
     */
    private function call(string $tool, array $arguments = []): array
    {
        $answer = $this->request('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $this->assertArrayHasKey('result', $answer, $tool.' answered with a result, not a protocol error');
        /** @var array{content: list<array{text: string}>, isError: bool} $result */
        $result = $answer['result'];

        return [$result['content'][0]['text'], $result['isError']];
    }

    /** @param array<string, mixed> $arguments */
    private function ok(string $tool, array $arguments = []): string
    {
        [$text, $error] = $this->call($tool, $arguments);
        $this->assertFalse($error, $tool.' failed: '.$text);

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function json(string $tool, array $arguments = []): array
    {
        $decoded = \json_decode($this->ok($tool, $arguments), associative: true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $arguments */
    private function refused(string $tool, array $arguments, string $says): void
    {
        [$text, $error] = $this->call($tool, $arguments);
        $this->assertTrue($error, $tool.' should have refused, and answered: '.$text);
        $this->assertStringContainsString($says, $text);
    }
}
