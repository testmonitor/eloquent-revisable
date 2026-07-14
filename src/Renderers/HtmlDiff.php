<?php

namespace TestMonitor\Revisable\Renderers;

use Illuminate\Support\Str;
use Jfcherng\Diff\DiffHelper;
use Ssddanbrown\HtmlDiff\Diff as HtmlDiffer;
use TestMonitor\Revisable\Diff;

class HtmlDiff
{
    /**
     * @param  string  $detailLevel  Granularity of inline highlighting: 'none'|'line'|'word'|'char'
     *                               For HTML fields, only 'none' is mapped; all other levels resolve to word-level
     *                               because the underlying HTML differ does not support finer granularity.
     * @param  string  $lineSeparator  String placed between cells when a multi-line value is joined
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
     * @return array{before: string|array, after: string|array}|null
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

        // If either value is an array, diff each pair in parallel and return arrays of before/after diffs.
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
     * Build HTML diffs for each pair in parallel before/after arrays.
     *
     * @return array{before: list<string>, after: list<string>}
     */
    protected function diffArray(array $before, array $after): array
    {
        $diffs = collect(array_map(null, $before, $after))
            ->map(fn (array $pair) => $this->diffValue((string) ($pair[0] ?? ''), (string) ($pair[1] ?? '')))
            ->reject(fn (array $pair) => blank(strip_tags($pair['before'])) && blank(strip_tags($pair['after'])))
            ->values();

        return [
            'before' => $diffs->pluck('before')->all(),
            'after' => $diffs->pluck('after')->all(),
        ];
    }

    /**
     * Build a plain-text diff, escaping identical values and delegating changes to jfcherng/php-diff.
     *
     * @return array{before: string, after: string}
     */
    protected function diffPlainValue(string $before, string $after): array
    {
        if ($before === $after) {
            return ['before' => $this->escape($before), 'after' => $this->escape($after)];
        }

        $diff = DiffHelper::calculate(
            old: $before,
            new: $after,
            renderer: 'SideBySide',
            differOptions: [],
            rendererOptions: ['showHeader' => false, 'lineNumbers' => false, 'detailLevel' => $this->detailLevel],
        );

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
     * Extract the inner HTML of all <td class="$side"> cells from SideBySide output.
     */
    protected function extractCells(string $diff, string $side): string
    {
        return Str::of($diff)
            ->matchAll('/<td class="' . $side . '">(.*?)<\/td>/s')
            ->implode($this->lineSeparator);
    }

    /**
     * Return true when the string contains at least one HTML tag.
     */
    protected function containsHtml(string $value): bool
    {
        return Str::of($value)->test('/<\s*\/?\s*[a-zA-Z][^>]*>/');
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

        $merged = (new HtmlDiffer($before, $after))->build();

        return [
            'before' => $this->beforeView($merged),
            'after' => $this->afterView($merged),
        ];
    }

    /**
     * Extract the before view: remove inserted content, normalise <del> tags.
     */
    protected function beforeView(string $merged): string
    {
        return Str::of($merged)
            // Formatting-only change: show the old formatting instead of dropping the text.
            ->replaceMatches('/<ins class="mod">(.*?)<\/ins>/s', '<del class="mod">$1</del>')
            // Wholly new <tr>: drop the whole row, not just its text.
            ->replaceMatches(
                '/<tr(\s[^>]*)?>\s*(?:<td(\s[^>]*)?>(?:\s*<ins[^>]*>.*?<\/ins>)+\s*<\/td>\s*)+<\/tr>/is', ''
            )
            // Wholly new <li>/<p>/<td>: drop the element, not just its text.
            ->replaceMatches(
                '/<(li|p|td)(\s[^>]*)?>(?:\s*<ins[^>]*>.*?<\/ins>)+\s*<\/\1>/is', ''
            )
            // Any other inserted text didn't exist yet, so drop it.
            ->replaceMatches('/<ins[^>]*>.*?<\/ins>/s', '')
            // Normalise the differ's diff-specific <del> classes.
            ->replaceMatches('/<del(?! class="mod")[^>]*>/', '<del>')
            ->toString();
    }

    /**
     * Extract the after view: remove deleted content, normalise <ins> tags.
     */
    protected function afterView(string $merged): string
    {
        return Str::of($merged)
            // Deleted text no longer exists, so drop it.
            ->replaceMatches('/<del[^>]*>.*?<\/del>/s', '')
            // Normalise diff-specific <ins> classes, but keep the "mod" marker.
            ->replaceMatches('/<ins(?! class="mod")[^>]*>/', '<ins>')
            ->toString();
    }
}
