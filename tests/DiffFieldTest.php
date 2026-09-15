<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Diffing\FieldDiff;
use TestMonitor\Revisable\Diffing\PlainDiffer;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;
use TestMonitor\Revisable\Models\Revision;

final class DiffFieldTest extends TestCase
{
    protected function diffFor(mixed $old, mixed $new, string $field = 'value'): Diff
    {
        $before = new Revision(['metadata' => ['attributes' => [$field => $old]]]);
        $after = new Revision(['metadata' => ['attributes' => [$field => $new]]]);

        return new Diff($before, $after);
    }

    // Diffing a field

    #[Test]
    public function it_diffs_a_field_with_the_plain_differ_by_default()
    {
        // Given
        $diff = $this->diffFor('the brown fox', 'the red fox');

        // When
        $result = $diff->field('value');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('the <del>brown</del> fox', $result->beforeHtml);
        $this->assertSame('the <ins>red</ins> fox', $result->afterHtml);
    }

    #[Test]
    public function it_returns_the_before_and_after_views_as_an_array()
    {
        // Given
        $diff = $this->diffFor('the brown fox', 'the red fox');

        // When
        $html = $diff->field('value')->toHtml();

        // Then
        $this->assertSame('the <del>brown</del> fox', $html['before']);
        $this->assertSame('the <ins>red</ins> fox', $html['after']);
    }

    #[Test]
    public function it_diffs_a_numeric_value_as_text()
    {
        // Given
        $diff = $this->diffFor(10, 20);

        // When
        $result = $diff->field('value');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
        $this->assertSame('<del>10</del>', $result->beforeHtml);
        $this->assertSame('<ins>20</ins>', $result->afterHtml);
    }

    #[Test]
    public function it_diffs_a_json_array_field_as_a_list()
    {
        // Given
        $diff = $this->diffFor(json_encode(['keep', 'gone']), json_encode(['keep', 'fresh']));

        // When
        $result = $diff->list('value');

        // Then
        // 'gone' and 'fresh' occupy the same position in an otherwise-equal-length gap, so
        // ArrayAligner pairs them for a word diff (Changed) rather than reporting them as an
        // unrelated Removed/Added pair. See ListDiffTest::it_marks_an_edited_item_as_changed_rather_than_removed_and_added.
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Changed],
            array_map(fn ($item) => $item->status, $result->items),
        );
    }

    #[Test]
    public function it_wraps_a_scalar_value_as_a_single_item_list()
    {
        // Given
        $diff = $this->diffFor('just a string', 'just a string');

        // When
        $result = $diff->list('value');

        // Then
        $this->assertCount(1, $result->items);
        $this->assertSame(ChangeType::Kept, $result->items[0]->status);
    }

    // Choosing a differ

    #[Test]
    public function it_resolves_a_differ_by_name()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // When
        $result = $diff->field('value', 'plain');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    #[Test]
    public function it_resolves_the_markdown_differ_by_name()
    {
        // Given
        $diff = $this->diffFor('The brown fox.', 'The red fox.');

        // When
        $result = $diff->field('value', 'markdown');

        // Then
        $this->assertStringContainsString('<p>', $result->afterHtml);
        $this->assertStringContainsString('<ins>red</ins>', $result->afterHtml);
    }

    #[Test]
    public function it_accepts_a_differ_instance_for_one_off_configuration()
    {
        // Given
        $diff = $this->diffFor("one\ntwo", "one\ntwo");

        // When
        $result = $diff->field('value', new PlainDiffer(separator: ' / '));

        // Then
        $this->assertSame('one / two', $result->beforeHtml);
    }

    #[Test]
    public function it_diffs_a_markdown_list_field()
    {
        // Given
        $diff = $this->diffFor(
            json_encode(['Install deps', 'Run **tests**', 'Deploy']),
            json_encode(['Install deps', 'Run tests', 'Ship it']),
        );

        // When
        $result = $diff->list('value', 'markdown');

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Changed, ChangeType::Changed],
            array_map(fn ($item) => $item->status, $result->items),
        );

        // The wording is unchanged, only the emphasis was dropped, so just that word is
        // marked rather than the whole entry.
        $this->assertStringContainsString('Run <ins class="mod">tests</ins>', $result->items[1]->afterHtml);
        $this->assertStringContainsString('Ship it', $result->items[2]->afterHtml);
    }

    #[Test]
    public function it_throws_for_an_unknown_differ_name()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // Then
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('nonsense');

        // When
        $diff->field('value', 'nonsense');
    }

    // Fields that are not tracked

    #[Test]
    public function it_returns_null_for_an_untracked_field()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // When / Then
        $this->assertNull($diff->field('nonexistent'));
    }

    #[Test]
    public function it_returns_null_for_an_untracked_list_field()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // When / Then
        $this->assertNull($diff->list('nonexistent'));
    }

    #[Test]
    public function it_returns_null_for_a_tracked_relation_name()
    {
        // Given
        // Raw relation metadata, shaped as in RevisionDiffTest::it_returns_the_raw_before_and_after_metadata:
        // `get()` returns an added/removed/kept/changed entry for this name, not a before/after one.
        $before = new Revision(['metadata' => ['relations' => ['tags' => ['pivots' => []]]]]);
        $after = new Revision(['metadata' => ['relations' => ['tags' => ['pivots' => []]]]]);

        $diff = new Diff($before, $after);

        // When / Then
        $this->assertNull($diff->field('tags'));
        $this->assertNull($diff->list('tags'));
    }

    // Values that are not scalars

    #[Test]
    public function it_throws_when_a_field_holding_a_list_is_read_as_a_scalar()
    {
        // Given
        $diff = $this->diffFor(json_encode(['a', 'b']), json_encode(['a', 'c']));

        // Then
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The field `value` holds a list. Use `list()` instead of `field()` to diff it.');

        // When
        $diff->field('value');
    }

    #[Test]
    public function it_throws_when_a_field_holding_a_decoded_array_is_read_as_a_scalar()
    {
        // Given
        $diff = $this->diffFor(['a', 'b'], ['a', 'c']);

        // Then
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The field `value` holds a list.');

        // When
        $diff->field('value');
    }

    #[Test]
    public function it_does_not_throw_for_a_json_object_field()
    {
        // Given
        // A JSON object decodes to a PHP array too, same as a JSON list, but it isn't a
        // list: there's no object-diffing differ, so it must fall through to plain text
        // instead of being mistaken for a list and thrown as fieldIsList().
        $diff = $this->diffFor(json_encode(['a' => 1, 'b' => 2]), json_encode(['a' => 1, 'b' => 3]));

        // When
        $result = $diff->field('value');

        // Then
        $this->assertInstanceOf(FieldDiff::class, $result);
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    #[Test]
    public function it_treats_json_scalars_and_stray_brackets_as_plain_text_not_lists()
    {
        // Given / When / Then
        foreach (['5', '"text"', 'true', 'null', 'a [bracket] in prose'] as $value) {
            $diff = $this->diffFor($value, $value);

            $this->assertInstanceOf(FieldDiff::class, $diff->field('value'));
        }
    }
}
