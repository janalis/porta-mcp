<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Ide;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Ide\WebSocketFrames;

/** RFC 6455's server half: the accept key, frames out, a client's masked frames in however they arrive. */
final class WebSocketFramesTest extends TestCase
{
    /** A client's frame: always masked, as the RFC requires of one. */
    // @mago-expect lint:no-boolean-flag-parameter -- the FIN bit IS a flag; a fragment and a whole frame differ by it alone
    private static function masked(string $payload, int $opcode = WebSocketFrames::TEXT, bool $final = true): string
    {
        $key = 'abcd';
        $length = \strlen($payload);
        $head = \chr(($final ? 0x80 : 0) | $opcode);
        $head .= match (true) {
            $length < 126 => \chr(0x80 | $length),
            $length < 65_536 => \chr(0x80 | 126).\pack('n', $length),
            default => \chr(0x80 | 127).\pack('J', $length),
        };

        return $head.$key.($payload ^ \str_repeat($key, times: \max(1, \intdiv($length + 3, num2: 4))));
    }

    public function testTheAcceptKeyIsTheRfcsOwnExample(): void
    {
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WebSocketFrames::accept('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    public function testAServerFrameCarriesItsLengthInTheShortestForm(): void
    {
        $this->assertSame("\x81\x02hi", WebSocketFrames::encode('hi'));
        $this->assertSame("\x8A\x00", WebSocketFrames::encode('', WebSocketFrames::PONG));
        $this->assertSame("\x81\x7E\x01\x00", \substr(WebSocketFrames::encode(\str_repeat('x', times: 256)), offset: 0, length: 4));
        $this->assertSame("\x81\x7F".\pack('J', 70_000), \substr(WebSocketFrames::encode(\str_repeat('x', times: 70_000)), offset: 0, length: 10));
        foreach ([125 => "\x81\x7D", 126 => "\x81\x7E\x00\x7E", 65_535 => "\x81\x7E\xFF\xFF", 65_536 => "\x81\x7F".\pack('J', 65_536)] as $length => $head) {
            $this->assertSame($head, \substr(WebSocketFrames::encode(\str_repeat('x', times: $length)), offset: 0, length: \strlen($head)), "{$length} bytes: each form up to its last length");
        }
    }

    public function testMaskedFramesArriveWholeHoweverTheyAreSplit(): void
    {
        $frames = new WebSocketFrames();
        $bytes = self::masked('{"id":1}').self::masked(\str_repeat('é', times: 100)).self::masked(\str_repeat('z', times: 70_000));

        $messages = [];
        foreach (\str_split($bytes, length: 7) as $chunk) {
            $messages = [...$messages, ...$frames->feed($chunk)];
        }

        $this->assertSame([[WebSocketFrames::TEXT, '{"id":1}'], [WebSocketFrames::TEXT, \str_repeat('é', times: 100)], [WebSocketFrames::TEXT, \str_repeat('z', times: 70_000)]], $messages);
        $this->assertSame([[WebSocketFrames::TEXT, '']], $frames->feed(self::masked('')), 'an empty message too');
        $this->assertSame([[WebSocketFrames::TEXT, 'plain']], $frames->feed("\x81\x05plain"), 'an unmasked frame is read as it is');
        $long = self::masked(\str_repeat('y', times: 300));
        $this->assertSame([], $frames->feed(\substr($long, offset: 0, length: 3)), 'an extended length still arriving');
        $this->assertSame([[WebSocketFrames::TEXT, \str_repeat('y', times: 300)]], $frames->feed(\substr($long, offset: 3)));
        $huge = self::masked(\str_repeat('w', times: 70_000));
        $this->assertSame([], $frames->feed(\substr($huge, offset: 0, length: 6)));
        $this->assertSame([[WebSocketFrames::TEXT, \str_repeat('w', times: 70_000)]], $frames->feed(\substr($huge, offset: 6)));
    }

    public function testFragmentsJoinAndAControlFrameMayCutBetweenThem(): void
    {
        $frames = new WebSocketFrames();

        $messages = $frames->feed(self::masked('{"a":', final: false).self::masked('', WebSocketFrames::PING).self::masked('1}', 0x0));

        $this->assertSame(
            [
                [WebSocketFrames::PING, ''],
                [WebSocketFrames::TEXT, '{"a":1}'],
            ],
            $messages,
        );
    }

    public function testACloseBetweenFragmentsIsAControlFrameNotAFragment(): void
    {
        $frames = new WebSocketFrames();

        $this->assertSame([[WebSocketFrames::CLOSE, "\x03\xE8"]], $frames->feed(self::masked('{"a":', final: false).self::masked("\x03\xE8", WebSocketFrames::CLOSE)));
    }

    public function testAFrameAndAMessageOfExactlyTheCapAreTaken(): void
    {
        $this->assertSame([[WebSocketFrames::TEXT, '0123456789']], new WebSocketFrames(maxMessage: 10)->feed(self::masked('0123456789')));

        $frames = new WebSocketFrames(maxMessage: 10);
        $this->assertSame([[WebSocketFrames::TEXT, '0123456789']], $frames->feed(self::masked('01234', final: false).self::masked('56789', 0x0)));
    }

    public function testAFrameOrAMessagePastTheCapIsRefused(): void
    {
        try {
            new WebSocketFrames(maxMessage: 10)->feed(self::masked(\str_repeat('x', times: 11)));
            $this->fail('a frame past the cap');
        } catch (\LengthException $refused) {
            $this->assertSame('a frame past 10 bytes', $refused->getMessage());
        }

        $frames = new WebSocketFrames(maxMessage: 10);
        $frames->feed(self::masked('123456', final: false));
        $this->expectExceptionMessageIsOrContains('a message past 10 bytes');
        $frames->feed(self::masked('789012', 0x0));
    }
}
