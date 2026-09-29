<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Server;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Server\Session;
use Porta\McpPlugin\Server\ToolCatalog;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

/** The JSON-RPC conversation, one line at a time, against a catalog of stub tools. */
final class SessionTest extends TestCase
{
    use PumpsLoop;

    /** The protocol's field name — a request's handle for its progress, nothing secret. */
    // @mago-expect lint:no-literal-password -- the MCP field NAME `progressToken`, not a credential
    private const string PROGRESS_TOKEN = 'progressToken';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @param array<string, \Closure(array<string, mixed>): (string|array<array-key, mixed>)> $handlers by tool id, all of the mcp plugin */
    private function session(array $handlers = [], float $heartbeat = Session::HEARTBEAT): Session
    {
        $tools = [];
        foreach ($handlers as $id => $handler) {
            $tools[] = ['plugin' => 'mcp', 'id' => $id, 'description' => 'the '.$id.' tool', 'inputSchema' => ['type' => 'object'], 'handler' => $handler];
        }

        return new Session(
            function (array $message): void {
                $this->sent[] = $message;
            },
            new ToolCatalog(static fn (): array => $tools, 'mcp', static fn (): array => []),
            '9.9.9',
            $heartbeat,
        );
    }

    /** @param array<string, mixed> $message */
    private static function line(array $message): string
    {
        return \json_encode(['jsonrpc' => '2.0', ...$message], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        $this->assertNotSame([], $this->sent, 'the session answered');

        return \array_last($this->sent);
    }

    private function assertError(int $code, int|string|null $id = null): void
    {
        $answer = $this->last();
        $this->assertSame($id, $answer['id']);
        $this->assertIsArray($answer['error']);
        $this->assertSame($code, $answer['error']['code']);
    }

    public function testInitializeEchoesASupportedVersionAndAdvertisesTools(): void
    {
        $this->session()->receive(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));

        $answer = $this->last();
        $this->assertSame(1, $answer['id']);
        $this->assertIsArray($answer['result']);
        $this->assertSame('2025-06-18', $answer['result']['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => true]], $answer['result']['capabilities']);
        $this->assertSame(['name' => 'porta', 'title' => 'Porta', 'version' => '9.9.9'], $answer['result']['serverInfo']);
        $this->assertStringContainsString('1-based', $answer['result']['instructions']);
    }

    public function testAnUnknownVersionIsAnsweredWithTheNewest(): void
    {
        $this->session()->receive(self::line(['id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]));

        $answer = $this->last();
        $this->assertIsArray($answer['result']);
        $this->assertSame(Session::PROTOCOL_VERSIONS[0], $answer['result']['protocolVersion']);
    }

    public function testPingAnswersAnEmptyObject(): void
    {
        $this->session()->receive(self::line(['id' => 7, 'method' => 'ping']));

        $this->assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', \json_encode($this->last()));
    }

    public function testToolsListNamesEveryTool(): void
    {
        $this->session(['context' => static fn (array $a): string => ''])->receive(self::line(['id' => 2, 'method' => 'tools/list']));

        $answer = $this->last();
        $this->assertSame(['tools' => [['name' => 'context', 'description' => 'the context tool', 'inputSchema' => ['type' => 'object']]]], $answer['result']);
    }

    public function testAToolCallAnswersItsStringAsText(): void
    {
        $session = $this->session(['echo' => static fn (array $arguments): string => 'got '.$arguments['word']]);

        $session->receive(self::line(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['word' => 'hi']]]));

        $this->assertSame(['content' => [['type' => 'text', 'text' => 'got hi']], 'isError' => false], $this->last()['result']);
    }

    public function testAnArrayAnswerIsSentAsJsonTextAndMissingArgumentsAreAnEmptyObject(): void
    {
        $session = $this->session(['count' => static fn (array $arguments): array => ['arguments' => \count($arguments), 'path' => 'a/b']]);

        $session->receive(self::line(['id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'count']]));

        $result = $this->last()['result'];
        $this->assertIsArray($result);
        $this->assertFalse($result['isError']);
        $this->assertSame("{\n    \"arguments\": 0,\n    \"path\": \"a/b\"\n}", $result['content'][0]['text']);
    }

    public function testAThrowingToolIsAnErrorResultTheCallerReads(): void
    {
        $session = $this->session(['boom' => static fn (array $arguments): string => throw new \RuntimeException('no such file')]);

        $session->receive(self::line(['id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'boom', 'arguments' => []]]));

        $this->assertSame(['content' => [['type' => 'text', 'text' => 'no such file']], 'isError' => true], $this->last()['result']);
    }

    public function testAToolThatSuspendsAnswersWhenItResumesAndDoesNotHoldUpTheNextCall(): void
    {
        $session = $this->session([
            'slow' => static function (array $arguments): string {
                $suspension = EventLoop::getSuspension();
                EventLoop::delay(0.05, static fn () => $suspension->resume('slow done'));

                return $suspension->suspend();
            },
            'fast' => static fn (array $arguments): string => 'fast done',
        ]);

        $session->receive(self::line(['id' => 'slow', 'method' => 'tools/call', 'params' => ['name' => 'slow']]));
        $this->assertSame([], $this->sent, 'the slow call has not answered yet');
        $session->receive(self::line(['id' => 'fast', 'method' => 'tools/call', 'params' => ['name' => 'fast']]));
        $this->assertSame('fast', $this->last()['id'], 'the second call answered while the first waits');

        $this->await(fn (): bool => 2 === \count($this->sent), what: 'the suspended call to answer');
        $this->assertSame('slow', $this->last()['id']);
        $this->assertSame([['type' => 'text', 'text' => 'slow done']], $this->last()['result']['content']);
    }

    public function testAnUnknownToolIsInvalidParams(): void
    {
        $this->session()->receive(self::line(['id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nope']]));

        $this->assertError(Session::INVALID_PARAMS, 6);
        $this->assertSame('Unknown tool: nope', $this->last()['error']['message']);
    }

    public function testACallWithoutANameOrWithScalarArgumentsIsInvalidParams(): void
    {
        $session = $this->session(['echo' => static fn (array $a): string => '']);

        $session->receive(self::line(['id' => 8, 'method' => 'tools/call', 'params' => ['arguments' => []]]));
        $this->assertError(Session::INVALID_PARAMS, 8);

        $session->receive(self::line(['id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => 'x']]));
        $this->assertError(Session::INVALID_PARAMS, 9);
    }

    public function testScalarParamsAreInvalidParams(): void
    {
        $this->session()->receive(self::line(['id' => 10, 'method' => 'ping', 'params' => 3]));

        $this->assertError(Session::INVALID_PARAMS, 10);
    }

    public function testAnUnknownMethodIsMethodNotFound(): void
    {
        $this->session()->receive(self::line(['id' => 11, 'method' => 'resources/list']));

        $this->assertError(Session::METHOD_NOT_FOUND, 11);
    }

    public function testMalformedJsonIsAParseErrorWithANullId(): void
    {
        $this->session()->receive('{"jsonrpc":');

        $this->assertError(Session::PARSE_ERROR);
    }

    public function testABatchOrAScalarIsAnInvalidRequest(): void
    {
        $session = $this->session();

        $session->receive('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
        $this->assertError(Session::INVALID_REQUEST);

        $session->receive('42');
        $this->assertError(Session::INVALID_REQUEST);
    }

    public function testARequestWithABadIdIsAnInvalidRequest(): void
    {
        $this->session()->receive(self::line(['id' => ['x'], 'method' => 'ping']));

        $this->assertError(Session::INVALID_REQUEST);
    }

    public function testARequestWithoutAMethodIsAnsweredButAResponseIsNot(): void
    {
        $session = $this->session();

        $session->receive(self::line(['id' => 12, 'result' => []]));
        $session->receive(self::line(['id' => 13, 'error' => ['code' => 1, 'message' => 'x']]));
        $session->receive(self::line([]));
        $this->assertSame([], $this->sent, 'responses and id-less garbage are not answered');

        $session->receive(self::line(['id' => 14]));
        $this->assertError(Session::INVALID_REQUEST, 14);

        $session->receive(self::line(['id' => 3.5]));
        $this->assertError(Session::INVALID_REQUEST);
    }

    public function testNotificationsAreNeverAnswered(): void
    {
        $session = $this->session();

        $session->receive(self::line(['method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]));
        $session->receive(self::line(['method' => 'ping']));

        $this->assertSame([], $this->sent);
    }

    public function testListChangedIsSentOnlyOnceTheClientIsInitialized(): void
    {
        $session = $this->session();

        $session->toolsChanged();
        $this->assertSame([], $this->sent, 'the client has not finished its handshake');

        $session->receive(self::line(['method' => 'notifications/initialized']));
        $session->toolsChanged();

        $this->assertSame([['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']], $this->sent);
    }

    /** A tool that answers when the test says so, and the way to say it. */
    private static function waiting(?\Closure &$release): \Closure
    {
        return static function (array $arguments) use (&$release): string {
            $suspension = EventLoop::getSuspension();
            $release = static fn () => $suspension->resume('done');

            return $suspension->suspend();
        };
    }

    public function testACancelledCallIsNeverAnsweredAndTheOthersAre(): void
    {
        $release = null;
        $session = $this->session(['wait' => self::waiting($release), 'fast' => static fn (array $a): string => 'fast']);

        $session->receive(self::line(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wait']]));
        $session->receive(self::line(['method' => 'notifications/cancelled', 'params' => ['requestId' => 1, 'reason' => 'user pressed Esc']]));
        $session->receive(self::line(['method' => 'notifications/cancelled', 'params' => ['requestId' => 'never-sent']]));
        $session->receive(self::line(['method' => 'notifications/cancelled']));
        $this->assertInstanceOf(\Closure::class, $release);
        $release();
        $this->pump(0.02); // the resumed fiber runs on the loop

        $this->assertSame([], $this->sent, 'the cancelled call finished, and nothing was said');
    }

    public function testCancellingTheString1LeavesTheInteger1Alone(): void
    {
        $release = null;
        $session = $this->session(['wait' => self::waiting($release)]);

        $session->receive(self::line(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wait']]));
        $session->receive(self::line(['method' => 'notifications/cancelled', 'params' => ['requestId' => '1']]));
        $this->assertInstanceOf(\Closure::class, $release);
        $release();

        $this->await(fn (): bool => [] !== $this->sent && 1 === ($this->last()['id'] ?? null), what: 'the integer call still answered');
    }

    public function testACallWithAProgressTokenIsToldAboutUntilItAnswers(): void
    {
        $release = null;
        $session = $this->session(['wait' => self::waiting($release)], heartbeat: 0.01);

        $session->receive(self::line(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wait', '_meta' => [self::PROGRESS_TOKEN => 7]]]));
        $this->await(fn (): bool => \count($this->sent) >= 2, what: 'two heartbeats');
        $this->assertSame(
            [
                ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [self::PROGRESS_TOKEN => 7, 'progress' => 1, 'message' => 'wait is still running']],
                ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [self::PROGRESS_TOKEN => 7, 'progress' => 2, 'message' => 'wait is still running']],
            ],
            \array_slice($this->sent, offset: 0, length: 2),
            'progress only ever grows',
        );

        $this->assertInstanceOf(\Closure::class, $release);
        $release();
        $this->await(fn (): bool => 1 === ($this->last()['id'] ?? null), what: 'the answer');
        $answered = \count($this->sent);
        $this->pump(0.05);
        $this->assertCount($answered, $this->sent, 'no beat after the answer');
    }

    public function testWithoutAProgressTokenThereIsNoHeartbeat(): void
    {
        $release = null;
        $session = $this->session(['wait' => self::waiting($release)], heartbeat: 0.01);

        $session->receive(self::line(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wait', '_meta' => ['progressToken' => 1.5]]]));
        $this->pump(0.05);

        $this->assertSame([], $this->sent, 'a token that is neither a string nor an integer is no token');
        $this->assertInstanceOf(\Closure::class, $release);
        $release();
    }
}
