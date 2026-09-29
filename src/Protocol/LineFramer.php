<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Protocol;

/**
 * MCP's stdio framing: one JSON-RPC message per line, newline-terminated, no
 * embedded newlines. `feed()` takes bytes as they arrive — split or batched
 * arbitrarily — and hands back every complete line.
 *
 * Not {@see \Porta\Support\JsonRpcFramer}, which is Content-Length framing
 * (LSP and DAP): the two share only the rule that the read buffer is capped,
 * and a peer that never sends a newline would otherwise grow it without bound.
 * Past the cap the partial line is thrown away and everything up to the next
 * newline with it, so the stream resyncs on the message after.
 */
final class LineFramer
{
    /** Far past any real request; a line longer than this is not one. */
    public const int MAX_LINE = 16_777_216;

    private string $buffer = '';

    /** Set while an oversized line is being skipped, up to its newline. */
    private bool $skipping = false;

    /** @param \Closure(string): void $onOverflow told why a line was dropped */
    public function __construct(
        private readonly \Closure $onOverflow,
        private readonly int $maxLine = self::MAX_LINE,
    ) {}

    /** @return list<string> the complete lines now available, blank ones left out */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $lines = [];
        $offset = 0;
        while (false !== ($end = \strpos($this->buffer, needle: "\n", offset: $offset))) {
            $line = \trim(\substr($this->buffer, $offset, $end - $offset));
            $offset = $end + 1;
            if ($this->skipping) {
                $this->skipping = false;

                continue;
            }
            if ('' !== $line) {
                $lines[] = $line;
            }
        }
        $this->buffer = \substr($this->buffer, $offset);
        if (\strlen($this->buffer) > $this->maxLine) {
            ($this->onOverflow)(\sprintf('a line past %d bytes with no newline — dropped', $this->maxLine));
            $this->buffer = '';
            $this->skipping = true;
        }

        return $lines;
    }
}
