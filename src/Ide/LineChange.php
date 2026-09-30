<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

/**
 * The one edit that turns a text into another over the lines that differ:
 * the unchanged lines at the head and the tail are left out, so the caret
 * and the undo step are about the change, not the file.
 *
 * Whole lines out, whole lines in; a change that runs to the end of the text
 * starts at the end of the last unchanged line, so the newline before it
 * goes or stays with it.
 */
final readonly class LineChange
{
    /**
     * @param array{int, int} $start 0-based line and byte column
     * @param array{int, int} $end   the same, exclusive
     * @param int             $first the first changed line
     */
    private function __construct(
        public array $start,
        public array $end,
        public string $text,
        public int $first,
    ) {}

    /** The edit from $from to $to, or null when they are the same text. */
    public static function between(string $from, string $to): ?self
    {
        $old = \explode("\n", $from);
        $new = \explode("\n", $to);
        $head = 0;
        while ($head < \count($old) && $head < \count($new) && $old[$head] === $new[$head]) {
            ++$head;
        }
        $tail = 0;
        while ($tail < (\count($old) - $head) && $tail < (\count($new) - $head) && $old[\count($old) - 1 - $tail] === $new[\count($new) - 1 - $tail]) {
            ++$tail;
        }
        $endLine = \count($old) - $tail;
        $added = \array_slice($new, $head, \count($new) - $tail - $head);
        if ($endLine === $head && [] === $added) {
            return null;
        }
        if ($endLine < \count($old)) {
            return new self([$head, 0], [$endLine, 0], [] === $added ? '' : \implode("\n", $added)."\n", $head);
        }
        $last = \count($old) - 1;
        $before = $head - 1;
        // From the end of the last unchanged line — or from the very start,
        // with none — so its newline goes, or leads the new lines in.
        // The analyzer reads the head loop as always stepping once; a text
        // changed from its first line ('old' → 'new') does not, and lands here.
        // @mago-expect analysis:redundant-comparison,redundant-condition -- $head is 0 when the first line changed; LineChangeTest covers it
        $start = $before >= 0 ? [$before, \strlen($old[$before])] : [0, 0];
        // @mago-expect analysis:redundant-comparison,redundant-logical-operation -- the same: $before is -1 then
        $lead = $before >= 0 && [] !== $added ? "\n" : '';

        return new self($start, [$last, \strlen($old[$last])], $lead.\implode("\n", $added), $head);
    }
}
