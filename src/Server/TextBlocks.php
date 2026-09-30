<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Server;

/**
 * A tool answer of several text blocks, sent as they are — for a protocol
 * that reads its answer by position (Claude Code's `openDiff`: a verdict,
 * then the text) rather than as one string or one JSON value.
 */
final readonly class TextBlocks
{
    /** @param list<string> $texts */
    public function __construct(
        public array $texts,
    ) {}
}
