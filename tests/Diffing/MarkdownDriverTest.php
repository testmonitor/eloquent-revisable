<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\BlockDiff;
use TestMonitor\Revisable\Diffing\MarkdownDriver;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class MarkdownDriverTest extends TestCase
{
    /**
     * @param list<BlockDiff> $blocks
     * @return list<ChangeType>
     */
    protected function statuses(array $blocks): array
    {
        return array_map(fn (BlockDiff $block) => $block->status, $blocks);
    }

    // Block alignment

    #[Test]
    public function it_keeps_every_block_when_nothing_changed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("# Title\n\nSome text.", "# Title\n\nSome text.");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Kept], $this->statuses($result->blocks));
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    #[Test]
    public function it_marks_only_the_edited_paragraph_as_changed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("# Title\n\nThe brown fox.", "# Title\n\nThe red fox.");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Changed], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_does_not_shift_later_blocks_when_one_is_removed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("One.\n\nTwo.\n\nThree.", "One.\n\nThree.");

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Removed, ChangeType::Kept],
            $this->statuses($result->blocks),
        );
    }

    #[Test]
    public function it_treats_a_paragraph_promoted_to_a_heading_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Same words.', '# Same words.');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
    }

    // Lists as containers

    #[Test]
    public function it_recurses_into_list_items_rather_than_replacing_the_whole_list()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("- one\n- two", "- one\n- three");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Changed], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_marks_an_appended_list_item_as_added()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('- one', "- one\n- two");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Added], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_keeps_the_list_wrapper_in_the_rendered_output()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("- one\n- two", "- one\n- three");

        // Then
        $this->assertStringContainsString('<ul>', $result->afterHtml);
        $this->assertStringContainsString('</ul>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Atomic blocks

    #[Test]
    public function it_replaces_a_changed_code_block_whole_rather_than_diffing_inside_it()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("```\nfoo bar\n```", "```\nfoo baz\n```");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('foo baz', $result->afterHtml);
        $this->assertStringContainsString('<code>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_keeps_an_unchanged_code_block()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("```\nfoo bar\n```", "```\nfoo bar\n```");

        // Then
        $this->assertSame([ChangeType::Kept], $this->statuses($result->blocks));
    }

    // Inline diffing

    #[Test]
    public function it_marks_changed_words_inside_a_paragraph()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('The brown fox.', 'The red fox.');

        // Then
        $this->assertStringContainsString('<del>brown</del>', $result->beforeHtml);
        $this->assertStringContainsString('<ins>red</ins>', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_places_inline_markers_inside_the_paragraph_element()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('The brown fox.', 'The red fox.');

        // Then
        $this->assertStringStartsWith('<p>', trim($result->afterHtml));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_preserves_emphasis_around_unchanged_words()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('A **bold** brown fox.', 'A **bold** red fox.');

        // Then
        $this->assertStringContainsString('<strong>bold</strong>', $result->afterHtml);
        $this->assertStringContainsString('<ins>red</ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_reports_a_formatting_only_change_as_changed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Hello world', 'Hello **world**');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Whole block additions

    #[Test]
    public function it_wraps_a_wholly_added_block_at_block_level()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('One.', "One.\n\nTwo.");

        // Then
        $this->assertStringContainsString('<ins>', $result->afterHtml);
        $this->assertStringContainsString('Two.', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Field status

    #[Test]
    public function it_reports_an_absent_previous_value_as_added()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff(null, '# Fresh');

        // Then
        $this->assertSame(ChangeType::Added, $result->status);
        $this->assertSame('', $result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_reports_an_absent_new_value_as_removed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('# Gone', null);

        // Then
        $this->assertSame(ChangeType::Removed, $result->status);
        $this->assertSame('', $result->afterHtml);
    }

    // Security

    #[Test]
    public function it_escapes_raw_html_embedded_in_markdown_by_default()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('<script>alert(1)</script>', '<script>alert(1)</script>');

        // Then
        $this->assertStringNotContainsString('<script>', $result->afterHtml);
        $this->assertStringContainsString('&lt;script&gt;', $result->afterHtml);
    }

    // Configuration

    #[Test]
    public function it_accepts_a_custom_environment()
    {
        // Given
        $environment = new Environment(['html_input' => 'escape']);
        $environment->addExtension(new CommonMarkCoreExtension);
        $driver = new MarkdownDriver($environment);

        // When
        $result = $driver->diff('Hello world', 'Hello there');

        // Then
        $this->assertStringContainsString('<ins>there</ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Alignment key

    #[Test]
    public function it_keys_a_value_by_its_plain_text_so_formatting_edits_still_align()
    {
        // Given
        $driver = new MarkdownDriver;

        // When / Then
        $this->assertSame($driver->key('Run **tests**'), $driver->key('Run tests'));
    }

    // Block identity

    #[Test]
    public function it_treats_a_heading_level_change_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('# Heading', '## Heading');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Code spans

    #[Test]
    public function it_marks_a_changed_code_span_as_a_whole()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Run `foo bar` now.', 'Run `foo baz` now.');

        // Then
        $this->assertStringContainsString('<del><code>foo bar</code></del>', $result->beforeHtml);
        $this->assertStringContainsString('<ins><code>foo baz</code></ins>', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Link text

    #[Test]
    public function it_marks_changed_link_text_without_disturbing_the_anchor()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('See [the docs](https://a) here.', 'See [the guide](https://a) here.');

        // Then
        $this->assertStringContainsString('<a href="https://a">the <ins>guide</ins></a>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Marker nesting

    #[Test]
    public function it_does_not_nest_inline_markers_inside_a_wholly_added_block()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('One.', "One.\n\nOne. Two.");

        // Then
        $this->assertStringContainsString('<ins><p>One. Two.</p></ins>', $result->afterHtml);
        $this->assertStringNotContainsString('<ins><ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Blocks that parse to nothing

    #[Test]
    public function it_reports_a_change_when_neither_side_produces_any_block()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('[docs]: https://a', '[docs]: https://b');

        // Then
        $this->assertSame([], $result->blocks);
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    #[Test]
    public function it_reports_two_blockless_values_that_match_as_kept()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('[docs]: https://a', '[docs]: https://a');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
    }
}
