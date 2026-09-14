<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * A diffed field. `blocks` is a flat list in document order, for inspection; the two HTML
 * strings are the rendered views, produced by the driver that built this result.
 */
readonly class FieldDiff
{
    /**
     * @param list<BlockDiff> $blocks
     */
    public function __construct(
        public ChangeType $status,
        public array $blocks,
        public string $beforeHtml,
        public string $afterHtml,
    ) {}

    /**
     * @return array{before: string, after: string}
     */
    public function toHtml(): array
    {
        return ['before' => $this->beforeHtml, 'after' => $this->afterHtml];
    }

    /**
     * Derive a field's overall status from whether each side held a value and, when both
     * did, whether any block changed.
     *
     * @param list<BlockDiff> $blocks
     */
    public static function statusFor(?string $before, ?string $after, array $blocks): ChangeType
    {
        $hadBefore = $before !== null && $before !== '';
        $hasAfter = $after !== null && $after !== '';

        return match (true) {
            ! $hadBefore && ! $hasAfter => ChangeType::Kept,
            ! $hadBefore => ChangeType::Added,
            ! $hasAfter => ChangeType::Removed,
            default => collect($blocks)->every(fn (BlockDiff $block) => $block->status === ChangeType::Kept)
                ? ChangeType::Kept
                : ChangeType::Changed,
        };
    }
}
