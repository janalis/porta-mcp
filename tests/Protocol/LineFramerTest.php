<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Protocol\LineFramer;

final class LineFramerTest extends TestCase
{
    /** @var list<string> */
    private array $overflows = [];

    private function framer(int $max = LineFramer::MAX_LINE): LineFramer
    {
        return new LineFramer(function (string $reason): void {
            $this->overflows[] = $reason;
        }, $max);
    }

    public function testALineSplitAcrossReadsIsHandedBackWhole(): void
    {
        $framer = $this->framer();

        $this->assertSame([], $framer->feed('{"a":'));
        $this->assertSame(['{"a":1}'], $framer->feed("1}\n"));
    }

    public function testSeveralLinesInOneReadComeBackInOrderAndBlankOnesAreSkipped(): void
    {
        $framer = $this->framer();

        $this->assertSame(['one', 'two', 'three'], $framer->feed("one\n\n  \r\ntwo\r\nthree\npartial"));
        $this->assertSame(['partial'], $framer->feed("\n"));
    }

    public function testAnOversizedLineIsDroppedUpToItsNewlineAndTheNextOneStillArrives(): void
    {
        $framer = $this->framer(8);

        $this->assertSame([], $framer->feed('0123456789'));
        $this->assertCount(1, $this->overflows);
        $this->assertStringContainsString('8 bytes', $this->overflows[0]);
        // The rest of the oversized line is still coming: it must not surface
        // as a message of its own.
        $this->assertSame(['next'], $framer->feed("tail\nnext\n"));
    }

    public function testALineAtTheCapIsKept(): void
    {
        $framer = $this->framer(8);

        $this->assertSame([], $framer->feed('01234567'));
        $this->assertSame([], $this->overflows);
        $this->assertSame(['01234567'], $framer->feed("\n"));
    }
}
