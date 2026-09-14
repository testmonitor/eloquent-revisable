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
    public function it_marks_an_inserted_item_as_added()
    {
        // Given
        $driver = new PlainDriver;

        // When
        // A naive index-by-index zip of ['a', 'c'] against ['a', 'b', 'c'] would read this
        // as 'c' changed into 'b' and 'c' added at the tail. Real alignment anchors on the
        // shared 'a' and 'c' and reports only 'b' as new.
        $result = ListDiff::for(['a', 'c'], ['a', 'b', 'c'], $driver);

        // Then
        $this->assertSame(
            [ChangeType::Kept, ChangeType::Added, ChangeType::Kept],
            $this->statuses($result->items),
        );
    }

    #[Test]
    public function it_marks_a_dropped_item_as_removed()
    {
        // Given
        $driver = new PlainDriver;

        // When
        // A naive index-by-index zip of ['a', 'b', 'c'] against ['b', 'c'] would read this
        // as 'a' changed into 'b', 'b' changed into 'c', and 'c' removed at the tail. Real
        // alignment anchors on the shared 'b' and 'c' and reports only 'a' as gone.
        $result = ListDiff::for(['a', 'b', 'c'], ['b', 'c'], $driver);

        // Then
        $this->assertSame(
            [ChangeType::Removed, ChangeType::Kept, ChangeType::Kept],
            $this->statuses($result->items),
        );
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
        // 'new' is inserted at the front, shifting 'the brown fox' and 'end' one position
        // to the right. A naive index-by-index zip would compare every entry against its
        // neighbour and see nothing but Changed/Added. Real alignment anchors on the shared
        // 'keep' and 'end' and still pairs the edited sentence with its earlier self.
        $result = ListDiff::for(
            ['keep', 'the brown fox', 'end'],
            ['new', 'keep', 'the red fox', 'end'],
            $driver,
        );

        // Then
        $this->assertSame(
            [ChangeType::Added, ChangeType::Kept, ChangeType::Changed, ChangeType::Kept],
            $this->statuses($result->items),
        );
        $this->assertSame('the <del>brown</del> fox', $result->items[2]->beforeHtml);
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
        // 'x' is removed from the front, shifting 'a' and 'b' one position to the left. A
        // naive index-by-index zip would read this as 'x' changed into 'a', 'a' changed
        // into 'b', and 'b' removed at the tail: [Changed, Changed, Removed]. Real alignment
        // anchors on the shared 'a' and reports 'x' removed and 'b' edited into 'c'. Both
        // hypotheses land on the same overall Changed status, so the item-level statuses
        // are what actually distinguishes them.
        $result = ListDiff::for(['x', 'a', 'b'], ['a', 'c'], $driver);

        // Then
        $this->assertSame(
            [ChangeType::Removed, ChangeType::Kept, ChangeType::Changed],
            $this->statuses($result->items),
        );
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
    public function it_drops_false_but_keeps_zero_and_true_as_text()
    {
        // Given
        $value = ['one', null, '', 0, false, true, 'two'];

        // When / Then
        // false casts to an empty string, same as null and '', so it must be dropped too.
        // 0 and true cast to non-empty strings ('0' and '1'), so they must survive.
        $this->assertSame(['one', '0', '1', 'two'], ListDiff::entries($value));
    }

    #[Test]
    public function it_drops_a_false_entry_decoded_from_json()
    {
        // Given
        $value = json_encode(['a', false, 'b', true]);

        // When / Then
        $this->assertSame(['a', 'b', '1'], ListDiff::entries($value));
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
