<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Diffing\PlainDriver;
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
    public function it_diffs_a_field_with_the_plain_driver_by_default()
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
    public function it_resolves_a_driver_by_name()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // When
        $result = $diff->field('value', 'plain');

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    #[Test]
    public function it_accepts_a_driver_instance_for_one_off_configuration()
    {
        // Given
        $diff = $this->diffFor("one\ntwo", "one\ntwo");

        // When
        $result = $diff->field('value', new PlainDriver(separator: ' / '));

        // Then
        $this->assertSame('one / two', $result->beforeHtml);
    }

    #[Test]
    public function it_throws_for_an_unknown_driver_name()
    {
        // Given
        $diff = $this->diffFor('old', 'new');

        // Then
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('nonsense');

        // When
        $diff->field('value', 'nonsense');
    }

    #[Test]
    public function it_throws_when_a_field_holding_a_list_is_read_as_a_scalar()
    {
        // Given
        $diff = $this->diffFor(json_encode(['a', 'b']), json_encode(['a', 'c']));

        // Then
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('value');

        // When
        $diff->field('value');
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
}
