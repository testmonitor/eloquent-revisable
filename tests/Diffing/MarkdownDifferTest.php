<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use Iterator;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\BlockDiff;
use TestMonitor\Revisable\Diffing\MarkdownDiffer;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;
use TestMonitor\Revisable\Tests\TestCase;

final class MarkdownDifferTest extends TestCase
{
    /**
     * @param list<BlockDiff> $blocks
     * @return list<ChangeType>
     */
    protected function statuses(array $blocks): array
    {
        return array_map(fn (BlockDiff $block) => $block->status, $blocks);
    }

    // Unchanged content

    #[Test]
    public function it_keeps_every_block_when_nothing_changed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("# Title\n\nSome text.", "# Title\n\nSome text.");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Kept], $this->statuses($result->blocks));
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    #[Test]
    public function it_keeps_an_unchanged_code_block()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("```\nfoo bar\n```", "```\nfoo bar\n```");

        // Then
        $this->assertSame([ChangeType::Kept], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_does_not_mark_anything_when_neither_words_nor_formatting_changed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('**Setup step.**', '**Setup step.**');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
        $this->assertStringNotContainsString('<ins', $result->afterHtml);
        $this->assertSame($result->beforeHtml, $result->afterHtml);
    }

    // Changed words

    #[Test]
    public function it_marks_changed_words_inside_a_paragraph()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('The brown fox.', 'The red fox.');

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
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('The brown fox.', 'The red fox.');

        // Then
        $this->assertStringStartsWith('<p>', trim($result->afterHtml));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_only_the_edited_paragraph_as_changed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("# Title\n\nThe brown fox.", "# Title\n\nThe red fox.");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Changed], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_preserves_emphasis_around_unchanged_words()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('A **bold** brown fox.', 'A **bold** red fox.');

        // Then
        $this->assertStringContainsString('<strong>bold</strong>', $result->afterHtml);
        $this->assertStringContainsString('<ins>red</ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_changed_link_text_without_disturbing_the_anchor()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('See [the docs](https://a) here.', 'See [the guide](https://a) here.');

        // Then
        $this->assertStringContainsString('<a href="https://a">the <ins>guide</ins></a>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_a_changed_code_span_as_a_whole()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Run `foo bar` now.', 'Run `foo baz` now.');

        // Then
        $this->assertStringContainsString('<del><code>foo bar</code></del>', $result->beforeHtml);
        $this->assertStringContainsString('<ins><code>foo baz</code></ins>', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_changed_words_inside_a_multi_byte_paragraph()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Café au café, très chaud.', 'Café au café, très froid.');

        // Then
        $this->assertStringContainsString('<del>chaud.</del>', $result->beforeHtml);
        $this->assertStringContainsString('<ins>froid.</ins>', $result->afterHtml);
        $this->assertStringContainsString('Café au café, très', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Changed formatting

    #[Test]
    public function it_marks_a_formatting_only_change()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Setup step.', '**Setup step.**');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('<p><strong><ins class="mod">Setup step.</ins></strong></p>', trim($result->afterHtml));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_leaves_the_before_view_unmarked_for_a_formatting_only_change()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Setup step.', '**Setup step.**');

        // Then
        $this->assertSame('<p>Setup step.</p>', trim($result->beforeHtml));
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
    }

    #[Test]
    public function it_marks_removed_formatting_as_well_as_added_formatting()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('**Setup step.**', 'Setup step.');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('<p><ins class="mod">Setup step.</ins></p>', trim($result->afterHtml));
    }

    #[Test]
    public function it_marks_only_the_word_whose_formatting_changed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('A bold word here', 'A **bold** word here');

        // Then
        $this->assertSame(
            '<p>A <strong><ins class="mod">bold</ins></strong> word here</p>',
            trim($result->afterHtml),
        );
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_each_reformatted_word_separately()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('a b c d', 'a **b** c **d**');

        // Then
        $this->assertSame(2, substr_count($result->afterHtml, '<ins class="mod">'));
        $this->assertStringNotContainsString('<ins class="mod">a', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_leaves_untouched_formatting_unmarked()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('A **kept** word here', 'A **kept** *new* word here');

        // Then
        $this->assertStringContainsString('<strong>kept</strong>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_distinguishes_a_formatting_marker_from_a_real_insertion()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $formatting = $differ->diff('Setup step.', '**Setup step.**');
        $insertion = $differ->diff('Setup', 'Setup step');

        // Then
        $this->assertStringContainsString('<ins class="mod">', $formatting->afterHtml);
        $this->assertStringNotContainsString('<ins class="mod">', $insertion->afterHtml);
        $this->assertStringContainsString('<ins>', $insertion->afterHtml);
    }

    #[Test]
    public function it_joins_neighbouring_reformatted_runs_into_one_marker()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('*a* *b* c', '**a b** c');

        // Then
        $this->assertStringContainsString('<ins class="mod">a b</ins>', $result->afterHtml);
        $this->assertSame(1, substr_count($result->afterHtml, '<ins class="mod">'));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_a_reformatted_code_span_whole()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('`code` here', '**`code`** here');

        // Then
        $this->assertStringContainsString('<ins class="mod"><code>code</code></ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Added and removed blocks

    #[Test]
    public function it_wraps_a_wholly_added_block_at_block_level()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('One.', "One.\n\nTwo.");

        // Then
        $this->assertStringContainsString('<ins><p>Two.</p></ins>', $result->afterHtml);
        $this->assertSidesAreClean($result->beforeHtml, $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_a_wholly_added_list_at_block_level()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Text.', "Text.\n\n- one");

        // Then
        $this->assertStringContainsString('<ins><ul>', $result->afterHtml);
        $this->assertStringContainsString('</ul></ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_does_not_shift_later_blocks_when_one_is_removed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("One.\n\nTwo.\n\nThree.", "One.\n\nThree.");

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Removed, ChangeType::Kept],
            $this->statuses($result->blocks),
        );
    }

    #[Test]
    public function it_does_not_nest_inline_markers_inside_a_wholly_added_block()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('One.', "One.\n\nOne. Two.");

        // Then
        $this->assertStringContainsString('<ins><p>One. Two.</p></ins>', $result->afterHtml);
        $this->assertStringNotContainsString('<ins><ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Lists

    #[Test]
    public function it_recurses_into_list_items_rather_than_replacing_the_whole_list()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("- one\n- two", "- one\n- three");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Changed], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_keeps_the_list_wrapper_in_the_rendered_output()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("- one\n- two", "- one\n- three");

        // Then
        $this->assertStringContainsString('<ul>', $result->afterHtml);
        $this->assertStringContainsString('</ul>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_an_appended_list_item_as_added()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('- one', "- one\n- two");

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Added], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_marks_an_added_list_item_inside_the_item_rather_than_inside_the_list()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('- one', "- one\n- two");

        // Then
        $this->assertStringContainsString('<li><ins>two</ins></li>', $result->afterHtml);
        $this->assertDoesNotMatchRegularExpression('/<ul>\s*<ins>/', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_marks_a_removed_list_item_inside_the_item()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("- one\n- two", '- one');

        // Then
        $this->assertStringContainsString('<li><del>two</del></li>', $result->beforeHtml);
        $this->assertDoesNotMatchRegularExpression('/<ul>\s*<del>/', $result->beforeHtml);
        $this->assertWellFormedHtml($result->beforeHtml);
    }

    #[Test]
    public function it_renders_an_added_list_item_as_tightly_as_its_siblings()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('- one', "- one\n- two");

        // Then
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_marks_a_nested_list_inside_an_added_item_as_a_block()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('- one', "- one\n- two\n    - nested");

        // Then
        // The nested list is marked as a block of its own, inside the item.
        $this->assertStringContainsString('<ins><ul>', $result->afterHtml);
        $this->assertStringContainsString('</ul></ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Atomic blocks

    #[Test]
    public function it_replaces_a_changed_code_block_whole()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("```\nfoo bar\n```", "```\nfoo baz\n```");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('foo baz', $result->afterHtml);
        $this->assertStringContainsString('<code>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Changes invisible to a node's type and text

    #[Test]
    public function it_treats_a_paragraph_promoted_to_a_heading_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Same words.', '# Same words.');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
    }

    #[Test]
    public function it_treats_a_heading_level_change_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('# Heading', '## Heading');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_bullet_list_turned_ordered_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("- one\n- two", "1. one\n2. two");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('<ins><ol>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_changed_list_start_number_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('1. one', '3. one');

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('<ol start="3">', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_list_turned_loose_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("- one\n- two", "- one\n\n- two");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_changed_code_block_language_as_a_removal_and_an_addition()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("```php\ncode\n```", "```js\ncode\n```");

        // Then
        $this->assertSame([ChangeType::Removed, ChangeType::Added], $this->statuses($result->blocks));
        $this->assertStringContainsString('class="language-js"', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_treats_a_hard_break_turned_soft_as_a_change_in_the_rendered_break()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff("a  \nb", "a\nb");

        // Then
        $this->assertNotSame(ChangeType::Kept, $result->status);
        $this->assertStringContainsString('<br />', $result->beforeHtml);
        $this->assertStringNotContainsString('<br />', $result->afterHtml);
        $this->assertNotSame($result->beforeHtml, $result->afterHtml);
    }

    #[Test]
    public function it_ignores_list_padding_that_changes_nothing_in_the_output()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('-   one', '- one');

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
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff($before, $after);

        // Then
        $this->assertSame($expected, $result->status);

        if ($result->status === ChangeType::Kept) {
            $this->assertSame($result->beforeHtml, $result->afterHtml, 'A kept field rendered two different sides.');
        }
    }

    /**
     * @return Iterator<string, array{string, string, ChangeType}>
     */
    public static function blockPairs(): Iterator
    {
        yield 'identical paragraph' => ['A paragraph.', 'A paragraph.', ChangeType::Kept];
        yield 'identical heading and body' => ["# Title\n\nBody.", "# Title\n\nBody.", ChangeType::Kept];
        yield 'identical tight list' => ["- one\n- two", "- one\n- two", ChangeType::Kept];
        yield 'identical ordered list' => ["1. one\n2. two", "1. one\n2. two", ChangeType::Kept];
        yield 'identical fenced code' => ["```php\ncode\n```", "```php\ncode\n```", ChangeType::Kept];
        yield 'fenced code indentation added' => ["```\nfoo\n```", "```\n  foo\n```", ChangeType::Changed];
        yield 'fenced code trailing whitespace removed' => ["```\nfoo  \n```", "```\nfoo\n```", ChangeType::Changed];
        yield 'indented code block reindented' => ['    foo', '      foo', ChangeType::Changed];
        yield 'identical blockquote' => ['> quoted', '> quoted', ChangeType::Kept];
        yield 'identical thematic break' => ["a\n\n---\n\nb", "a\n\n---\n\nb", ChangeType::Kept];
        yield 'thematic break style' => ["a\n\n---\n\nb", "a\n\n***\n\nb", ChangeType::Kept];
        yield 'list marker padding' => ['-   one', '- one', ChangeType::Kept];
        yield 'heading level' => ['# Same words.', '## Same words.', ChangeType::Changed];
        yield 'bullet list turned ordered' => ["- one\n- two", "1. one\n2. two", ChangeType::Changed];
        yield 'list start number' => ['1. one', '3. one', ChangeType::Changed];
        yield 'tight list turned loose' => ["- one\n- two", "- one\n\n- two", ChangeType::Changed];
        yield 'fenced code language' => ["```php\ncode\n```", "```js\ncode\n```", ChangeType::Changed];
        yield 'changed word' => ['The brown fox.', 'The red fox.', ChangeType::Changed];
        yield 'added paragraph' => ['One.', "One.\n\nTwo.", ChangeType::Changed];
        yield 'removed list item' => ["- one\n- two", '- one', ChangeType::Changed];
        yield 'emphasis added' => ['Hello world', 'Hello **world**', ChangeType::Changed];
        yield 'link destination' => ['[text](/a)', '[text](/b)', ChangeType::Changed];
        yield 'link title' => ['[t](/a "one")', '[t](/a "two")', ChangeType::Changed];
        yield 'image source' => ['![alt](/a.png)', '![alt](/b.png)', ChangeType::Changed];
        yield 'image source, no alt text' => ['![](/a.png)', '![](/b.png)', ChangeType::Changed];
        yield 'identical link' => ['[text](/a)', '[text](/a)', ChangeType::Kept];
    }

    // Field status

    #[Test]
    public function it_reports_an_absent_previous_value_as_added()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff(null, '# Fresh');

        // Then
        $this->assertSame(ChangeType::Added, $result->status);
        $this->assertSame('', $result->beforeHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_reports_an_absent_new_value_as_removed()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('# Gone', null);

        // Then
        $this->assertSame(ChangeType::Removed, $result->status);
        $this->assertSame('', $result->afterHtml);
    }

    #[Test]
    public function it_reports_two_blockless_values_that_match_as_kept()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('[docs]: https://a', '[docs]: https://a');

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    #[Test]
    public function it_reports_a_change_when_neither_side_produces_any_block()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('[docs]: https://a', '[docs]: https://b');

        // Then
        $this->assertSame([], $result->blocks);
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    // Rendering list entries inline

    #[Test]
    public function it_leaves_block_rendering_alone_by_default()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('Step one', 'Step two');

        // Then
        $this->assertSame('<p>Step <ins>two</ins></p>', trim($result->afterHtml));
    }

    #[Test]
    public function it_renders_a_single_paragraph_entry_inline_when_asked()
    {
        // Given
        $differ = new MarkdownDiffer(inlineSingleParagraph: true);

        // When
        $result = $differ->diff('Step one', 'Step two');

        // Then
        $this->assertSame('Step <del>one</del>', trim($result->beforeHtml));
        $this->assertSame('Step <ins>two</ins>', trim($result->afterHtml));
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_unwraps_a_wholly_added_entry_inside_its_marker()
    {
        // Given
        $differ = new MarkdownDiffer(inlineSingleParagraph: true);

        // When
        $result = $differ->diff(null, 'Brand new');

        // Then
        $this->assertSame('<ins>Brand new</ins>', trim($result->afterHtml));
        $this->assertStringNotContainsString('<p>', $result->afterHtml);
    }

    #[Test]
    public function it_keeps_block_markup_for_an_entry_holding_several_blocks()
    {
        // Given
        $differ = new MarkdownDiffer(inlineSingleParagraph: true);

        // When
        $result = $differ->diff("Para one\n\nPara two", "Para one\n\nPara three");

        // Then
        $this->assertStringContainsString('<p>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_keeps_block_markup_for_an_entry_that_is_not_a_paragraph()
    {
        // Given
        $differ = new MarkdownDiffer(inlineSingleParagraph: true);

        // When
        $result = $differ->diff('# Heading one', '# Heading two');

        // Then
        $this->assertStringContainsString('<h1>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    // Alignment key

    #[Test]
    public function it_keys_a_value_by_its_plain_text_so_formatting_edits_still_align()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When / Then
        $this->assertSame($differ->key('Run **tests**'), $differ->key('Run tests'));
    }

    // Configuration and safety

    #[Test]
    public function it_accepts_a_custom_environment()
    {
        // Given
        $environment = new Environment(['html_input' => 'escape']);
        $environment->addExtension(new CommonMarkCoreExtension);

        $differ = new MarkdownDiffer($environment);

        // When
        $result = $differ->diff('Hello world', 'Hello there');

        // Then
        $this->assertStringContainsString('<ins>there</ins>', $result->afterHtml);
        $this->assertWellFormedHtml($result->afterHtml);
    }

    #[Test]
    public function it_escapes_raw_html_embedded_in_markdown_by_default()
    {
        // Given
        $differ = new MarkdownDiffer;

        // When
        $result = $differ->diff('<script>alert(1)</script>', '<script>alert(1)</script>');

        // Then
        $this->assertStringNotContainsString('<script>', $result->afterHtml);
        $this->assertStringContainsString('&lt;script&gt;', $result->afterHtml);
    }

    #[Test]
    public function it_produces_the_same_result_across_repeated_calls_on_one_instance()
    {
        // Given
        $shared = new MarkdownDiffer;

        $pairs = [
            ['The brown fox.', 'The red fox.'],
            ["- one\n- two", "- one\n- three"],
            ['The brown fox.', 'The red fox.'], // deliberately repeated
            ['[text](/a)', '[text](/b)'],
        ];

        // When
        $sharedResults = array_map(fn (array $pair) => $shared->diff(...$pair), $pairs);
        $freshResults = array_map(fn (array $pair) => (new MarkdownDiffer)->diff(...$pair), $pairs);

        // Then
        foreach ($sharedResults as $i => $result) {
            $this->assertSame($this->statuses($freshResults[$i]->blocks), $this->statuses($result->blocks));
            $this->assertSame($freshResults[$i]->status, $result->status);
            $this->assertSame($freshResults[$i]->beforeHtml, $result->beforeHtml);
            $this->assertSame($freshResults[$i]->afterHtml, $result->afterHtml);
        }
    }

    #[Test]
    public function it_names_the_package_to_install_when_commonmark_is_missing()
    {
        // Given / When
        $exception = InvalidConfiguration::missingCommonMark();

        // Then
        $this->assertStringContainsString('league/commonmark', $exception->getMessage());
        $this->assertStringContainsString('composer require', $exception->getMessage());
    }
}
