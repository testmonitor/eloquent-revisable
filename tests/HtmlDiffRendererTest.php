<?php

namespace TestMonitor\Revisable\Tests;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Renderers\FieldType;
use TestMonitor\Revisable\Renderers\HtmlDiff;

final class HtmlDiffRendererTest extends TestCase
{
    protected function diffFor(mixed $old, mixed $new, string $field = 'value'): HtmlDiff
    {
        $before = new Revision(['metadata' => ['attributes' => [$field => $old]]]);
        $after = new Revision(['metadata' => ['attributes' => [$field => $new]]]);

        return new Diff($before, $after)->asHtml();
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

    // Encoding

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
    public function it_treats_html_looking_text_as_literal_text()
    {
        // Given
        $htmlDiff = $this->diffFor('<b>hello</b>', '<b>hello</b>');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('&lt;b&gt;hello&lt;/b&gt;', $result['before']);
        $this->assertSame('&lt;b&gt;hello&lt;/b&gt;', $result['after']);
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
    public function it_treats_a_null_before_value_as_an_empty_string()
    {
        // Given
        $htmlDiff = $this->diffFor(null, 'something');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('', (string) $result['before']);
        $this->assertStringContainsString('<ins>something</ins>', (string) $result['after']);
    }

    // Lists

    #[Test]
    public function it_decodes_json_strings_before_diffing()
    {
        // Given
        $htmlDiff = $this->diffFor(
            json_encode(['apple', 'banana']),
            json_encode(['apple', 'cherry']),
        );

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

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
        $result = $htmlDiff->field('value', FieldType::PlainList);

        // Then
        $this->assertIsArray($result['before']);
        $this->assertIsArray($result['after']);
        $this->assertSame('apple', $result['before'][0]);
        $this->assertStringContainsString('<ins>', (string) $result['after'][1]);
    }

    #[Test]
    public function it_wraps_a_scalar_side_in_an_array_when_the_other_side_is_an_array()
    {
        // Given
        $htmlDiff = $this->diffFor('apple', json_encode(['apple', 'banana']));

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

        // Then
        $this->assertIsArray($result['before']);
        $this->assertIsArray($result['after']);
        $this->assertNotEmpty($result['before']);
    }

    #[Test]
    public function it_retains_elements_that_exist_only_in_the_after_array()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple'], ['apple', 'banana']);

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

        // Then
        $this->assertCount(2, $result['after']);
        $this->assertStringContainsString('banana', (string) $result['after'][1]);
    }

    #[Test]
    public function it_never_leaves_blank_entries_in_either_array()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple', 'banana', 'cherry'], ['apple', '', 'grape']);

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

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
        $result = $htmlDiff->field('value', FieldType::PlainList);

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
        $result = $htmlDiff->field('value', FieldType::PlainList);

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

    #[Test]
    public function it_returns_an_empty_before_array_when_the_field_had_no_prior_value()
    {
        // Given
        $htmlDiff = $this->diffFor(null, ['apple', 'banana']);

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

        // Then
        $this->assertSame([], $result['before']);
        $this->assertCount(2, $result['after']);
        $this->assertStringContainsString('<ins>', (string) $result['after'][0]);
        $this->assertStringContainsString('apple', (string) $result['after'][0]);
    }

    #[Test]
    public function it_returns_an_empty_after_array_when_the_field_no_longer_has_a_value()
    {
        // Given
        $htmlDiff = $this->diffFor(['apple', 'banana'], null);

        // When
        $result = $htmlDiff->field('value', FieldType::PlainList);

        // Then
        $this->assertSame([], $result['after']);
        $this->assertCount(2, $result['before']);
        $this->assertStringContainsString('<del>', (string) $result['before'][0]);
        $this->assertStringContainsString('apple', (string) $result['before'][0]);
    }

    // Rendered fields

    protected function renderer(): Closure
    {
        return fn (string $value) => '<p>' . preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $value) . '</p>';
    }

    #[Test]
    public function it_renders_before_diffing_a_changed_value()
    {
        // Given
        $htmlDiff = $this->diffFor('Original name', 'Updated name');

        // When
        $result = $htmlDiff->field('value', FieldType::Html, $this->renderer());

        // Then
        $this->assertStringContainsString('<del>Original</del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>Updated</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_diffs_new_array_items_as_rendered_plain_text()
    {
        // Given
        $htmlDiff = $this->diffFor(null, json_encode(['**Step one**', 'Step two']));

        // When
        $result = $htmlDiff->field('value', FieldType::HtmlList, $this->renderer());

        // Then
        $this->assertSame([], $result['before']);
        $this->assertSame(['<ins>Step one</ins>', '<ins>Step two</ins>'], $result['after']);
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
    public function it_keeps_line_breaks_on_an_unchanged_multiline_value()
    {
        // Given
        $htmlDiff = $this->diffFor("Line one\nLine two", "Line one\nLine two");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('Line one<br>Line two', (string) $result['before']);
        $this->assertSame('Line one<br>Line two', (string) $result['after']);
    }

    #[Test]
    public function it_keeps_unchanged_lines_that_sit_far_from_a_change()
    {
        // Given
        $tail = "\n\nSecond paragraph.\n\nThird paragraph.\n\nFourth paragraph.\n";
        $htmlDiff = $this->diffFor('The status is online.' . $tail, 'The status is offline.' . $tail);

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertStringContainsString('Fourth paragraph.', (string) $result['before']);
        $this->assertStringContainsString('Fourth paragraph.', (string) $result['after']);
    }

    #[Test]
    public function it_marks_every_line_of_a_wholly_inserted_multiline_value()
    {
        // Given
        $htmlDiff = $this->diffFor('', "Alpha.\n\nBravo.");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('', (string) $result['before']);
        $this->assertSame('<ins>Alpha.</ins><br><br><ins>Bravo.</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_leaves_a_blank_line_unwrapped_within_a_wholly_inserted_value()
    {
        // Given
        $htmlDiff = $this->diffFor('', "Alpha.\n\nBravo.\n");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('<ins>Alpha.</ins><br><br><ins>Bravo.</ins><br>', (string) $result['after']);
    }

    #[Test]
    public function it_marks_every_line_of_a_wholly_deleted_multiline_value()
    {
        // Given
        $htmlDiff = $this->diffFor("Alpha.\n\nBravo.", '');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('<del>Alpha.</del><br><br><del>Bravo.</del>', (string) $result['before']);
        $this->assertSame('', (string) $result['after']);
    }

    #[Test]
    public function it_marks_a_line_inserted_between_unchanged_lines()
    {
        // Given
        $htmlDiff = $this->diffFor("Alpha.\nCharlie.", "Alpha.\nBravo.\nCharlie.");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('Alpha.<br>Charlie.', (string) $result['before']);
        $this->assertSame('Alpha.<br><ins>Bravo.</ins><br>Charlie.', (string) $result['after']);
    }

    #[Test]
    public function it_marks_a_line_deleted_between_unchanged_lines()
    {
        // Given
        $htmlDiff = $this->diffFor("Alpha.\nBravo.\nCharlie.", "Alpha.\nCharlie.");

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('Alpha.<br><del>Bravo.</del><br>Charlie.', (string) $result['before']);
        $this->assertSame('Alpha.<br>Charlie.', (string) $result['after']);
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

        // Then
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
}
