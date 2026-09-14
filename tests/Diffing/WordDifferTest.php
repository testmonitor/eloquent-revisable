<?php

namespace TestMonitor\Revisable\Tests\Diffing;

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
    public function it_preserves_whitespace_exactly_so_rendering_is_lossless()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff("a  b\tc", "a  b\td");

        // Then
        $rebuilt = collect($segments)
            ->filter(fn (Segment $segment) => $segment->type->inBefore())
            ->map(fn (Segment $segment) => $segment->text)
            ->implode('');

        $this->assertSame("a  b\tc", $rebuilt);
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

    #[Test]
    public function it_records_token_offsets_for_each_segment()
    {
        // Given
        $differ = new WordDiffer;

        // When
        $segments = $differ->diff('a b', 'a c');

        // Then
        $this->assertSame(0, $segments[0]->beforeOffset);
        $this->assertSame(0, $segments[0]->afterOffset);
        $this->assertSame(2, $segments[1]->beforeOffset);
    }
}
