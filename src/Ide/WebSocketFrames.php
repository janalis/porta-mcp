<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

/**
 * The little of RFC 6455 a local server needs: the upgrade's accept key, a
 * server's frames out (never masked), a client's frames in (always masked),
 * split or batched arbitrarily, fragmented messages joined.
 *
 * Bytes in, messages out. A frame past the cap is not a message this server
 * is for, and reads as the peer to hang up on: {@see feed()} throws.
 */
final class WebSocketFrames
{
    public const int TEXT = 0x1;
    public const int BINARY = 0x2;
    public const int CLOSE = 0x8;
    public const int PING = 0x9;
    public const int PONG = 0xA;

    /** Far past any request an agent sends; a frame larger is not one. */
    public const int MAX_MESSAGE = 16_777_216;

    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private string $buffer = '';

    /** The opcode and payload so far of a message arriving in fragments. */
    private ?int $fragmentOpcode = null;

    private string $fragments = '';

    public function __construct(
        private readonly int $maxMessage = self::MAX_MESSAGE,
    ) {}

    /** The `Sec-WebSocket-Accept` that answers a client's `Sec-WebSocket-Key`. */
    public static function accept(string $key): string
    {
        return \base64_encode(\sha1($key.self::GUID, binary: true));
    }

    /** One unfragmented, unmasked frame — what a server sends. */
    public static function encode(string $payload, int $opcode = self::TEXT): string
    {
        $length = \strlen($payload);
        $head = \chr(0x80 | $opcode);

        return match (true) {
            $length < 126 => $head.\chr($length),
            $length < 65_536 => $head.\chr(126).\pack('n', $length),
            default => $head.\chr(127).\pack('J', $length),
        }.$payload;
    }

    /**
     * The whole messages now available, each as [opcode, payload] — a
     * control frame as it comes, a data message once its last fragment has.
     *
     * @return list<array{0: int, 1: string}>
     *
     * @throws \LengthException a frame, or a message, past the cap
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];
        while (null !== ($frame = $this->next())) {
            [$final, $opcode, $payload] = $frame;
            if ($opcode >= self::CLOSE) {
                $messages[] = [$opcode, $payload];

                continue;
            }
            $this->fragmentOpcode ??= $opcode;
            $this->fragments .= $payload;
            if (\strlen($this->fragments) > $this->maxMessage) {
                throw new \LengthException(\sprintf('a message past %d bytes', $this->maxMessage));
            }
            if ($final) {
                $messages[] = [$this->fragmentOpcode, $this->fragments];
                $this->fragmentOpcode = null;
                $this->fragments = '';
            }
        }

        return $messages;
    }

    /** @return array{0: bool, 1: int, 2: string}|null the next whole frame — final bit, opcode, unmasked payload — or null until it has arrived */
    private function next(): ?array
    {
        $header = $this->header();
        if (null !== $header && $header[1] > $this->maxMessage) {
            throw new \LengthException(\sprintf('a frame past %d bytes', $this->maxMessage));
        }

        return null === $header ? null : $this->payload($header[0], $header[1]);
    }

    /** @return array{int, int}|null where the next frame's key or payload starts, and the payload's length — null until the buffer has that much */
    private function header(): ?array
    {
        if (\strlen($this->buffer) < 2) {
            return null;
        }
        $short = \ord($this->buffer[1]) & 0x7F;
        $offset = match ($short) {
            126 => 4,
            127 => 10,
            default => 2,
        };
        $length = match (true) {
            \strlen($this->buffer) < $offset => null,
            126 === $short => (int) \unpack('n', $this->buffer, offset: 2)[1],
            127 === $short => (int) \unpack('J', $this->buffer, offset: 2)[1],
            default => $short,
        };

        return null === $length ? null : [$offset, $length];
    }

    /** @return array{0: bool, 1: int, 2: string}|null the frame whose header ends at $offset, taken out of the buffer — null until it has all arrived */
    private function payload(int $offset, int $length): ?array
    {
        $first = \ord($this->buffer[0]);
        $masked = 0 !== (\ord($this->buffer[1]) & 0x80);
        $key = $masked ? \substr($this->buffer, $offset, length: 4) : '';
        $offset += $masked ? 4 : 0;
        if (\strlen($this->buffer) < ($offset + $length)) {
            return null;
        }
        $payload = \substr($this->buffer, $offset, $length);
        $this->buffer = \substr($this->buffer, $offset + $length);
        if ($masked) {
            $payload ^= \str_repeat($key, times: \max(1, \intdiv($length + 3, num2: 4)));
            $payload = \substr($payload, offset: 0, length: $length);
        }

        return [0 !== ($first & 0x80), $first & 0x0F, $payload];
    }
}
