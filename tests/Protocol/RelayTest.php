<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Protocol\Relay;
use Porta\McpPlugin\Server\Session;

final class RelayTest extends TestCase
{
    private function relay(): Relay
    {
        return new Relay('/project', '9.9.9');
    }

    /** @return array<string, mixed> */
    private static function decode(?string $line): array
    {
        self::assertNotNull($line);

        /** @var array<string, mixed> */
        return \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $message */
    private static function line(array $message): string
    {
        return \json_encode(['jsonrpc' => '2.0', ...$message], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function testWithNoIdeItShakesHandsAsAServerWithNoToolsYet(): void
    {
        $relay = $this->relay();

        $answer = self::decode($relay->offline(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']])));

        $this->assertSame(['2.0', 1], [$answer['jsonrpc'] ?? null, $answer['id']]);
        $this->assertSame(
            [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => true]],
                'serverInfo' => ['name' => 'porta', 'title' => 'Porta', 'version' => '9.9.9'],
                'instructions' => $relay->absent(),
            ],
            $answer['result'],
        );
        $this->assertStringContainsString('/project', $relay->absent());
        $this->assertSame(['tools' => []], self::decode($relay->offline(self::line(['id' => 2, 'method' => 'tools/list'])))['result']);
        $this->assertSame([], self::decode($relay->offline(self::line(['id' => 'p', 'method' => 'ping'])))['result']);
    }

    public function testAVersionItDoesNotSpeakGetsTheNewest(): void
    {
        $answer = self::decode($this->relay()->offline(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])));

        $this->assertSame(Session::PROTOCOL_VERSIONS[0], $answer['result']['protocolVersion'] ?? null);
    }

    public function testWithNoIdeACallIsRefusedWithWhatToOpen(): void
    {
        $relay = $this->relay();

        $answer = self::decode($relay->offline(self::line(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'context']])));

        $this->assertSame(['code' => Session::INVALID_REQUEST, 'message' => $relay->absent()], $answer['error']);
        $this->assertSame('2.0', $answer['jsonrpc'] ?? null);
        $this->assertNull($relay->offline(self::line(['id' => 1.5, 'method' => 'tools/call'])), 'an id neither number nor string: a notification');
        $this->assertNull($relay->offline(self::line(['method' => 'notifications/cancelled'])), 'a notification is owed nothing');
        $this->assertSame(['code' => Session::PARSE_ERROR, 'message' => 'Parse error'], self::decode($relay->offline('{nope'))['error']);
    }

    public function testBeforeAnyHandshakeANewIdeIsToldNothing(): void
    {
        $this->assertSame(['ide' => [], 'client' => []], $this->relay()->resume());
    }

    public function testANewIdeIsHandedTheHandshakeTheClientMadeWithoutIt(): void
    {
        $relay = $this->relay();
        $relay->offline(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));

        $half = $relay->resume();
        $this->assertCount(1, $half['ide'], 'no initialized to replay before the client sent it');

        $relay->offline(self::line(['method' => 'notifications/initialized']));
        $resume = $relay->resume();

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => Relay::REPLAY_ID, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
            self::decode($resume['ide'][0]),
        );
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], self::decode($resume['ide'][1] ?? null));
        $this->assertSame([self::line(['method' => 'notifications/tools/list_changed'])], $resume['client']);
    }

    public function testTheReplaysAnswerIsNotTheClients(): void
    {
        $relay = $this->relay();

        $this->assertFalse($relay->fromIde(self::line(['id' => Relay::REPLAY_ID, 'result' => []])));
        $this->assertTrue($relay->fromIde(self::line(['id' => 1, 'result' => []])));
        $this->assertTrue($relay->fromIde(self::line(['method' => 'notifications/tools/list_changed'])));
        $this->assertTrue($relay->fromIde('not json'), 'what the bridge cannot read, the client may');
    }

    public function testAnIdeThatGoesIsAnsweredForOnlyWhatItStillOwed(): void
    {
        $relay = $this->relay();
        $relay->toIde(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));
        $relay->toIde(self::line(['method' => 'notifications/initialized']));
        $relay->toIde(self::line(['id' => 2, 'method' => 'tools/call']));
        $relay->toIde(self::line(['id' => '2', 'method' => 'tools/call']));
        $relay->fromIde(self::line(['id' => 1, 'result' => []]));
        $relay->fromIde(self::line(['id' => '2', 'result' => []]));

        $owed = \array_map(self::decode(...), $relay->lost());

        $this->assertCount(1, $owed, 'the string id "2" was answered; the integer 2 was not');
        $this->assertSame(2, $owed[0]['id']);
        $this->assertStringContainsString('Porta closed while this call ran', $owed[0]['error']['message'] ?? '');
        $this->assertSame([], $relay->lost(), 'each is answered once');
        $this->assertCount(2, $relay->resume()['ide'], 'the handshake and the initialized that went to the IDE are replayed');
    }

    public function testAHandshakeTheIdeNeverAnsweredIsAnsweredHere(): void
    {
        $relay = $this->relay();
        $relay->toIde(self::line(['id' => 7, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));

        $owed = \array_map(self::decode(...), $relay->lost());

        $this->assertSame(7, $owed[0]['id']);
        $this->assertSame('2025-06-18', $owed[0]['result']['protocolVersion'] ?? null);
    }

    public function testOnlyTheClientsOwnInitializedIsReplayedAndEveryOwedCallIsAnswered(): void
    {
        $relay = $this->relay();
        $relay->toIde(self::line(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));
        $relay->toIde(self::line(['id' => 2, 'method' => 'tools/call']));
        $relay->toIde(self::line(['id' => 3, 'method' => 'tools/call']));

        $owed = \array_map(self::decode(...), $relay->lost());

        $this->assertSame([1, 2, 3], \array_column($owed, 'id'));
        $this->assertSame(['2.0', '2.0', '2.0'], \array_column($owed, 'jsonrpc'));
        $this->assertCount(1, $relay->resume()['ide'], 'no initialized went to the IDE, so none is replayed');
    }
}
