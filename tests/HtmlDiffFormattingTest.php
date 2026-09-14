<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Renderers\HtmlDiff;

final class HtmlDiffFormattingTest extends TestCase
{
    /**
     * Exposes the protected diffHtmlValue() so it can be exercised directly - it's only ever
     * called by a subclass that has already rendered a value to HTML (e.g. from markdown).
     */
    protected function diffHtmlValue(mixed $before, mixed $after, string $detailLevel = 'word'): array
    {
        $diff = new Diff(
            new Revision(['metadata' => ['attributes' => ['value' => $before]]]),
            new Revision(['metadata' => ['attributes' => ['value' => $after]]]),
        );

        $htmlDiff = new class($diff, $detailLevel) extends HtmlDiff
        {
            public function diff(string $before, string $after): array
            {
                return $this->diffHtmlValue($before, $after);
            }
        };

        return $htmlDiff->diff($before, $after);
    }

    #[Test]
    public function it_leaves_an_unchanged_value_as_plain_text()
    {
        // Given — identical before and after fall straight through to the plain-text diff,
        // which only ever compares stripped text (same as any other unchanged field)
        $result = $this->diffHtmlValue('<p>Setup step.</p>', '<p>Setup step.</p>');

        // Then
        $this->assertSame('Setup step.', $result['before']);
        $this->assertSame('Setup step.', $result['after']);
    }

    #[Test]
    public function it_highlights_only_the_word_whose_formatting_changed()
    {
        // Given — the text is identical, only "Setup step." became bold
        $before = '<p>Setup step. Do the other thing.</p>';
        $after = '<p><strong>Setup step.</strong> Do the other thing.</p>';

        // When
        $result = $this->diffHtmlValue($before, $after);

        // Then — before is untouched, after highlights just the changed word with real formatting
        $this->assertSame($before, $result['before']);
        $this->assertStringContainsString('<strong><ins class="mod">Setup step.</ins></strong>', (string) $result['after']);
        $this->assertStringContainsString('Do the other thing.', (string) $result['after']);
    }

    #[Test]
    public function it_highlights_a_removed_formatting_the_same_way()
    {
        // Given — the text is identical, "Setup step." lost its bold
        $before = '<p><strong>Setup step.</strong> Do the other thing.</p>';
        $after = '<p>Setup step. Do the other thing.</p>';

        // When
        $result = $this->diffHtmlValue($before, $after);

        // Then
        $this->assertSame($before, $result['before']);
        $this->assertStringContainsString('<ins class="mod">Setup step.</ins>', (string) $result['after']);
        $this->assertStringNotContainsString('<strong>', (string) $result['after']);
    }

    #[Test]
    public function it_falls_back_to_a_plain_text_diff_when_the_text_actually_changed()
    {
        // Given
        $before = '<p>Original name.</p>';
        $after = '<p>Updated name.</p>';

        // When
        $result = $this->diffHtmlValue($before, $after);

        // Then — plain word diff, no real HTML tags reconstructed
        $this->assertStringNotContainsString('<p>', (string) $result['before']);
        $this->assertStringContainsString('<del>Original</del>', (string) $result['before']);
        $this->assertStringContainsString('<ins>Updated</ins>', (string) $result['after']);
    }

    #[Test]
    public function it_skips_highlighting_entirely_at_detail_level_none()
    {
        // Given
        $before = '<p>Setup step.</p>';
        $after = '<p><strong>Setup step.</strong></p>';

        // When
        $result = $this->diffHtmlValue($before, $after, detailLevel: 'none');

        // Then — no highlighting logic runs at all, just the plain stripped text
        $this->assertSame('Setup step.', $result['before']);
        $this->assertSame('Setup step.', $result['after']);
    }
}
