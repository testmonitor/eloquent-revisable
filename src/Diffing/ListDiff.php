<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Contracts\Differ;
use TestMonitor\Revisable\Diffing\Support\ArrayAligner;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * A diffed list field, its items aligned by the differ's key so edits pair with their originals.
 */
readonly class ListDiff
{
    /**
     * @param list<FieldDiff> $items
     */
    public function __construct(
        public ChangeType $status,
        public array $items,
    ) {}

    /**
     * Align two entry lists and diff each aligned pair with the given differ.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    public static function for(array $before, array $after, Differ $differ): self
    {
        $items = [];

        foreach (new ArrayAligner($before, $after, fn (mixed $entry) => $differ->key((string) $entry))->align() as $aligned) {
            foreach (array_map(null, $aligned->before, $aligned->after) as [$beforeEntry, $afterEntry]) {
                $items[] = $differ->diff($beforeEntry, $afterEntry);
            }
        }

        return new self(static::statusFor($items), $items);
    }

    /**
     * Normalise a stored value into a list of entries.
     *
     * @return list<string>
     */
    public static function entries(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        $entries = is_array($decoded) ? $decoded : [$value];

        return collect($entries)
            // Anything non-scalar is encoded rather than cast, since (string) on an array
            // yields the literal 'Array' and a PHP warning.
            ->map(fn (mixed $entry) => is_scalar($entry) || $entry === null ? (string) $entry : (string) json_encode($entry))
            // Rejected after stringifying, so false cannot survive as a silent blank.
            ->reject(fn (string $entry) => $entry === '')
            ->values()
            ->all();
    }

    /**
     * @return array{before: list<string>, after: list<string>}
     */
    public function toHtml(): array
    {
        return [
            'before' => $this->htmlFor(before: true),
            'after' => $this->htmlFor(before: false),
        ];
    }

    /**
     * @return list<string>
     */
    protected function htmlFor(bool $before): array
    {
        return collect($this->items)
            ->filter(fn (FieldDiff $item) => $before ? $item->status->inBefore() : $item->status->inAfter())
            ->map(fn (FieldDiff $item) => $before ? $item->beforeHtml : $item->afterHtml)
            ->values()
            ->all();
    }

    /**
     * A list takes its items' shared status when they agree, and Changed when they don't.
     *
     * @param list<FieldDiff> $items
     */
    protected static function statusFor(array $items): ChangeType
    {
        $statuses = collect($items)->map(fn (FieldDiff $item) => $item->status)->unique();

        return match ($statuses->count()) {
            0 => ChangeType::Kept,
            1 => $statuses->first(),
            default => ChangeType::Changed,
        };
    }
}
