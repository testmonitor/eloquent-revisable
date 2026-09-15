<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\Support\AlignedBlock;
use TestMonitor\Revisable\Diffing\Support\ArrayAligner;
use TestMonitor\Revisable\Tests\TestCase;

final class ArrayAlignerTest extends TestCase
{
    /**
     * Flatten aligned blocks into pairs, padding the shorter side with null,
     * which is how every caller consumes them.
     *
     * @param list<AlignedBlock> $blocks
     * @return list<array{mixed, mixed}>
     */
    protected function pairs(array $blocks): array
    {
        $pairs = [];

        foreach ($blocks as $block) {
            foreach (array_map(null, $block->before, $block->after) as $pair) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    #[Test]
    public function it_aligns_identical_arrays_pairwise()
    {
        // Given
        $aligner = new ArrayAligner(['a', 'b', 'c'], ['a', 'b', 'c']);

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['a', 'a'], ['b', 'b'], ['c', 'c']], $pairs);
    }

    #[Test]
    public function it_does_not_shift_later_items_when_one_is_removed()
    {
        // Given
        $aligner = new ArrayAligner(['a', 'b', 'c'], ['a', 'c']);

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['a', 'a'], ['b', null], ['c', 'c']], $pairs);
    }

    #[Test]
    public function it_does_not_shift_later_items_when_one_is_inserted()
    {
        // Given
        $aligner = new ArrayAligner(['a', 'c'], ['a', 'b', 'c']);

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['a', 'a'], [null, 'b'], ['c', 'c']], $pairs);
    }

    #[Test]
    public function it_anchors_on_unique_items_so_duplicates_cannot_mismatch()
    {
        // Given
        // 'Log in as admin' is duplicated in $before, so it can never anchor; the only
        // anchor available is 'Divider'. A positional (or naive longest-match) diff would
        // instead latch onto the leading 'Log in as admin' as the single longest match
        // (it appears earliest in $before), treating everything else as pure removals and
        // insertions and leaving the trailing 'Log in as admin' unmatched.
        $aligner = new ArrayAligner(
            ['Log in as admin', 'Divider', 'Verify dashboard loads', 'Log in as admin'],
            ['Divider', 'Verify dashboard loads correctly', 'Log in as admin'],
        );

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([
            ['Log in as admin', null],
            ['Divider', 'Divider'],
            ['Verify dashboard loads', 'Verify dashboard loads correctly'],
            ['Log in as admin', 'Log in as admin'],
        ], $pairs);
    }

    #[Test]
    public function it_pairs_a_changed_item_rather_than_treating_it_as_a_removal_and_insertion()
    {
        // Given
        $aligner = new ArrayAligner(['a', 'old', 'c'], ['a', 'new', 'c']);

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['a', 'a'], ['old', 'new'], ['c', 'c']], $pairs);
    }

    #[Test]
    public function it_aligns_by_a_derived_key_while_returning_the_original_items()
    {
        // Given
        $aligner = new ArrayAligner(
            ['<p>a</p>', '<p>b</p>'],
            ['<em>a</em>', '<p>b</p>'],
            fn (string $item) => strip_tags($item),
        );

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['<p>a</p>', '<em>a</em>'], ['<p>b</p>', '<p>b</p>']], $pairs);
    }

    #[Test]
    public function it_returns_every_item_as_one_sided_when_the_other_array_is_empty()
    {
        // Given
        $aligner = new ArrayAligner(['a', 'b'], []);

        // When
        $pairs = $this->pairs($aligner->align());

        // Then
        $this->assertSame([['a', null], ['b', null]], $pairs);
    }

    #[Test]
    public function it_returns_no_blocks_for_two_empty_arrays()
    {
        // Given
        $aligner = new ArrayAligner([], []);

        // When / Then
        $this->assertSame([], $this->pairs($aligner->align()));
    }
}
