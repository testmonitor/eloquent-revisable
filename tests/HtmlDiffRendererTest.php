<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Renderers\HtmlDiff;

class HtmlDiffRendererTest extends TestCase
{
    protected function diffFor(mixed $old, mixed $new, string $field = 'value'): HtmlDiff
    {
        $before = new Revision(['metadata' => ['attributes' => [$field => $old]]]);
        $after = new Revision(['metadata' => ['attributes' => [$field => $new]]]);

        return (new Diff($before, $after))->asHtml();
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
        $this->assertStringNotContainsString('<ins>', $result['before']);
        $this->assertStringNotContainsString('<del>', $result['after']);
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
    }

    #[Test]
    public function it_marks_deleted_words_in_the_before_view()
    {
        // Given
        $htmlDiff = $this->diffFor('hello world', 'hello');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('world', $result['before']);
        $this->assertStringNotContainsString('<ins>', $result['before']);
    }

    #[Test]
    public function it_marks_inserted_words_in_the_after_view()
    {
        // Given
        $htmlDiff = $this->diffFor('hello', 'hello world');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('world', $result['after']);
        $this->assertStringNotContainsString('<del>', $result['after']);
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
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('new content', $result['after']);
    }

    #[Test]
    public function it_handles_empty_new_value()
    {
        // Given
        $htmlDiff = $this->diffFor('old content', '');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('old content', $result['before']);
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
        $this->assertStringContainsString('<ins>', $result['after'][1]);
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
        $this->assertStringContainsString('banana', $result['after'][1]);
    }

    #[Test]
    public function it_keeps_before_and_after_arrays_aligned_after_filtering()
    {
        // Given — second pair has empty after, which under independent filtering would remove it from
        // after but keep it in before (due to the strip_tags difference), shifting subsequent indexes
        $htmlDiff = $this->diffFor(['apple', 'banana', 'cherry'], ['apple', '', 'grape']);

        // When
        $result = $htmlDiff->field('value');

        // Then — both arrays must have the same length
        $this->assertCount(count($result['before']), $result['after']);

        // And corresponding entries must be the same pair
        foreach (array_keys($result['before']) as $i) {
            $this->assertArrayHasKey($i, $result['after']);
        }
    }

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
        $this->assertStringContainsString('<ins>', $result['after'][0]);
        $this->assertStringContainsString('apple', $result['after'][0]);
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
        $this->assertStringContainsString('<del>', $result['before'][0]);
        $this->assertStringContainsString('apple', $result['before'][0]);
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
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('<br>', $result['before']);
    }

    // Configuration

    #[Test]
    public function it_uses_char_level_detail_when_configured()
    {
        // Given
        $htmlDiff = (new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => 'abcde']]]),
            new Revision(['metadata' => ['attributes' => ['value' => 'abXde']]]),
        ))->asHtml(detailLevel: 'char');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
    }

    #[Test]
    public function it_returns_html_unmodified_when_detail_level_is_none()
    {
        // Given
        $before = '<p>Hello <strong>world</strong></p>';
        $after = '<p>Hello <strong>universe</strong></p>';
        $htmlDiff = (new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => $before]]]),
            new Revision(['metadata' => ['attributes' => ['value' => $after]]]),
        ))->asHtml(detailLevel: 'none');

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
        $htmlDiff = (new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => "Line one\nLine two"]]]),
            new Revision(['metadata' => ['attributes' => ['value' => "Line one\nLine changed"]]]),
        ))->asHtml(lineSeparator: '<p>');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<p>', $result['before']);
        $this->assertStringNotContainsString('<br>', $result['before']);
    }

    // HTML-aware diffing

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
        $this->assertStringContainsString('<del class="mod">Consequatur</del>', $result['before']);
        $this->assertStringContainsString('Consequatur', $result['after']);
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
        $this->assertStringNotContainsString('&lt;', $result['before']);
        $this->assertStringNotContainsString('&lt;', $result['after']);
        $this->assertStringContainsString('<strong>', $result['after']);
        $this->assertStringContainsString('<ins class="mod">', $result['after']);
        $this->assertStringContainsString('Consequatur', $result['after']);
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
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('world', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('<strong>', $result['after']);
        $this->assertStringContainsString('universe', $result['after']);
        $this->assertStringNotContainsString('&lt;', $result['after']);
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
        $this->assertStringContainsString('<ins class="mod">Hello world</ins>', $result['after']);
    }

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
        $this->assertStringContainsString('<p></p>', $result['before']);
        $this->assertStringContainsString('<li></li>', $result['before']);
    }

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
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('three', $result['after']);
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
        $this->assertStringContainsString('two', $result['after']);
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
        $this->assertStringNotContainsString('<p></p>', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('two', $result['after']);
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
        $this->assertStringNotContainsString('<p></p>', $result['after']);
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('two', $result['before']);
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
        $this->assertStringContainsString('<ins>', $result['after']);
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
        $this->assertStringContainsString('<ins>', $result['after']);
    }

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
        $this->assertStringContainsString('<del>Quia dolore non ut recusandae.</del>', $result['before']);
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
        $this->assertStringContainsString('<ins>Quia dolore is different now.</ins>', $result['after']);
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
        $this->assertStringContainsString('<del>Alpha</del>', $result['before']);
        $this->assertStringContainsString('<ins>Zzz</ins>', $result['after']);
    }

    #[Test]
    public function it_handles_plain_text_before_and_html_after()
    {
        // Given
        $htmlDiff = $this->diffFor('Hello world', '<strong>Hello</strong> universe');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('world', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
        $this->assertStringContainsString('<strong>', $result['after']);
        $this->assertStringContainsString('universe', $result['after']);
    }
}
