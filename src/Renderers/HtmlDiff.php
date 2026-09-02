<?php

namespace TestMonitor\Revisable\Renderers;

use Illuminate\Support\Str;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Ssddanbrown\HtmlDiff\Diff as HtmlDiffer;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Renderers\Support\ArrayAligner;
use TestMonitor\Revisable\Renderers\Support\HtmlFragment;

class HtmlDiff
{
    /**
     * @param string $detailLevel Granularity of inline highlighting: 'none'|'line'|'word'|'char'
     *                            For HTML fields, only 'none' is mapped; all other levels resolve to word-level
     *                            because the underlying HTML differ does not support finer granularity.
     * @param string $lineSeparator String placed between cells when a multi-line value is joined
     */
    public function __construct(
        protected Diff $diff,
        protected string $detailLevel = 'word',
        protected string $lineSeparator = '<br>',
    ) {}

    /**
     * Render an HTML diff for a tracked field, returning separate before and after views.
     *
     * Returns null when the field is not tracked in the diff.
     *
     * @return array{before: string, after: string}|array{before: list<string>, after: list<string>}|null
     */
    public function field(string $field): ?array
    {
        $value = $this->diff->get($field);

        if ($value === null) {
            return null;
        }

        // Normalize the before/after values, then diff them.
        $before = $this->normalize($value['before'] ?? '');
        $after = $this->normalize($value['after'] ?? '');

        // No prior value at all: don't pad the before side with a blank line per new item.
        if (is_array($after) && ($value['before'] ?? null) === null) {
            return $this->diffNewArray($after);
        }

        // No value anymore: don't pad the after side with a blank line per removed item.
        if (is_array($before) && ($value['after'] ?? null) === null) {
            return $this->diffRemovedArray($before);
        }

        if (is_array($before) || is_array($after)) {
            return $this->diffArray((array) $before, (array) $after);
        }

        return $this->diffValue($before, $after);
    }

    /**
     * Normalize a value: JSON strings are decoded, all other values pass through.
     */
    protected function normalize(mixed $value): mixed
    {
        return Str::isJson($value) ? json_decode($value, true) : $value;
    }

    /**
     * Build an HTML diff for a single before/after string pair.
     *
     * @return array{before: string, after: string}
     */
    protected function diffValue(mixed $before, mixed $after): array
    {
        $before = (string) $before;
        $after = (string) $after;

        return $this->containsHtml($before) || $this->containsHtml($after)
            ? $this->diffHtmlValue($before, $after)
            : $this->diffPlainValue($before, $after);
    }

    /**
     * Build HTML diffs for a before/after array pair, aligning items by content via `ArrayAligner`.
     *
     * @param array<array-key, mixed> $before
     * @param array<array-key, mixed> $after
     * @return array{before: list<string>, after: list<string>}
     */
    protected function diffArray(array $before, array $after): array
    {
        $before = array_map(fn (mixed $item) => (string) $item, array_values($before));
        $after = array_map(fn (mixed $item) => (string) $item, array_values($after));

        $beforeOut = collect();
        $afterOut = collect();

        // Each block is a run of items considered aligned between before and after.
        foreach (new ArrayAligner($before, $after)->align() as $block) {
            // Zip the block's two sides pairwise, padding the shorter side with null.
            foreach (array_map(null, $block->before, $block->after) as [$blockBefore, $blockAfter]) {
                $pair = $this->diffArrayItem($blockBefore, $blockAfter);

                // Only push non-null sides to the output collections.
                if ($pair['before'] !== null) {
                    $beforeOut->push($pair['before']);
                }

                if ($pair['after'] !== null) {
                    $afterOut->push($pair['after']);
                }
            }
        }

        return [
            'before' => $beforeOut->reject(fn (string $item) => blank(strip_tags($item)))->values()->all(),
            'after' => $afterOut->reject(fn (string $item) => blank(strip_tags($item)))->values()->all(),
        ];
    }

    /**
     * Diff a single item pair from an aligned block. A null side means a pure insertion or deletion.
     *
     * @return array{before: ?string, after: ?string}
     */
    protected function diffArrayItem(?string $before, ?string $after): array
    {
        // If before is non-null and after is null, it's a deletion.
        if ($before !== null && $after === null) {
            return ['before' => $this->diffValue($before, '')['before'], 'after' => null];
        }

        // If before is null and after is non-null, it's an insertion.
        if ($before === null && $after !== null) {
            return ['before' => null, 'after' => $this->diffValue('', $after)['after']];
        }

        // If both sides are non-null, delegate to diffValue.
        return $this->diffValue($before, $after);
    }

    /**
     * Build the after view for an array field that had no prior value at all.
     *
     * @param array<array-key, mixed> $after
     * @return array{before: list<string>, after: list<string>}
     */
    protected function diffNewArray(array $after): array
    {
        $after = collect($after)
            ->map(fn (mixed $item) => $this->diffValue('', (string) $item)['after'])
            ->reject(fn (string $item) => blank(strip_tags($item)))
            ->values();

        return ['before' => [], 'after' => $after->all()];
    }

    /**
     * Build the before view for an array field that no longer has any value.
     *
     * @param array<array-key, mixed> $before
     * @return array{before: list<string>, after: list<string>}
     */
    protected function diffRemovedArray(array $before): array
    {
        $before = collect($before)
            ->map(fn (mixed $item) => $this->diffValue((string) $item, '')['before'])
            ->reject(fn (string $item) => blank(strip_tags($item)))
            ->values();

        return ['before' => $before->all(), 'after' => []];
    }

    /**
     * Build a plain-text diff, escaping identical values and delegating changes to jfcherng/php-diff.
     *
     * @return array{before: string, after: string}
     */
    protected function diffPlainValue(string $before, string $after): array
    {
        if ($before === $after) {
            return ['before' => $this->joinLines($before), 'after' => $this->joinLines($before)];
        }

        $diff = DiffHelper::calculate(
            old: $before,
            new: $after,
            renderer: 'SideBySide',
            differOptions: ['context' => Differ::CONTEXT_ALL],
            rendererOptions: ['showHeader' => false, 'lineNumbers' => false, 'detailLevel' => $this->detailLevel],
        );

        $diff = $this->markWholeLineChanges($diff);

        return [
            'before' => $this->extractCells($diff, 'old'),
            'after' => $this->extractCells($diff, 'new'),
        ];
    }

    /**
     * HTML-encode a string, consistent with jfcherng's own encoding of diffed values.
     */
    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * HTML-encode a value line by line, joined by the configured line separator, so an
     * unchanged multiline value keeps the same shape as one rendered through the differ.
     */
    protected function joinLines(string $value): string
    {
        return Str::of($this->normalizeLineEndings($value))
            ->explode("\n")
            ->map(fn (string $line) => $this->escape($line))
            ->implode($this->lineSeparator);
    }

    /**
     * A wholly added/removed line has no inline <ins>/<del> of its own, only its <tbody>
     * wrapper class — add the markup before that wrapper is discarded.
     */
    protected function markWholeLineChanges(string $diff): string
    {
        return Str::of($diff)
            ->pipe(fn ($diff) => $this->markWholeLineChangesForTag($diff, 'ins', 'new'))
            ->pipe(fn ($diff) => $this->markWholeLineChangesForTag($diff, 'del', 'old'))
            ->value();
    }

    /**
     * Wrap the $side cell of every wholly-$tag <tbody> block in an inline <$tag> marker.
     */
    protected function markWholeLineChangesForTag(string $diff, string $tag, string $side): string
    {
        $tbody = 'change change-' . $tag;

        return Str::of($diff)
            ->replaceMatches(
                '/<tbody class="' . $tbody . '">(.*?)<\/tbody>/s',
                fn (array $match) => '<tbody class="' . $tbody . '">'
                    . $this->wrapCells($match[1], $side, $tag)
                    . '</tbody>',
            )
            ->value();
    }

    /**
     * Wrap the content of every non-empty <td class="$side"> cell in $tag.
     */
    protected function wrapCells(string $html, string $side, string $tag): string
    {
        return Str::of($html)
            ->replaceMatches(
                '/<td class="' . $side . '">(.*?)<\/td>/s',
                fn (array $match) => $match[1] === ''
                    ? $match[0]
                    : '<td class="' . $side . '"><' . $tag . '>' . $match[1] . '</' . $tag . '></td>',
            )
            ->value();
    }

    /**
     * Extract the inner HTML of all <td class="$side"> cells from SideBySide output.
     */
    protected function extractCells(string $diff, string $side): string
    {
        return Str::of($diff)
            ->matchAll('/<td class="' . $side . '">(.*?)<\/td>/s')
            ->implode($this->lineSeparator);
    }

    /**
     * Collapse CRLF/CR line endings to a plain LF.
     */
    protected function normalizeLineEndings(string $value): string
    {
        return Str::of($value)->replace(["\r\n", "\r"], "\n")->value();
    }

    /**
     * Return true when the string contains at least one HTML tag.
     */
    protected function containsHtml(string $value): bool
    {
        return Str::of($value)->test('/<\s*\/?\s*[a-zA-Z][^>]*>/');
    }

    /**
     * Return true when the string contains list/table block structure (<ul>, <ol>, <table>).
     */
    protected function containsBlockStructure(string $value): bool
    {
        return Str::of($value)->test('/<(ul|ol|table)[\s>]/i');
    }

    /**
     * Build an HTML diff for values where at least one contains HTML markup.
     *
     * Delegates to ssddanbrown/htmldiff for DOM-aware diffing, then splits the
     * merged result into separate before (del-marked) and after (ins-marked) views.
     *
     * @return array{before: string, after: string}
     */
    protected function diffHtmlValue(string $before, string $after): array
    {
        if ($this->detailLevel === 'none') {
            return ['before' => $before, 'after' => $after];
        }

        $before = $this->normalizeNewlines($before);
        $after = $this->normalizeNewlines($after);

        if ($this->hasMismatchedBlockStructure($before, $after)) {
            return [
                'before' => $this->diffValue($before, '')['before'],
                'after' => $this->diffValue('', $after)['after'],
            ];
        }

        $merged = new HtmlDiffer($before, $after)->build();

        return [
            'before' => $this->beforeView($merged),
            'after' => $this->afterView($merged),
        ];
    }

    /**
     * Turn literal newlines into explicit <br> tags so the differ can't relocate them across
     * an <ins>/<del> boundary, dropping the ones that sit purely between two tags (formatting-only
     * whitespace that browsers never render).
     */
    protected function normalizeNewlines(string $html): string
    {
        return Str::of($this->normalizeLineEndings($html))
            ->replaceMatches('/(>)[ \t]*(?:\n[ \t]*)+(<)/', '$1$2')
            ->replace("\n", '<br>')
            ->toString();
    }

    /**
     * Return true when exactly one side has list/table block structure, which can't merge
     * word-by-word without fragments of the plain side getting trapped inside a <li>/<td>.
     */
    protected function hasMismatchedBlockStructure(string $before, string $after): bool
    {
        if ($before === '' || $after === '') {
            return false;
        }

        return $this->containsBlockStructure($before) !== $this->containsBlockStructure($after);
    }

    /**
     * Extract the before view: remove inserted content, normalise <del> tags.
     */
    protected function beforeView(string $merged): string
    {
        return new HtmlFragment($merged)
            // Formatting-only change: show the old formatting instead of dropping the text.
            ->renameElements('//ins[@class="mod"]', 'del')
            // Drop <li>/<p>/<td>/<th>/<tr> elements that only ever held new content.
            ->removeElementsEmptiedBy('//li | //p | //td | //th | //tr', 'ins')
            // Any other inserted text didn't exist yet, so drop it.
            ->removeElements('//ins')
            // A wholly new list/table leaves an empty wrapper behind once its rows/items are
            // gone; drop those too, repeating since removing one can empty its own parent.
            ->removeEmptyElements('//ul | //ol | //table | //tbody | //thead')
            // Normalise the differ's diff-specific <del> classes.
            ->removeAttribute('//del[not(@class="mod")]', 'class')
            ->toHtml();
    }

    /**
     * Extract the after view: remove deleted content, normalise <ins> tags.
     */
    protected function afterView(string $merged): string
    {
        return new HtmlFragment($merged)
            // Drop <li>/<p>/<td>/<th>/<tr> elements that only ever held removed content.
            ->removeElementsEmptiedBy('//li | //p | //td | //th | //tr', 'del')
            // Deleted text no longer exists, so drop it.
            ->removeElements('//del')
            // A wholly deleted list/table leaves an empty wrapper behind once its rows/items
            // are gone; drop those too.
            ->removeEmptyElements('//ul | //ol | //table | //tbody | //thead')
            // Normalise diff-specific <ins> classes, but keep the "mod" marker.
            ->removeAttribute('//ins[not(@class="mod")]', 'class')
            ->toHtml();
    }
}
