<?php

namespace TestMonitor\Revisable\Tests\Diffing;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\FieldDiff;
use TestMonitor\Revisable\Diffing\ListDiff;
use TestMonitor\Revisable\Diffing\PlainDriver;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class ListDiffTest extends TestCase
{
    /**
     * @param list<FieldDiff> $items
     * @return list<ChangeType>
     */
    protected function statuses(array $items): array
    {
        return array_map(fn (FieldDiff $item) => $item->status, $items);
    }

    // Item statuses

    #[Test]
    public function it_keeps_items_present_on_both_sides()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['a', 'b'], ['a', 'b'], $driver);

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Kept], $this->statuses($result->items));
        $this->assertSame(ChangeType::Kept, $result->status);
    }

    #[Test]
    public function it_marks_an_appended_item_as_added()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['a'], ['a', 'b'], $driver);

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Added], $this->statuses($result->items));
    }

    #[Test]
    public function it_marks_a_dropped_item_as_removed()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['a', 'b'], ['a'], $driver);

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Removed], $this->statuses($result->items));
    }

    #[Test]
    public function it_does_not_shift_later_items_when_an_early_item_is_removed()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['first', 'second', 'third'], ['first', 'third'], $driver);

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Removed, ChangeType::Kept],
            $this->statuses($result->items),
        );
    }

    #[Test]
    public function it_marks_an_edited_item_as_changed_rather_than_removed_and_added()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['keep', 'the brown fox'], ['keep', 'the red fox'], $driver);

        // Then
        $this->assertSame([ChangeType::Kept, ChangeType::Changed], $this->statuses($result->items));
        $this->assertSame('the <del>brown</del> fox', $result->items[1]->beforeHtml);
    }

    // List status

    #[Test]
    public function it_reports_the_whole_list_as_added_when_every_item_is_new()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for([], ['a', 'b'], $driver);

        // Then
        $this->assertSame(ChangeType::Added, $result->status);
    }

    #[Test]
    public function it_reports_the_whole_list_as_removed_when_every_item_is_gone()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['a', 'b'], [], $driver);

        // Then
        $this->assertSame(ChangeType::Removed, $result->status);
    }

    #[Test]
    public function it_reports_the_whole_list_as_changed_when_items_differ_in_kind()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for(['a', 'b'], ['a', 'c'], $driver);

        // Then
        $this->assertSame(ChangeType::Changed, $result->status);
    }

    #[Test]
    public function it_reports_an_empty_list_as_kept()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $result = ListDiff::for([], [], $driver);

        // Then
        $this->assertSame(ChangeType::Kept, $result->status);
        $this->assertSame([], $result->items);
    }

    // Rendering

    #[Test]
    public function it_omits_added_items_from_the_before_view_and_removed_items_from_the_after_view()
    {
        // Given
        $driver = new PlainDriver;

        // When
        $html = ListDiff::for(['keep', 'gone'], ['keep', 'fresh'], $driver)->toHtml();

        // Then
        $this->assertSame(['keep', '<del>gone</del>'], $html['before']);
        $this->assertSame(['keep', '<ins>fresh</ins>'], $html['after']);
    }

    // Entry decoding

    #[Test]
    public function it_decodes_a_json_array_into_entries()
    {
        // Given
        $value = json_encode(['one', 'two']);

        // When / Then
        $this->assertSame(['one', 'two'], ListDiff::entries($value));
    }

    #[Test]
    public function it_treats_a_plain_string_as_a_single_entry()
    {
        // Given
        $value = 'not json at all';

        // When / Then
        $this->assertSame(['not json at all'], ListDiff::entries($value));
    }

    #[Test]
    public function it_accepts_an_array_value_as_is()
    {
        // Given
        $value = ['one', 'two'];

        // When / Then
        $this->assertSame(['one', 'two'], ListDiff::entries($value));
    }

    #[Test]
    public function it_filters_out_null_and_empty_entries()
    {
        // Given
        $value = ['one', null, '', 'two'];

        // When / Then
        $this->assertSame(['one', 'two'], ListDiff::entries($value));
    }

    #[Test]
    public function it_returns_no_entries_for_a_null_value()
    {
        // Given
        $value = null;

        // When / Then
        $this->assertSame([], ListDiff::entries($value));
    }
}
