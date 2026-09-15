<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\BlockDiff;
use TestMonitor\Revisable\Diffing\PlainDiffer;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class PlainDifferTest extends TestCase
{
    // Status

    #[Test]
    public function it_reports_a_field_as_kept_when_both_sides_match()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff('hello world', 'hello world');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    #[Test]
    public function it_reports_a_field_as_added_when_there_was_no_previous_value()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff(null, 'brand new');

        // Then
        $this->assertSame(ChangeType::Added, $result->status);
        $this->assertSame('', $result->beforeHtml);
        $this->assertSame('<ins>brand new</ins>', $result->afterHtml);
    }

    #[Test]
    public function it_reports_a_field_as_removed_when_the_value_is_gone()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff('was here', null);

        // Then
        $this->assertSame(ChangeType::Removed, $result->status);
        $this->assertSame('<del>was here</del>', $result->beforeHtml);
        $this->assertSame('', $result->afterHtml);
    }

    #[Test]
    public function it_reports_a_field_as_kept_when_both_sides_are_empty()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff(null, null);

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
        $this->assertSame([], $result->blocks);
    }

    // Structure

    #[Test]
    public function it_produces_one_block_per_line()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff("first\nsecond\nthird", "first\nsecond\nthird");

        // Then
        $this->assertCount(3, $result->blocks);
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Kept, ChangeType::Kept],
            array_map(fn (BlockDiff $block) => $block->status, $result->blocks),
        );
    }

    #[Test]
    public function it_marks_only_the_changed_line_when_a_middle_line_is_edited()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff("keep\nold\nkeep too", "keep\nnew\nkeep too");

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Changed, ChangeType::Kept],
            array_map(fn (BlockDiff $block) => $block->status, $result->blocks),
        );
    }

    #[Test]
    public function it_does_not_shift_later_lines_when_a_line_is_removed()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff("a\nb\nc", "a\nc");

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Removed, ChangeType::Kept],
            array_map(fn (BlockDiff $block) => $block->status, $result->blocks),
        );
    }

    // Rendering

    #[Test]
    public function it_marks_deletions_in_the_before_view_and_insertions_in_the_after_view()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff('the brown fox', 'the red fox');

        // Then
        $this->assertSame('the <del>brown</del> fox', $result->beforeHtml);
        $this->assertSame('the <ins>red</ins> fox', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_escapes_html_special_characters()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff('hello & <b>world</b>', 'hello & <b>world</b>');

        // Then
        $this->assertSame('hello &amp; &lt;b&gt;world&lt;/b&gt;', $result->beforeHtml);
    }

    #[Test]
    public function it_treats_html_looking_text_as_literal_text_when_it_changes()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff('<b>old</b>', '<b>new</b>');

        // Then
        $this->assertStringContainsString('&lt;b&gt;', $result->beforeHtml);
        $this->assertStringNotContainsString('<b>', $result->beforeHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
    }

    #[Test]
    public function it_joins_lines_with_the_configured_separator()
    {
        // Given
        $differ = new PlainDiffer(separator: ' | ');

        // When
        $result = $differ->diff("one\ntwo", "one\ntwo");

        // Then
        $this->assertSame('one | two', $result->beforeHtml);
    }

    #[Test]
    public function it_defaults_to_a_line_break_separator()
    {
        // Given
        $differ = new PlainDiffer;

        // When
        $result = $differ->diff("one\ntwo", "one\ntwo");

        // Then
        $this->assertSame('one<br/>two', $result->beforeHtml);
    }

    #[Test]
    public function it_normalises_windows_line_endings()
    {
        // Given
        $differ = new PlainDiffer(separator: '|');

        // When
        $result = $differ->diff("one\r\ntwo", "one\r\ntwo");

        // Then
        $this->assertSame('one|two', $result->beforeHtml);
    }

    #[Test]
    public function it_omits_a_removed_line_from_the_after_view_entirely()
    {
        // Given
        $differ = new PlainDiffer(separator: '|');

        // When
        $result = $differ->diff("a\nb", 'a');

        // Then
        $this->assertSame('a|<del>b</del>', $result->beforeHtml);
        $this->assertSame('a', $result->afterHtml);
    }

    // Alignment key

    #[Test]
    public function it_uses_the_value_itself_as_its_alignment_key()
    {
        // Given
        $differ = new PlainDiffer;

        // When / Then
        $this->assertSame('some value', $differ->key('some value'));
    }
}
