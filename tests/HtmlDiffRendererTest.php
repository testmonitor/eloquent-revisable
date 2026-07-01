<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Renderers\HtmlDiff;

class HtmlDiffRendererTest extends TestCase
{
    protected function diffFor(mixed $old, mixed $new, string $field = 'value'): HtmlDiff
    {
        $before = ['attributes' => [$field => $old]];
        $after = ['attributes' => [$field => $new]];

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
            ['attributes' => ['value' => 'abcde']],
            ['attributes' => ['value' => 'abXde']],
        ))->asHtml(detailLevel: 'char');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('<del>', $result['before']);
        $this->assertStringContainsString('<ins>', $result['after']);
    }

    #[Test]
    public function it_uses_the_configured_line_separator_for_multiline_values()
    {
        // Given
        $htmlDiff = (new Diff(
            ['attributes' => ['value' => "Line one\nLine two"]],
            ['attributes' => ['value' => "Line one\nLine changed"]],
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
        $this->assertStringContainsString('Consequatur', $result['before']);
        $this->assertStringContainsString('<del class="mod">', $result['before']);
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
    public function it_returns_html_unmodified_when_detail_level_is_none()
    {
        // Given
        $htmlDiff = (new Diff(
            ['attributes' => ['value' => '<p>Hello <strong>world</strong></p>']],
            ['attributes' => ['value' => '<p>Hello <strong>universe</strong></p>']],
        ))->asHtml(detailLevel: 'none');

        // When
        $result = $htmlDiff->field('value');

        // Then — no diff markers, raw HTML returned as-is
        $this->assertStringNotContainsString('<ins>', $result['before']);
        $this->assertStringNotContainsString('<del>', $result['before']);
        $this->assertStringNotContainsString('<ins>', $result['after']);
        $this->assertStringNotContainsString('<del>', $result['after']);
        $this->assertStringContainsString('<strong>', $result['before']);
        $this->assertStringContainsString('<strong>', $result['after']);
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
