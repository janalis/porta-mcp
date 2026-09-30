<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Ide;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Ide\LineChange;

/** One edit over the lines that differ — and applying it gives exactly the new text. */
final class LineChangeTest extends TestCase
{
    /** Apply $change to $text the way the editor would. */
    private static function apply(string $text, LineChange $change): string
    {
        $lines = \explode("\n", $text);
        $offset = static fn (array $at): int => \strlen(\implode("\n", \array_slice($lines, offset: 0, length: $at[0]))) + ($at[0] > 0 ? 1 : 0) + $at[1];

        return \substr($text, offset: 0, length: $offset($change->start)).$change->text.\substr($text, $offset($change->end));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function changes(): iterable
    {
        yield 'a line changed' => ["a\nb\nc\n", "a\nX\nc\n", 1];
        yield 'a line added' => ["a\nc\n", "a\nb\nc\n", 1];
        yield 'a line removed' => ["a\nb\nc\n", "a\nc\n", 1];
        yield 'the last line changed, no final newline' => ["a\nb", "a\nB", 1];
        yield 'lines added at the end, no final newline' => ["a\nb", "a\nb\nc\nd", 2];
        yield 'the last line removed, no final newline' => ["a\nb", 'a', 1];
        yield 'everything, from the first line' => ['old', 'new', 0];
        yield 'an empty text filled' => ['', "<?php\n", 0];
        yield 'lines removed before the final newline' => ["<?php\n// one\n// two\n", "<?php\n", 1];
    }

    #[DataProvider('changes')]
    public function testTheEditTurnsTheOldTextIntoTheNewOne(string $old, string $new, int $first): void
    {
        $change = LineChange::between($old, $new);

        $this->assertInstanceOf(LineChange::class, $change);
        $this->assertSame($new, self::apply($old, $change));
        $this->assertSame($first, $change->first);
    }

    public function testTheSameTextIsNoEdit(): void
    {
        $this->assertNull(LineChange::between("a\nb\n", "a\nb\n"));
    }

    public function testOnlyTheChangedLinesAreInTheEdit(): void
    {
        $change = LineChange::between("1\n2\n3\n4\n5\n", "1\n2\nX\n4\n5\n");

        $this->assertInstanceOf(LineChange::class, $change);
        $this->assertSame([[2, 0], [3, 0], "X\n"], [$change->start, $change->end, $change->text]);
    }
}
