<?php

namespace TestMonitor\Revisable;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Renderers\HtmlDiff;

class Diff
{
    /**
     * @var array<string, array{before: mixed, after: mixed}>
     */
    protected array $fields;

    /**
     * @var array<string, array{added: list<mixed>, removed: list<mixed>, kept: list<mixed>, changed: array<mixed>}>
     */
    protected array $relations;

    public function __construct(protected ?RevisionContract $before, protected ?RevisionContract $after)
    {
        $this->fields = $this->diffFields(
            $before?->metadata()['attributes'] ?? [],
            $after?->metadata()['attributes'] ?? []
        );

        $this->relations = $this->diffRelations(
            $before?->metadata()['relations'] ?? [],
            $after?->metadata()['relations'] ?? []
        );
    }

    /**
     * Create a diff of a single revision against nothing, so every field and relation it holds appears as added.
     */
    public static function fromNothing(RevisionContract $revision): static
    {
        return new static(null, $revision);
    }

    /**
     * Get the "before" revision this diff was built from, or null if diffed against nothing.
     */
    public function before(): ?RevisionContract
    {
        return $this->before;
    }

    /**
     * Get the "after" revision this diff was built from, or null if diffed against nothing.
     */
    public function after(): ?RevisionContract
    {
        return $this->after;
    }

    /**
     * Get the raw "before" revision metadata, or a subkey of it (e.g. 'attributes', 'relations.tags').
     */
    public function beforeMetadata(?string $key = null): mixed
    {
        return Arr::get($this->before?->metadata() ?? [], $key);
    }

    /**
     * Get the raw "after" revision metadata, or a subkey of it (e.g. 'attributes', 'relations.tags').
     */
    public function afterMetadata(?string $key = null): mixed
    {
        return Arr::get($this->after?->metadata() ?? [], $key);
    }

    /**
     * Return all tracked fields and relations, including those that are unchanged.
     */
    public function all(): array
    {
        return [...$this->fields, ...$this->relations];
    }

    /**
     * Get the diff for a specific field or relation.
     */
    public function get(string $field): ?array
    {
        return $this->all()[$field] ?? null;
    }

    /**
     * Return only the fields and relations that changed between the two revisions.
     */
    public function changes(): array
    {
        $fieldChanges = Arr::where(
            $this->fields,
            fn ($entry) => $this->valuesAreDifferent($entry['before'], $entry['after'])
        );

        $relationChanges = Arr::where(
            $this->relations,
            fn ($entry) => ! empty($entry['added']) || ! empty($entry['removed']) || ! empty($entry['changed']),
        );

        return [...$fieldChanges, ...$relationChanges];
    }

    /**
     * Return the names of the fields and relations that changed between the two revisions.
     */
    public function changed(): array
    {
        return array_keys($this->changes());
    }

    /**
     * Wrap this diff in an HTML renderer.
     *
     * @param  string  $detailLevel  Granularity of inline highlighting: 'none'|'line'|'word'|'char'
     * @param  string  $lineSeparator  String placed between cells when a multi-line value is joined
     */
    public function asHtml(string $detailLevel = 'word', string $lineSeparator = '<br>'): HtmlDiff
    {
        return new HtmlDiff($this, $detailLevel, $lineSeparator);
    }

    /**
     * Compare two attribute values, using semantic JSON equality to ignore cosmetic whitespace differences.
     */
    protected function valuesAreDifferent(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return false;
        }

        if (Str::isJson($before) && Str::isJson($after)) {
            return json_decode($before, true) !== json_decode($after, true);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    protected function diffFields(array $before, array $after): array
    {
        $fields = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $fields[$key] = [
                'before' => Arr::get($before, $key),
                'after' => Arr::get($after, $key),
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function diffRelations(array $before, array $after): array
    {
        $relations = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            $relations[$name] = $this->diffRelation($before[$name] ?? [], $after[$name] ?? []);
        }

        return $relations;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function diffRelation(array $before, array $after): array
    {
        $relatedKey = $after['pivots']['related_key'] ?? $before['pivots']['related_key'] ?? null;

        return $relatedKey
            ? $this->diffPivotedRelation($before, $after, $relatedKey)
            : $this->diffDirectRelation($before, $after);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{added: list<mixed>, removed: list<mixed>, kept: list<mixed>, changed: array<mixed, mixed>}
     */
    protected function diffPivotedRelation(array $before, array $after, string $relatedKey): array
    {
        return $this->diffItemsByKey(
            $before['pivots']['items'] ?? [],
            $after['pivots']['items'] ?? [],
            $relatedKey,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{added: list<mixed>, removed: list<mixed>, changed: array<mixed, mixed>}
     */
    protected function diffDirectRelation(array $before, array $after): array
    {
        $primaryKey = $after['records']['primary_key'] ?? $before['records']['primary_key'] ?? null;

        if (! $primaryKey) {
            return ['added' => [], 'removed' => [], 'changed' => []];
        }

        return $this->diffItemsByKey(
            $before['records']['items'] ?? [],
            $after['records']['items'] ?? [],
            $primaryKey,
        );
    }

    /**
     * Diff two lists of associative arrays keyed by an identifying column, reporting which
     * identifiers were added, removed, and which retained identifiers had column-level changes.
     *
     * @param  list<array<string, mixed>>  $beforeItems
     * @param  list<array<string, mixed>>  $afterItems
     * @return array{added: list<mixed>, removed: list<mixed>, kept: list<mixed>, changed: array<mixed, mixed>}
     */
    protected function diffItemsByKey(array $beforeItems, array $afterItems, string $key): array
    {
        $beforeIds = array_column($beforeItems, $key);
        $afterIds = array_column($afterItems, $key);

        $changed = [];

        foreach (array_intersect($beforeIds, $afterIds) as $id) {
            $match = fn ($item) => ($item[$key] ?? null) == $id;

            $beforeItem = Arr::first($beforeItems, $match, []);
            $afterItem = Arr::first($afterItems, $match, []);

            $itemChanges = [];

            foreach (array_unique([...array_keys($beforeItem), ...array_keys($afterItem)]) as $field) {
                if (($beforeItem[$field] ?? null) !== ($afterItem[$field] ?? null)) {
                    $itemChanges[$field] = [
                        'before' => $beforeItem[$field] ?? null,
                        'after' => $afterItem[$field] ?? null,
                    ];
                }
            }

            if (! empty($itemChanges)) {
                $changed[$id] = $itemChanges;
            }
        }

        return [
            'added' => array_values(array_diff($afterIds, $beforeIds)),
            'removed' => array_values(array_diff($beforeIds, $afterIds)),
            'kept' => array_values(array_intersect($beforeIds, $afterIds)),
            'changed' => $changed,
        ];
    }
}
