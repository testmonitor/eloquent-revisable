<?php

namespace TestMonitor\Revisable\Renderers;

use Closure;
use Illuminate\Support\Str;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Ssddanbrown\HtmlDiff\Diff as HtmlWordDiff;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Renderers\Support\ArrayAligner;

class HtmlDiff
{
    /**
     * @param string $detailLevel Granularity of inline highlighting: 'none'|'line'|'word'|'char'
     * @param string $lineSeparator String placed between cells when a multi-line value is joined
     */
    public function __construct(
        protected Diff $diff,
        protected string $detailLevel = 'word',
        protected string $lineSeparator = '<br>',
    ) {}

    /**
     * Diff a tracked field's before/after values per $type, rendering them via $renderer first if given.
     *
     * @param Closure(string):string|null $renderer
     * @return array{before: string, after: string}|array{before: list<string>, after: list<string>}|null
     */
    public function field(string $field, FieldType $type = FieldType::Plain, ?Closure $renderer = null): ?array
    {
        $value = $this->diff->get($field);

        if ($value === null) {
            return null;
        }

        return match (true) {
            $type->isList() => $this->fieldAsList($value, $type, $renderer),
            $type->isHtml() => $this->fieldAsHtml($value, $renderer),
            default => $this->diffValue($value['before'] ?? '', $value['after'] ?? ''),
        };
    }

    /**
     * Diff a scalar field as HTML, rendering it via $renderer first if given.
     *
     * @param array{before: mixed, after: mixed} $value
     * @return array{before: string, after: string}
     */
    protected function fieldAsHtml(array $value, ?Closure $renderer): array
    {
        $before = $value['before'] ?? '';
        $after = $value['after'] ?? '';

        if ($renderer instanceof Closure) {
            $before = $renderer((string) $before);
            $after = $renderer((string) $after);
        }

        return $this->diffHtmlValue((string) $before, (string) $after);
    }

    /**
     * Diff a list field item by item, rendering items via $renderer first if given.
     *
     * @param array{before: mixed, after: mixed} $value
     * @return array{before: list<string>, after: list<string>}
     */
    protected function fieldAsList(array $value, FieldType $type, ?Closure $renderer): array
    {
        $before = $this->decodeList($value['before'] ?? '');
        $after = $this->decodeList($value['after'] ?? '');

        if ($renderer instanceof Closure) {
            $before = $this->render($before, $renderer);
            $after = $this->render($after, $renderer);
        }

        // Array items are always aligned/compared as plain text, even when they're HTML.
        if ($type->isHtml()) {
            $before = $this->plainText($before);
            $after = $this->plainText($after);
        }

        // No prior value at all: don't pad the before side with a blank line per new item.
        if (($value['before'] ?? null) === null) {
            return $this->diffNewArray((array) $after);
        }

        // No value anymore: don't pad the after side with a blank line per removed item.
        if (($value['after'] ?? null) === null) {
            return $this->diffRemovedArray((array) $before);
        }

        return $this->diffArray((array) $before, (array) $after);
    }

    /**
     * Decode a field's raw value into a list; a plain scalar becomes a single-item list.
     *
     * @return array<array-key, mixed>
     */
    protected function decodeList(mixed $value): array
    {
        // If the value is already an array, just return it as-is.
        if (is_array($value)) {
            return $value;
        }

        // Attempt to decode a JSON string into an array first.
        $decoded = is_string($value) ? json_decode($value, true) : null;

        // If JSON decoding failed, fall back to wrapping the value in an array.
        return is_array($decoded)
            ? $decoded
            : array_filter([$value], fn (mixed $item) => $item !== null && $item !== '');
    }

    /**
     * Render a raw value to HTML via $renderer, recursing into arrays.
     *
     * @param Closure(string):string $renderer
     */
    protected function render(mixed $value, Closure $renderer): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->render($item, $renderer), $value);
        }

        return $renderer((string) $value);
    }

    /**
     * Strip a rendered value down to plain text, recursing into arrays.
     */
    protected function plainText(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->plainText(...), $value);
        }

        return Str::of((string) $value)
            ->stripTags()
            ->pipe(fn ($value) => html_entity_decode($value, ENT_QUOTES, 'UTF-8'))
            ->trim()
            ->value();
    }

    /**
     * Diff a single before/after string pair as plain text, via jfcherng/php-diff.
     *
     * @return array{before: string, after: string}
     */
    protected function diffValue(mixed $before, mixed $after): array
    {
        $before = (string) $before;
        $after = (string) $after;

        // If detail level is 'none', skip any diffing but still escape the values.
        if ($this->detailLevel === 'none') {
            return ['before' => $this->joinLines($before), 'after' => $this->joinLines($after)];
        }

        // If the before and after values are identical, no diffing is needed.
        if ($before === $after) {
            return ['before' => $this->joinLines($before), 'after' => $this->joinLines($before)];
        }

        // Calculate the diff using the SideBySide renderer with full context.
        $diff = DiffHelper::calculate(
            old: $before,
            new: $after,
            renderer: 'SideBySide',
            differOptions: ['context' => Differ::CONTEXT_ALL],
            rendererOptions: ['showHeader' => false, 'lineNumbers' => false, 'detailLevel' => $this->detailLevel],
        );

        // Mark whole line changes to improve readability of the diff.
        $diff = $this->markWholeLineChanges($diff);

        return [
            'before' => $this->extractCells($diff, 'old'),
            'after' => $this->extractCells($diff, 'new'),
        ];
    }

    /**
     * Diff two HTML renderings: diff the HTML itself for a formatting-only change, else diff as plain text.
     *
     * @return array{before: string, after: string}
     */
    protected function diffHtmlValue(string $beforeHtml, string $afterHtml): array
    {
        $before = (string) $this->plainText($beforeHtml);
        $after = (string) $this->plainText($afterHtml);

        // Just a formatting change if the plain text is identical but the HTML differs.
        if ($this->detailLevel !== 'none' && $before !== '' && $before === $after && $beforeHtml !== $afterHtml) {
            return $this->diffFormatting($beforeHtml, $afterHtml);
        }

        return $this->diffValue($before, $after);
    }

    /**
     * Word-diff two HTML renderings of identical text; the library marks the change as <ins class="mod">
     * only, so "before" stays untouched.
     *
     * @return array{before: string, after: string}
     */
    protected function diffFormatting(string $beforeHtml, string $afterHtml): array
    {
        $merged = HtmlWordDiff::excecute($beforeHtml, $afterHtml);

        return [
            'before' => $beforeHtml,
            'after' => preg_replace('/<del\b[^>]*>.*?<\/del>/s', '', $merged),
        ];
    }

    /**
     * Build diffs for a before/after array pair, aligning items by content via `ArrayAligner`.
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
     * Diff a single item pair from an aligned block; a null side means a pure insertion or deletion.
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
     * HTML-encode a string, consistent with jfcherng's own encoding of diffed values.
     */
    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * HTML-encode a value line by line, joined by the configured line separator,
     * matching the differ's own output shape.
     */
    protected function joinLines(string $value): string
    {
        return Str::of($this->normalizeLineEndings($value))
            ->explode("\n")
            ->map(fn (string $line) => $this->escape($line))
            ->implode($this->lineSeparator);
    }

    /**
     * Add inline <ins>/<del> markup for wholly changed lines, which otherwise only carry a <tbody> wrapper class.
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
}
