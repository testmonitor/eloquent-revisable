<?php

namespace TestMonitor\Revisable\Diffing\Support;

use Closure;
use Jfcherng\Diff\SequenceMatcher;

/**
 * Aligns two arrays by content (or a derived key) rather than by position, so a
 * removal or insertion doesn't shift later items out of alignment. Unique items are anchored
 * first, so a duplicated item can't get matched to the wrong occurrence.
 */
class ArrayAligner
{
    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @param Closure(mixed):string|null $key Derives the comparison key items are aligned
     *                                            by; defaults to the item cast to a string. Useful to align by plain text while
     *                                            keeping the original (e.g. AST node) values in the returned blocks.
     */
    public function __construct(
        protected array $before,
        protected array $after,
        protected ?Closure $key = null,
    ) {
        $this->key ??= fn (mixed $item) => (string) $item;
    }

    /**
     * Align the two arrays using anchors to guide the alignment.
     *
     * @return list<AlignedBlock>
     */
    public function align(): array
    {
        return $this->alignSlice($this->before, $this->after);
    }

    /**
     * Recursively align a slice of the before and after arrays, using anchors to guide the alignment.
     *
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @return list<AlignedBlock>
     */
    protected function alignSlice(array $before, array $after): array
    {
        $anchors = $this->findAnchors($before, $after);

        return empty($anchors)
            ? $this->matchByContent($before, $after)
            : $this->splitAroundAnchors($before, $after, $anchors);
    }

    /**
     * Split before/after around each anchor, recursively aligning the gaps between them.
     *
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @param list<AnchorPair> $anchors
     * @return list<AlignedBlock>
     */
    protected function splitAroundAnchors(array $before, array $after, array $anchors): array
    {
        $blocks = [];
        $beforeCursor = 0;
        $afterCursor = 0;

        // A trailing anchor at the very end of both arrays covers the final gap.
        foreach ([...$anchors, new AnchorPair(count($before), count($after))] as $anchor) {
            $gapBlocks = $this->alignSlice(
                array_slice($before, $beforeCursor, $anchor->beforeIndex - $beforeCursor),
                array_slice($after, $afterCursor, $anchor->afterIndex - $afterCursor),
            );

            foreach ($gapBlocks as $block) {
                $blocks[] = $block;
            }

            // Skip the synthetic trailing anchor added above, it has no content of its own.
            if ($anchor->beforeIndex < count($before)) {
                $blocks[] = new AlignedBlock([$before[$anchor->beforeIndex]], [$after[$anchor->afterIndex]]);
            }

            $beforeCursor = $anchor->beforeIndex + 1;
            $afterCursor = $anchor->afterIndex + 1;
        }

        return $blocks;
    }

    /**
     * Find items that occur exactly once in both arrays, in the order they're matched.
     *
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @return list<AnchorPair>
     */
    protected function findAnchors(array $before, array $after): array
    {
        $beforeKeys = array_map($this->key, $before);
        $afterKeys = array_map($this->key, $after);

        $beforeCounts = array_count_values($beforeKeys);
        $afterCounts = array_count_values($afterKeys);

        $isUnique = fn (string $key) => ($beforeCounts[$key] ?? 0) === 1 && ($afterCounts[$key] ?? 0) === 1;

        // Matching only within the unique items keeps a duplicate from ever being picked as an anchor.
        $beforeUnique = collect($beforeKeys)->filter($isUnique);
        $afterUnique = collect($afterKeys)->filter($isUnique);

        $matcher = new SequenceMatcher($beforeUnique->values()->all(), $afterUnique->values()->all());

        // filter() preserves original keys, so these map a position among the unique items back
        // to its real index in the full $before/$after array.
        $beforeIndexes = $beforeUnique->keys()->values();
        $afterIndexes = $afterUnique->keys()->values();

        $anchors = [];

        // Each matching block can span several consecutive unique items; unpack it into one
        // anchor per item so alignSlice() can recurse on the gap before each of them.
        foreach ($matcher->getMatchingBlocks() as [$beforeOffset, $afterOffset, $matchLength]) {
            for ($position = 0; $position < $matchLength; $position++) {
                $anchors[] = new AnchorPair(
                    $beforeIndexes[$beforeOffset + $position],
                    $afterIndexes[$afterOffset + $position],
                );
            }
        }

        return $anchors;
    }

    /**
     * Align two arrays with no shared anchors using SequenceMatcher's opcodes.
     *
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @return list<AlignedBlock>
     */
    protected function matchByContent(array $before, array $after): array
    {
        $matcher = new SequenceMatcher(array_map($this->key, $before), array_map($this->key, $after));

        return collect($matcher->getOpcodes())
            ->map(function (array $opcode) use ($before, $after) {
                // The opcode type (equal/replace/insert/delete) is discarded, every kind becomes
                // a block here, whether it's a verbatim match or a run to diff positionally.
                [, $beforeStart, $beforeEnd, $afterStart, $afterEnd] = $opcode;

                return new AlignedBlock(
                    array_slice($before, $beforeStart, $beforeEnd - $beforeStart),
                    array_slice($after, $afterStart, $afterEnd - $afterStart),
                );
            })
            ->all();
    }
}
