<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Inline single paragraph

    #[Test]
    public function it_renders_a_single_paragraph_entry_inline_when_asked()
    {
        // Given
        $driver = new MarkdownDriver(inlineSingleParagraph: true);

        // When
        $result = $driver->diff('Step one', 'Step two');

        // Then
        $this->assertSame('Step <del>one</del>', trim($result->beforeHtml));
        $this->assertSame('Step <ins>two</ins>', trim($result->afterHtml));
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_keeps_block_markup_for_an_entry_holding_several_blocks()
    {
        // Given
        $driver = new MarkdownDriver(inlineSingleParagraph: true);

        // When
        $result = $driver->diff("Para one\n\nPara two", "Para one\n\nPara three");

        // Then
        $this->assertStringContainsString('<p>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_keeps_block_markup_for_an_entry_that_is_not_a_paragraph()
    {
        // Given
        $driver = new MarkdownDriver(inlineSingleParagraph: true);

        // When
        $result = $driver->diff('# Heading one', '# Heading two');

        // Then
        $this->assertStringContainsString('<h1>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_unwraps_a_wholly_added_entry_inside_its_marker()
    {
        // Given
        $driver = new MarkdownDriver(inlineSingleParagraph: true);

        // When
        $result = $driver->diff(null, 'Brand new');

        // Then
        $this->assertSame('<ins>Brand new</ins>', trim($result->afterHtml));
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_leaves_block_rendering_alone_by_default()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Step one', 'Step two');

        // Then
        $this->assertSame('<p>Step <ins>two</ins></p>', trim($result->afterHtml));
    }

    // Formatting-only changes

    #[Test]
    public function it_marks_a_formatting_only_change_so_it_does_not_read_as_no_change()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Setup step.', '**Setup step.**');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('<p><strong><ins class="mod">Setup step.</ins></strong></p>', trim($result->afterHtml));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_leaves_the_before_view_unmarked_for_a_formatting_only_change()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Setup step.', '**Setup step.**');

        // Then
        $this->assertSame('<p>Setup step.</p>', trim($result->beforeHtml));
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
    }

    #[Test]
    public function it_marks_removed_formatting_as_well_as_added_formatting()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('**Setup step.**', 'Setup step.');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('<p><ins class="mod">Setup step.</ins></p>', trim($result->afterHtml));
    }

    #[Test]
    public function it_distinguishes_a_formatting_marker_from_a_real_insertion()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $formatting = $driver->diff('Setup step.', '**Setup step.**');
        $insertion = $driver->diff('Setup', 'Setup step');

        // Then
        $this->assertStringContainsString('<ins class="mod">', $formatting->afterHtml);
        $this->assertStringNotContainsString('<ins class="mod">', $insertion->afterHtml);
        $this->assertStringContainsString('<ins>', $insertion->afterHtml);
    }

    #[Test]
    public function it_does_not_mark_anything_when_neither_words_nor_formatting_changed()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('**Setup step.**', '**Setup step.**');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
        $this->assertStringNotContainsString('<ins', $result->afterHtml);
        $this->assertSame($result->beforeHtml, $result->afterHtml);
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

    #[Test]
    public function it_marks_an_added_list_item_inside_the_item_rather_than_inside_the_list()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('- one', "- one\n- two");

        // Then
        $this->assertStringContainsString('<li><ins>two</ins></li>', $result->afterHtml);
        $this->assertDoesNotMatchRegularExpression('/<ul>\s*<ins>/', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_renders_an_added_list_item_as_tightly_as_its_siblings()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('- one', "- one\n- two");

        // Then
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_marks_a_removed_list_item_inside_the_item_as_well()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("- one\n- two", '- one');

        // Then
        $this->assertStringContainsString('<li><del>two</del></li>', $result->beforeHtml);
        $this->assertDoesNotMatchRegularExpression('/<ul>\s*<del>/', $result->beforeHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
    }

    #[Test]
    public function it_still_marks_a_wholly_added_paragraph_at_block_level()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('One.', "One.\n\nTwo.");

        // Then
        $this->assertStringContainsString('<ins><p>Two.</p></ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_a_wholly_added_list_at_block_level()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Text.', "Text.\n\n- one");

        // Then
        $this->assertStringContainsString('<ins><ul>', $result->afterHtml);
        $this->assertStringContainsString('</ul></ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Block variants

    #[Test]
    public function it_treats_a_bullet_list_turned_ordered_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("- one\n- two", "1. one\n2. two");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('<ins><ol>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_changed_list_start_number_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('1. one', '3. one');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('<ol start="3">', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_list_turned_loose_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("- one\n- two", "- one\n\n- two");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_changed_code_block_language_as_a_removal_and_an_addition()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("```php\ncode\n```", "```js\ncode\n```");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('class="language-js"', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_ignores_list_padding_that_changes_nothing_in_the_output()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('-   one', '- one');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    // The invariant behind every status

    /**
     * A field reported as kept must render identically on both sides. Anything a node
     * carries that changes its output has to reach the status, or a consumer that skips
     * kept fields silently hides an edit the user really made.
     */
    #[Test]
    #[DataProvider('blockPairs')]
    public function it_renders_both_sides_identically_whenever_it_reports_a_field_as_kept(
        string $before,
        string $after,
        ChangeType $expected,
    ) {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff($before, $after);

        // Then
        $this->assertSame($expected, $result->status);

        if ($result->status === ChangeType::Kept) {
            $this->assertSame($result->beforeHtml, $result->afterHtml, 'A kept field rendered two different sides.');
        }
    }

    /**
     * @return array<string, array{string, string, ChangeType}>
     */
    public static function blockPairs(): array
    {
        return [
            'identical paragraph' => ['A paragraph.', 'A paragraph.', ChangeType::Kept],
            'identical heading and body' => ["# Title\n\nBody.", "# Title\n\nBody.", ChangeType::Kept],
            'identical tight list' => ["- one\n- two", "- one\n- two", ChangeType::Kept],
            'identical ordered list' => ["1. one\n2. two", "1. one\n2. two", ChangeType::Kept],
            'identical fenced code' => ["```php\ncode\n```", "```php\ncode\n```", ChangeType::Kept],
            'fenced code indentation added' => ["```\nfoo\n```", "```\n  foo\n```", ChangeType::Changed],
            'fenced code trailing whitespace removed' => ["```\nfoo  \n```", "```\nfoo\n```", ChangeType::Changed],
            'indented code block reindented' => ['    foo', '      foo', ChangeType::Changed],
            'identical blockquote' => ['> quoted', '> quoted', ChangeType::Kept],
            'identical thematic break' => ["a\n\n---\n\nb", "a\n\n---\n\nb", ChangeType::Kept],
            'thematic break style' => ["a\n\n---\n\nb", "a\n\n***\n\nb", ChangeType::Kept],
            'list marker padding' => ['-   one', '- one', ChangeType::Kept],
            'heading level' => ['# Same words.', '## Same words.', ChangeType::Changed],
            'bullet list turned ordered' => ["- one\n- two", "1. one\n2. two", ChangeType::Changed],
            'list start number' => ['1. one', '3. one', ChangeType::Changed],
            'tight list turned loose' => ["- one\n- two", "- one\n\n- two", ChangeType::Changed],
            'fenced code language' => ["```php\ncode\n```", "```js\ncode\n```", ChangeType::Changed],
            'changed word' => ['The brown fox.', 'The red fox.', ChangeType::Changed],
            'added paragraph' => ['One.', "One.\n\nTwo.", ChangeType::Changed],
            'removed list item' => ["- one\n- two", '- one', ChangeType::Changed],
            'emphasis added' => ['Hello world', 'Hello **world**', ChangeType::Changed],
            'link destination' => ['[text](/a)', '[text](/b)', ChangeType::Changed],
            'link title' => ['[t](/a "one")', '[t](/a "two")', ChangeType::Changed],
            'image source' => ['![alt](/a.png)', '![alt](/b.png)', ChangeType::Changed],
            'image source, no alt text' => ['![](/a.png)', '![](/b.png)', ChangeType::Changed],
            'identical link' => ['[text](/a)', '[text](/a)', ChangeType::Kept],
        ];
    }

    // Repeated use of one driver instance

    #[Test]
    public function it_produces_the_same_result_across_repeated_calls_on_one_instance()
    {
        // Given
        $shared = new MarkdownDriver;

        $pairs = [
            ['The brown fox.', 'The red fox.'],
            ["- one\n- two", "- one\n- three"],
            ['The brown fox.', 'The red fox.'], // deliberately repeated
            ['[text](/a)', '[text](/b)'],
        ];

        // When
        $sharedResults = array_map(fn (array $pair) => $shared->diff(...$pair), $pairs);
        $freshResults = array_map(fn (array $pair) => (new MarkdownDriver)->diff(...$pair), $pairs);

        // Then
        foreach ($sharedResults as $i => $result) {
            $this->assertSame($this->statuses($freshResults[$i]->blocks), $this->statuses($result->blocks));
            $this->assertSame($freshResults[$i]->status, $result->status);
            $this->assertSame($freshResults[$i]->beforeHtml, $result->beforeHtml);
            $this->assertSame($freshResults[$i]->afterHtml, $result->afterHtml);
        }
    }

    // Multi-byte text

    #[Test]
    public function it_marks_changed_words_inside_a_multi_byte_paragraph()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff('Café au café, très chaud.', 'Café au café, très froid.');

        // Then
        $this->assertStringContainsString('<del>chaud.</del>', $result->beforeHtml);
        $this->assertStringContainsString('<ins>froid.</ins>', $result->afterHtml);
        $this->assertStringContainsString('Café au café, très', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Line breaks

    #[Test]
    public function it_treats_a_hard_break_turned_soft_as_a_change_in_the_rendered_break()
    {
        // Given
        $driver = new MarkdownDriver;

        // When
        $result = $driver->diff("a  \nb", "a\nb");

        // Then
        $this->assertNotSame(ChangeType::Kept, $result->status);
        $this->assertStringContainsString('<br />', $result->beforeHtml);
        $this->assertStringNotContainsString('<br />', $result->afterHtml);
        $this->assertNotSame($result->beforeHtml, $result->afterHtml);
    }
}
