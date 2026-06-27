<?php

namespace TestMonitor\Revisable\Renderers;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jfcherng\Diff\DiffHelper;
use TestMonitor\Revisable\Diff;

class HtmlDiff
{
    /**
     * @param  Diff  $diff  The diff to render
     * @param  string  $detailLevel  Granularity of inline highlighting: 'none'|'line'|'word'|'char'
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

        // Normalize the values to arrays or strings, then diff them accordingly
        $before = $this->normalize($value['before'] ?? '');
        $after = $this->normalize($value['after'] ?? '');

        // If either value is an array, treat both as arrays and diff them in parallel
        if (is_array($before) || is_array($after)) {
            return $this->diffArray(
                is_array($before) ? $before : [],
                is_array($after) ? $after : [],
            );
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

        // If the values are identical, return them as-is without diffing
        if ($before === $after) {
            return [
                'before' => $this->escape($before),
                'after' => $this->escape($after),
            ];
        }

        // Use Diff to generate a side-by-side diff, then extract the inner HTML of the <td> cells
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
     * Build HTML diffs for each pair in parallel before/after arrays.
     *
     * @return array{before: list<string>, after: list<string>}
     */
    protected function diffArray(array $before, array $after): array
    {
        $diffs = collect($before)
            ->zip($after)
            ->map(fn (Collection $pair) => $this->diffValue((string) $pair[0], (string) $pair[1]))
            ->reject(fn (array $pair) => blank(strip_tags($pair['before'])) && blank(strip_tags($pair['after'])));

        return [
            'before' => $diffs->pluck('before')->values()->all(),
            'after' => $diffs->pluck('after')->values()->all(),
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
        preg_match_all('/<td class="' . $side . '">(.*?)<\/td>/s', $diff, $matches);

        return implode($this->lineSeparator, $matches[1]);
    }
}
