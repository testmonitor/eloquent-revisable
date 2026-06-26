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
    public function it_returns_identical_strings_unchanged()
    {
        // Given
        $htmlDiff = $this->diffFor('hello world', 'hello world');

        // When
        $result = $htmlDiff->field('value');

        // Then
        $this->assertSame('hello world', $result['before']);
        $this->assertSame('hello world', $result['after']);
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
}
