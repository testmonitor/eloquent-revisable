<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\Segment;
use TestMonitor\Revisable\Diffing\WordDiffer;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class WordDifferTest extends TestCase
{
    /**
     * Reduce segments to [type, text] pairs so assertions read clearly.
     *
     * @param list<Segment> $segments
     * @return list<array{ChangeType, string}>
     */
    protected function simplify(array $segments): array
    {
        return array_map(fn (Segment $segment) => [$segment->type, $segment->text], $segments);
    }

    /**
     * Rebuild one side of a diff by keeping only the segments present on that side and
     * concatenating their text, in order.
     *
     * @param list<Segment> $segments
     */
    protected function reconstruct(array $segments, callable $isOnThisSide): string
    {
        return collect($segments)
            ->filter(fn (Segment $segment) => $isOnThisSide($segment->type))
            ->map(fn (Segment $segment) => $segment->text)
            ->implode('');
    }

    /**
     * Whitespace edge cases for the lossless-reconstruction property that PlainDiffer
     * and MarkdownDiffer both depend on. Named cases document what each one probes.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function losslessCases(): iterable
    {
        yield 'multiple spaces and a tab' => ["a  b\tc", "a  b\td"];
        yield 'leading whitespace' => ['  hello', '  world'];
        yield 'trailing whitespace' => ['hello  ', 'world  '];
        yield 'leading and trailing whitespace' => ['  hello  ', '  world  '];
        yield 'whitespace only' => ['   ', "\t\t"];
        yield 'consecutive whitespace runs, a tab next to a newline' => ["a \t\nb", "a \t\nc"];
        yield 'empty before side' => ['', 'brand new text'];
        yield 'empty after side' => ['existing text', ''];
    }

    #[Test]
    public function it_returns_one_kept_segment_for_identical_strings()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('hello world', 'hello world');

        // Then
        $this->assertSame([[ChangeType::Kept, 'hello world']], $this->simplify($segments));
    }

    #[Test]
    public function it_marks_a_replaced_word_as_removed_then_added()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('the brown fox', 'the red fox');

        // Then
        $this->assertSame([
            [ChangeType::Kept, 'the '],
            [ChangeType::Removed, 'brown'],
            [ChangeType::Added, 'red'],
            [ChangeType::Kept, ' fox'],
        ], $this->simplify($segments));
    }

    #[Test]
    public function it_marks_an_appended_word_as_added_only()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('hello', 'hello world');

        // Then
        $this->assertSame([
            [ChangeType::Kept, 'hello'],
            [ChangeType::Added, ' world'],
        ], $this->simplify($segments));
    }

    #[Test]
    public function it_marks_a_dropped_word_as_removed_only()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('hello world', 'hello');

        // Then
        $this->assertSame([
            [ChangeType::Kept, 'hello'],
            [ChangeType::Removed, ' world'],
        ], $this->simplify($segments));
    }

    #[Test]
    #[DataProvider('losslessCases')]
    public function it_reconstructs_both_sides_exactly_so_rendering_is_lossless(string $before, string $after)
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff($before, $after);

        // Then
        $this->assertSame($before, $this->reconstruct($segments, fn (ChangeType $type) => $type->inBefore()));
        $this->assertSame($after, $this->reconstruct($segments, fn (ChangeType $type) => $type->inAfter()));
    }

    #[Test]
    public function it_diffs_multibyte_text_without_splitting_characters()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff("caf\u{e9} cr\u{e8}me", "caf\u{e9} noir");

        // Then
        $this->assertSame([
            [ChangeType::Kept, "caf\u{e9} "],
            [ChangeType::Removed, "cr\u{e8}me"],
            [ChangeType::Added, 'noir'],
        ], $this->simplify($segments));
    }

    #[Test]
    public function it_returns_a_single_added_segment_when_the_before_side_is_empty()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('', 'brand new');

        // Then
        $this->assertSame([[ChangeType::Added, 'brand new']], $this->simplify($segments));
    }

    #[Test]
    public function it_returns_no_segments_when_both_sides_are_empty()
    {
        // Given
        $differ = new WordDiffer;

        // When / Then
        $this->assertSame([], $differ->diff('', ''));
    }
}
