<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Contracts\DiffDriver;
use TestMonitor\Revisable\Diffing\Support\AlignedBlock;
use TestMonitor\Revisable\Diffing\Support\ArrayAligner;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Diffs plain text: lines are blocks, aligned by content, and each matched pair is
 * diffed word by word.
 */
final class PlainDriver implements DiffDriver
{
    public function __construct(
        protected string $separator = '<br/>',
        protected WordDiffer $words = new WordDiffer,
    ) {}

    public function diff(?string $before, ?string $after): FieldDiff
    {
        $blocks = $this->blocksFor($this->lines($before), $this->lines($after));

        return new FieldDiff(
            FieldDiff::statusFor($before, $after, $blocks),
            $blocks,
            $this->render($blocks, before: true),
            $this->render($blocks, before: false),
        );
    }

    public function key(string $value): string
    {
        return $value;
    }

    /**
     * Align the two line lists, then diff each aligned pair.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return list<BlockDiff>
     */
    protected function blocksFor(array $before, array $after): array
    {
        $blocks = [];

        foreach (new ArrayAligner($before, $after)->align() as $aligned) {
            foreach ($this->pairs($aligned) as [$beforeLine, $afterLine]) {
                $blocks[] = $this->diffLine($beforeLine, $afterLine);
            }
        }

        return $blocks;
    }

    /**
     * Zip an aligned block's two sides, padding the shorter one with null.
     *
     * @return list<array{?string, ?string}>
     */
    protected function pairs(AlignedBlock $aligned): array
    {
        return array_map(null, $aligned->before, $aligned->after);
    }

    protected function diffLine(?string $before, ?string $after): BlockDiff
    {
        return match (true) {
            $before === null => new BlockDiff(ChangeType::Added, [new Segment(ChangeType::Added, (string) $after)]),
            $after === null => new BlockDiff(ChangeType::Removed, [new Segment(ChangeType::Removed, $before)]),
            $before === $after => new BlockDiff(ChangeType::Kept, [new Segment(ChangeType::Kept, $before)]),
            default => new BlockDiff(ChangeType::Changed, $this->words->diff($before, $after)),
        };
    }

    /**
     * Render one side, dropping the blocks and segments that belong only to the other.
     *
     * @param list<BlockDiff> $blocks
     */
    protected function render(array $blocks, bool $before): string
    {
        return collect($blocks)
            ->filter(fn (BlockDiff $block) => $before ? $block->status->inBefore() : $block->status->inAfter())
            ->map(fn (BlockDiff $block) => $this->renderBlock($block, $before))
            ->implode($this->separator);
    }

    protected function renderBlock(BlockDiff $block, bool $before): string
    {
        return collect($block->segments)
            ->filter(fn (Segment $segment) => $before ? $segment->type->inBefore() : $segment->type->inAfter())
            ->map(fn (Segment $segment) => $this->renderSegment($segment))
            ->implode('');
    }

    protected function renderSegment(Segment $segment): string
    {
        $text = htmlspecialchars($segment->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($segment->type) {
            ChangeType::Removed => "<del>{$text}</del>",
            ChangeType::Added => "<ins>{$text}</ins>",
            default => $text,
        };
    }

    /**
     * Split a value into lines, collapsing CRLF/CR endings first.
     *
     * @return list<string>
     */
    protected function lines(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $value));
    }
}
