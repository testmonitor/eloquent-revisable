<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Renderers\HtmlDiff;

final class HtmlDiffRendererTest extends TestCase
{
    protected function diffFor(mixed $old, mixed $new, string $field = 'value'): HtmlDiff
    {
        $before = new Revision(['metadata' => ['attributes' => [$field => $old]]]);
        $after = new Revision(['metadata' => ['attributes' => [$field => $new]]]);

        return new Diff($before, $after)->asHtml();
    }

    protected function diffFromNothingFor(mixed $new, string $field = 'value'): HtmlDiff
    {
        return Diff::fromNothing(new Revision(['metadata' => ['attributes' => [$field => $new]]]))->asHtml();
    }

    // Field access

    #[Test]
    public function it_returns_null_for_an_untracked_field()
    {
        // Given
        $htmlDiff = $this->diffFor('old', 'new');

        // When / Then
        $this->assertNull($htmlDiff->field('nonexistent'));
    }

    #[Test]
    public function it_returns_identical_strings_html_encoded()
    {
        // Given
        $htmlDiff = $this->diffFor('hello & world', 'hello & world');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('hello &amp; world', $result['before']);
        $this->assertSame('hello &amp; world', $result['after']);
    }

    #[Test]
    public function it_renders_identical_html_values_as_html()
    {
        // Given
        $htmlDiff = $this->diffFor('<b>hello</b>', '<b>hello</b>');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('<b>hello</b>', $result['before']);
        $this->assertSame('<b>hello</b>', $result['after']);
    }

    // Core contract

    #[Test]
    public function it_does_not_put_ins_tags_in_the_before_view_or_del_tags_in_the_after_view()
    {
        // Given
        $htmlDiff = $this->diffFor('The quick brown fox', 'The quick red fox');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringNotContainsString('<ins>', (string) $result['before']);
        $this->assertStringNotContainsString('<del>', (string) $result['after']);
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
    }

    #[Test]
    public function it_marks_deleted_words_in_the_before_view()
    {
        // Given
        $htmlDiff = $this->diffFor('hello world', 'hello');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('world', (string) $result['before']);
        $this->assertStringNotContainsString('<ins>', (string) $result['before']);
    }

    #[Test]
    public function it_marks_inserted_words_in_the_after_view()
    {
        // Given
        $htmlDiff = $this->diffFor('hello', 'hello world');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('world', (string) $result['after']);
        $this->assertStringNotContainsString('<del>', (string) $result['after']);
    }

    // Edge cases

    #[Test]
    public function it_handles_empty_old_value()
    {
        // Given
        $htmlDiff = $this->diffFor('', 'new content');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('new content', (string) $result['after']);
    }

    #[Test]
    public function it_handles_empty_new_value()
    {
        // Given
        $htmlDiff = $this->diffFor('old content', '');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('old content', (string) $result['before']);
    }

    #[Test]
    public function it_handles_null_values_as_empty_strings()
    {
        // Given
        $htmlDiff = $this->diffFor(null, 'something');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertIsArray($result);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
    }

    // Normalization

    #[Test]
    public function it_decodes_json_strings_before_diffing()
    {
        // Given
        $htmlDiff = $this->diffFor(
            json_encode(['apple', 'banana']),
            json_encode(['apple', 'cherry']),
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertIsArray($result['before']);
        $this->assertIsArray($result['after']);
    }

    #[Test]
    public function it_diffs_arrays_element_by_element()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple', 'banana'], ['apple', 'cherry']);

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertIsArray($result['before']);
        $this->assertIsArray($result['after']);
        $this->assertSame('apple', $result['before'][0]);
        $this->assertStringContainsString('<ins>', (string) $result['after'][1]);
    }

    #[Test]
    public function it_wraps_a_scalar_side_in_an_array_when_the_other_side_is_an_array()
    {
        // Given — before is a plain string, after is a JSON-decoded array
        $htmlDiff = $this->diffFor('apple', json_encode(['apple', 'banana']));

        // When
        $result = $htmlDiff->field('value');

        // Then — the scalar is preserved as the first element, not dropped
        $this->assertIsArray($result['before']);
        $this->assertIsArray($result['after']);
        $this->assertNotEmpty($result['before']);
    }

    #[Test]
    public function it_retains_elements_that_exist_only_in_the_after_array()
    {
        // Given — after has more elements than before; the extra element would be silently
        // dropped by collect($before)->zip($after) since zip iterates over the caller's length
        $htmlDiff = $this->diffFor(['apple'], ['apple', 'banana']);

        // When
        $result = $htmlDiff->field('value');

        // Then — both elements are present
        $this->assertCount(2, $result['after']);
        $this->assertStringContainsString('banana', (string) $result['after'][1]);
    }

    #[Test]
    public function it_never_leaves_blank_entries_in_either_array()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple', 'banana', 'cherry'], ['apple', '', 'grape']);

        // When
        $result = $htmlDiff->field('value');

        // Then
        foreach ($result['before'] as $item) {
            $this->assertNotSame('', trim(strip_tags($item)));
        }

        foreach ($result['after'] as $item) {
            $this->assertNotSame('', trim(strip_tags($item)));
        }
    }

    #[Test]
    public function it_does_not_misdiff_the_surviving_neighbor_after_a_mid_array_removal()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple', 'banana', 'cherry'], ['apple', 'cherry']);

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertCount(3, $result['before']);
        $this->assertSame('apple', $result['before'][0]);
        $this->assertStringContainsString('<del>', (string) $result['before'][1]);
        $this->assertStringContainsString('banana', (string) $result['before'][1]);
        $this->assertSame('cherry', $result['before'][2]);

        $this->assertCount(2, $result['after']);
        $this->assertSame('apple', $result['after'][0]);
        $this->assertSame('cherry', $result['after'][1]);
    }

    #[Test]
    public function it_uses_a_unique_item_as_an_anchor_around_a_duplicated_item()
    {
        // Given
        $htmlDiff = $this->diffFor(
            ['Log in as admin', 'Divider', 'Verify dashboard loads', 'Log in as admin'],
            ['Divider', 'Verify dashboard loads correctly', 'Log in as admin'],
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before'][0]);
        $this->assertStringContainsString('Log in as admin', (string) $result['before'][0]);

        $this->assertSame('Divider', $result['before'][1]);
        $this->assertSame('Divider', $result['after'][0]);

        $this->assertStringContainsString('<ins>', (string) $result['after'][1]);
        $this->assertStringContainsString('correctly', (string) $result['after'][1]);

        $this->assertSame('Log in as admin', end($result['before']));
        $this->assertSame('Log in as admin', end($result['after']));
    }

    // Array lifecycle

    #[Test]
    public function it_returns_an_empty_before_array_when_the_field_had_no_prior_value()
    {
        // Given — the field never existed before (e.g. the initial revision), the after side is an array
        $htmlDiff = $this->diffFor(null, ['apple', 'banana']);

        // When
        $result = $htmlDiff->field('value');

        // Then — no blank placeholder lines on the before side, one per item on the after side
        $this->assertSame([], $result['before']);
        $this->assertCount(2, $result['after']);
        $this->assertStringContainsString('<ins>', (string) $result['after'][0]);
        $this->assertStringContainsString('apple', (string) $result['after'][0]);
    }

    #[Test]
    public function it_returns_an_empty_after_array_when_the_field_no_longer_has_a_value()
    {
        // Given — the field existed before but is now entirely gone
        $htmlDiff = $this->diffFor(['apple', 'banana'], null);

        // When
        $result = $htmlDiff->field('value');

        // Then — no blank placeholder lines on the after side, one per item on the before side
        $this->assertSame([], $result['after']);
        $this->assertCount(2, $result['before']);
        $this->assertStringContainsString('<del>', (string) $result['before'][0]);
        $this->assertStringContainsString('apple', (string) $result['before'][0]);
    }

    // Multiline

    #[Test]
    public function it_handles_multiline_strings()
    {
        // Given
        $htmlDiff = $this->diffFor("Line one\nLine two\nLine three", "Line one\nLine modified\nLine three");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('<br>', (string) $result['before']);
    }

    #[Test]
    public function it_keeps_unchanged_lines_that_sit_far_from_a_change()
    {
        // Given — only the first line changes, leaving the rest well beyond the differ's
        // default context window
        $tail = "\n\nSecond paragraph.\n\nThird paragraph.\n\nFourth paragraph.\n";
        $htmlDiff = $this->diffFor('The status is online.' . $tail, 'The status is offline.' . $tail);

        // When
        $result = $htmlDiff->field('value');

        // Then — every line survives on both sides, rather than being silently skipped
        $this->assertStringContainsString('Fourth paragraph.', (string) $result['before']);
        $this->assertStringContainsString('Fourth paragraph.', (string) $result['after']);
    }

    #[Test]
    public function it_keeps_line_breaks_on_an_unchanged_multiline_value()
    {
        // Given
        $htmlDiff = $this->diffFor("Line one\nLine two", "Line one\nLine two");

        // When
        $result = $htmlDiff->field('value');

        // Then — an unchanged value keeps the same shape as a changed one
        $this->assertSame('Line one<br>Line two', $result['before']);
        $this->assertSame('Line one<br>Line two', $result['after']);
    }

    #[Test]
    public function it_turns_authored_newlines_into_line_breaks_when_a_value_contains_html()
    {
        // Given — inline markup routes this through the HTML differ, where a raw newline
        // would otherwise be collapsed to a space by the browser
        $htmlDiff = $this->diffFor(
            "First line\nSecond <b>line</b>\nThird line",
            "First line\nSecond <b>lines</b>\nThird line",
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('First line<br>Second <b><del>line</del></b><br>Third line', $result['before']);
        $this->assertSame('First line<br>Second <b><ins>lines</ins></b><br>Third line', $result['after']);
    }

    #[Test]
    public function it_ignores_newlines_that_only_separate_block_level_tags()
    {
        // Given — newlines between block tags are source formatting, not authored breaks
        $htmlDiff = $this->diffFor("<p>one</p>\n\n<p>two</p>", "<p>one</p>\n\n<p>three</p>");

        // When
        $result = $htmlDiff->field('value');

        // Then — no stray line breaks or raw newlines are introduced between the paragraphs
        $this->assertSame('<p>one</p><p><del>two</del></p>', $result['before']);
        $this->assertSame('<p>one</p><p><ins>three</ins></p>', $result['after']);
    }

    #[Test]
    public function it_ignores_newlines_that_only_separate_list_items()
    {
        // Given
        $htmlDiff = $this->diffFor(
            "<ul>\n    <li>alpha</li>\n    <li>beta</li>\n</ul>",
            "<ul>\n    <li>alpha</li>\n    <li>gamma</li>\n</ul>",
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringNotContainsString('<br>', (string) $result['before']);
        $this->assertStringNotContainsString('<br>', (string) $result['after']);
        $this->assertStringContainsString('<del>beta</del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>gamma</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_marks_every_line_of_a_wholly_inserted_multiline_value()
    {
        // Given — a line with no counterpart carries its marker on the surrounding block
        // rather than inline, so it would otherwise come out looking unchanged
        $htmlDiff = $this->diffFor('', "Alpha.\n\nBravo.");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('', $result['before']);
        $this->assertSame('<ins>Alpha.</ins><br><br><ins>Bravo.</ins>', $result['after']);
    }

    #[Test]
    public function it_marks_every_line_of_a_wholly_deleted_multiline_value()
    {
        // Given — the mirror direction of a wholly inserted value
        $htmlDiff = $this->diffFor("Alpha.\n\nBravo.", '');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('<del>Alpha.</del><br><br><del>Bravo.</del>', $result['before']);
        $this->assertSame('', $result['after']);
    }

    #[Test]
    public function it_marks_a_line_inserted_between_unchanged_lines()
    {
        // Given
        $htmlDiff = $this->diffFor("Alpha.\nCharlie.", "Alpha.\nBravo.\nCharlie.");

        // When
        $result = $htmlDiff->field('value');

        // Then — only the new line is marked, the surrounding lines stay untouched
        $this->assertSame('Alpha.<br>Charlie.', $result['before']);
        $this->assertSame('Alpha.<br><ins>Bravo.</ins><br>Charlie.', $result['after']);
    }

    #[Test]
    public function it_marks_a_line_deleted_between_unchanged_lines()
    {
        // Given
        $htmlDiff = $this->diffFor("Alpha.\nBravo.\nCharlie.", "Alpha.\nCharlie.");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('Alpha.<br><del>Bravo.</del><br>Charlie.', $result['before']);
        $this->assertSame('Alpha.<br>Charlie.', $result['after']);
    }

    #[Test]
    public function it_marks_a_whole_multiline_value_as_inserted_when_diffing_from_nothing()
    {
        // Given — the shape of an initial revision, which has nothing to diff against
        $htmlDiff = $this->diffFromNothingFor("Alpha.\n\nBravo.\n");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('', $result['before']);
        $this->assertSame('<ins>Alpha.</ins><br><br><ins>Bravo.</ins><br>', $result['after']);
    }

    // Configuration

    #[Test]
    public function it_uses_char_level_detail_when_configured()
    {
        // Given
        $htmlDiff = new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => 'abcde']]]),
            new Revision(['metadata' => ['attributes' => ['value' => 'abXde']]]),
        )->asHtml(detailLevel: 'char');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
    }

    #[Test]
    public function it_returns_html_unmodified_when_detail_level_is_none()
    {
        // Given
        $before = '<p>Hello <strong>world</strong></p>';
        $after = '<p>Hello <strong>universe</strong></p>';
        $htmlDiff = new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => $before]]]),
            new Revision(['metadata' => ['attributes' => ['value' => $after]]]),
        )->asHtml(detailLevel: 'none');

        // When
        $result = $htmlDiff->field('value');

        // Then — the raw HTML is returned exactly as given, no diff markers or rewriting
        $this->assertSame($before, $result['before']);
        $this->assertSame($after, $result['after']);
    }

    #[Test]
    public function it_uses_the_configured_line_separator_for_multiline_values()
    {
        // Given
        $htmlDiff = new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => "Line one\nLine two"]]]),
            new Revision(['metadata' => ['attributes' => ['value' => "Line one\nLine changed"]]]),
        )->asHtml(lineSeparator: '<p>');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<p>', (string) $result['before']);
        $this->assertStringNotContainsString('<br>', (string) $result['before']);
    }

    // HTML-aware diffing — formatting changes

    #[Test]
    public function it_retains_content_in_before_view_when_formatting_is_removed()
    {
        // Given — only the <strong> wrapper is removed; the word itself is unchanged
        $htmlDiff = $this->diffFor(
            '<p><strong>Consequatur</strong> quas quia et et.</p>',
            '<p>Consequatur quas quia et et.</p>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the word must appear in both views, not just the after view,
        // and the before view marks it as a formatting change (not a deletion)
        $this->assertStringContainsString('<del class="mod">Consequatur</del>', (string) $result['before']);
        $this->assertStringContainsString('Consequatur', (string) $result['after']);
    }

    #[Test]
    public function it_marks_reformatted_words_when_only_formatting_changed()
    {
        // Given
        $htmlDiff = $this->diffFor(
            '<span>Consequatur quas quia et et.</span>',
            '<span><strong>Consequatur</strong> quas quia et et.</span>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringNotContainsString('&lt;', (string) $result['before']);
        $this->assertStringNotContainsString('&lt;', (string) $result['after']);
        $this->assertStringContainsString('<strong>', (string) $result['after']);
        $this->assertStringContainsString('<ins class="mod">', (string) $result['after']);
        $this->assertStringContainsString('Consequatur', (string) $result['after']);
    }

    #[Test]
    public function it_injects_del_and_ins_into_html_when_text_also_changed()
    {
        // Given
        $htmlDiff = $this->diffFor(
            '<span>Hello world</span>',
            '<span>Hello <strong>universe</strong></span>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('world', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('<strong>', (string) $result['after']);
        $this->assertStringContainsString('universe', (string) $result['after']);
        $this->assertStringNotContainsString('&lt;', (string) $result['after']);
    }

    #[Test]
    public function it_wraps_consecutive_reformatted_words_in_a_single_marker()
    {
        // Given
        $htmlDiff = $this->diffFor(
            '<span>Hello world foo</span>',
            '<span><strong>Hello world</strong> foo</span>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame(1, substr_count($result['after'], '<ins class="mod">'));
        $this->assertStringContainsString('<ins class="mod">Hello world</ins>', (string) $result['after']);
    }

    // HTML-aware diffing — preexisting content

    #[Test]
    public function it_keeps_preexisting_empty_elements_when_html_is_identical()
    {
        // Given — a genuinely empty <p> and <li> that were already there, unrelated to any insertion
        $htmlDiff = $this->diffFor(
            '<p>one</p><p></p><ul><li>two</li><li></li></ul>',
            '<p>one</p><p></p><ul><li>two</li><li></li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — both views stay identical to the input, nothing is stripped
        $this->assertSame($result['before'], $result['after']);
        $this->assertStringContainsString('<p></p>', (string) $result['before']);
        $this->assertStringContainsString('<li></li>', (string) $result['before']);
    }

    // HTML-aware diffing — wholly changed elements

    #[Test]
    public function it_omits_a_newly_added_list_item_from_the_before_view()
    {
        // Given — a third bullet was added; the old list never had it
        $htmlDiff = $this->diffFor(
            '<ul><li>one</li><li>two</li></ul>',
            '<ul><li>one</li><li>two</li><li>three</li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the before view still has exactly its original two bullets, no dangling empty one
        $this->assertSame(2, preg_match_all('/<li[^>]*>/', $result['before']));
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('three', (string) $result['after']);
    }

    #[Test]
    public function it_omits_a_newly_added_table_row_from_the_before_view()
    {
        // Given — a second row was added; stripping its inserted cell content
        // would otherwise leave an empty <tr><td></td></tr> behind
        $htmlDiff = $this->diffFor(
            '<table><tr><td>one</td></tr></table>',
            '<table><tr><td>one</td></tr><tr><td>two</td></tr></table>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the before view still has exactly its original single row, no dangling empty one
        $this->assertSame(1, preg_match_all('/<tr[^>]*>/', $result['before']));
        $this->assertSame(1, preg_match_all('/<td[^>]*>/', $result['before']));
        $this->assertStringContainsString('two', (string) $result['after']);
    }

    #[Test]
    public function it_omits_a_newly_added_nested_list_item_from_the_before_view()
    {
        // Given — the new bullet's text sits inside a <p>, one level deeper than a bare <li>
        $htmlDiff = $this->diffFor(
            '<ul><li><p>one</p></li></ul>',
            '<ul><li><p>one</p></li><li><p>two</p></li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the whole <li> is gone, not left behind as an empty <li><p></p></li>
        $this->assertSame(1, preg_match_all('/<li[^>]*>/', $result['before']));
        $this->assertStringNotContainsString('<p></p>', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('two', (string) $result['after']);
    }

    #[Test]
    public function it_omits_a_wholly_deleted_nested_list_item_from_the_after_view()
    {
        // Given — the second bullet's text was entirely cleared, tag structure left intact
        $htmlDiff = $this->diffFor(
            '<ul><li><p>one</p></li><li><p>two</p></li></ul>',
            '<ul><li><p>one</p></li><li><p></p></li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the whole <li> is gone from the after view, not left behind as <li><p></p></li>
        $this->assertSame(1, preg_match_all('/<li[^>]*>/', $result['after']));
        $this->assertStringNotContainsString('<p></p>', (string) $result['after']);
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('two', (string) $result['before']);
    }

    #[Test]
    public function it_omits_a_wholly_new_list_from_the_before_view()
    {
        // Given — the whole <ul> is new, not just some of its items
        $htmlDiff = $this->diffFor('', '<ul><li>one</li><li>two</li></ul>');

        // When
        $result = $htmlDiff->field('value');

        // Then — no dangling empty <ul></ul> left behind
        $this->assertSame('', $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
    }

    #[Test]
    public function it_omits_a_wholly_new_table_with_a_tbody_from_the_before_view()
    {
        // Given — removing the new rows would otherwise leave an empty <tbody></tbody>
        // inside an empty <table></table>
        $htmlDiff = $this->diffFor(
            '',
            '<table><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — both the table and its now-empty tbody wrapper are gone
        $this->assertSame('', $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
    }

    // HTML-aware diffing — structural mismatch (full swap)

    #[Test]
    public function it_treats_a_list_replaced_by_plain_text_as_a_full_swap()
    {
        // Given — shared words ("Quia dolore") would otherwise get trapped inside the old
        // <li> by the differ's positional word-pairing, splitting the plain text in two
        $htmlDiff = $this->diffFor(
            '<ul><li>Quia dolore non ut recusandae.</li></ul>',
            'Quia dolore is different now.',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the whole list is marked deleted, the whole plain text marked inserted,
        // with no plain-text fragments left dangling inside the old <li>
        $this->assertStringContainsString('<del>Quia dolore non ut recusandae.</del>', (string) $result['before']);
        $this->assertSame('<ins>Quia dolore is different now.</ins>', $result['after']);
    }

    #[Test]
    public function it_treats_plain_text_replaced_by_a_list_as_a_full_swap()
    {
        // Given — the mirror direction of the list-to-plain-text swap
        $htmlDiff = $this->diffFor(
            'Quia dolore non ut recusandae.',
            '<ul><li>Quia dolore is different now.</li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — no fragments of the old plain text left dangling inside the new <li>
        $this->assertSame('<del>Quia dolore non ut recusandae.</del>', $result['before']);
        $this->assertStringContainsString('<ins>Quia dolore is different now.</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_keeps_word_level_diffing_when_both_sides_are_lists()
    {
        // Given — both sides have list structure, so this must NOT degrade to a full swap
        $htmlDiff = $this->diffFor(
            '<ul><li>Alpha beta gamma.</li></ul>',
            '<ul><li>Zzz yyy xxx.</li></ul>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — word-level markers inside a single shared <li>, not a wholesale replacement
        $this->assertSame(1, preg_match_all('/<li[^>]*>/', $result['before']));
        $this->assertStringContainsString('<del>Alpha</del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>Zzz</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_treats_plain_text_replaced_by_paragraphs_as_a_full_swap()
    {
        // Given — a legacy plain-text value rewritten through a rich-text editor: word-pairing
        // the two would trap the old value's line breaks inside the new <p> elements
        $htmlDiff = $this->diffFor(
            "Quia dolore non ut recusandae.\n\nSecond paragraph.",
            '<p>Quia dolore is different now.</p><p>Second paragraph.</p>',
        );

        // When
        $result = $htmlDiff->field('value');

        // Then — the whole old value is marked deleted with its breaks intact, the whole new
        // value marked inserted
        $this->assertStringContainsString('<del>Quia dolore non ut recusandae.</del>', (string) $result['before']);
        $this->assertStringContainsString('<br>', (string) $result['before']);
        $this->assertStringNotContainsString('<p>', (string) $result['before']);
        $this->assertStringContainsString('<p><ins>Quia dolore is different now.</ins></p>', (string) $result['after']);
    }

    #[Test]
    public function it_keeps_word_level_diffing_when_both_sides_are_paragraphs()
    {
        // Given — both sides have paragraph structure, so this must NOT degrade to a full swap
        $htmlDiff = $this->diffFor('<p>Alpha beta gamma.</p>', '<p>Zzz beta gamma.</p>');

        // When
        $result = $htmlDiff->field('value');

        // Then — word-level markers inside a single shared <p>, not a wholesale replacement
        $this->assertSame(1, preg_match_all('/<p[^>]*>/', $result['before']));
        $this->assertStringContainsString('<del>Alpha</del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>Zzz</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_handles_plain_text_before_and_html_after()
    {
        // Given — inline HTML on only one side must NOT degrade to a full swap either
        $htmlDiff = $this->diffFor('Hello world', '<strong>Hello</strong> universe');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', (string) $result['before']);
        $this->assertStringContainsString('world', (string) $result['before']);
        $this->assertStringContainsString('<ins>', (string) $result['after']);
        $this->assertStringContainsString('<strong>', (string) $result['after']);
        $this->assertStringContainsString('universe', (string) $result['after']);
    }
}
