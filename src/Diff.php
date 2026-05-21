<?php

namespace TestMonitor\Revisable;

use Illuminate\Support\Arr;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;

class Diff
{
    /**
     * @var array<string, array{old: mixed, new: mixed}>
     */
    protected array $fields;

    /**
     * @var array<string, array{added: list<mixed>, removed: list<mixed>, changed: array<mixed, mixed>}>
     */
    protected array $relations;

    public function __construct(array $before, array $after)
    {
        $this->fields = $this->diffFields(
            $before['attributes'] ?? [],
            $after['attributes'] ?? []
        );

        $this->relations = $this->diffRelations(
            $before['relations'] ?? [],
            $after['relations'] ?? []
        );
    }

    /**
     * Create a diff from two revisions.
     */
    public static function fromRevisions(RevisionContract $before, RevisionContract $after): static
    {
        return new static($before->metadata ?? [], $after->metadata ?? []);
    }

    /**
     * Create an empty diff with no changes.
     */
    public static function empty(): static
    {
        return new static([], []);
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
    public function get(string $field): array
    {
        return $this->all()[$field];
    }

    /**
     * Return only the fields and relations that changed between the two revisions.
     */
    public function changes(): array
    {
        $fieldChanges = Arr::where($this->fields, fn ($entry) => $entry['old'] !== $entry['new']);

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
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function diffFields(array $before, array $after): array
    {
        $fields = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $fields[$key] = [
                'old' => Arr::get($before, $key),
                'new' => Arr::get($after, $key),
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
     * @return array{added: list<mixed>, removed: list<mixed>, changed: array<mixed, mixed>}
     */
    protected function diffPivotedRelation(array $before, array $after, string $relatedKey): array
    {
        $beforeIds = array_column($before['pivots']['items'] ?? [], $relatedKey);
        $afterIds = array_column($after['pivots']['items'] ?? [], $relatedKey);

        $changed = [];

        foreach (array_intersect($beforeIds, $afterIds) as $id) {
            $match = fn ($item) => ($item[$relatedKey] ?? null) == $id;

            $beforePivot = Arr::first($before['pivots']['items'] ?? [], $match, []);
            $afterPivot = Arr::first($after['pivots']['items'] ?? [], $match, []);

            $pivotChanges = [];

            foreach (array_unique([...array_keys($beforePivot), ...array_keys($afterPivot)]) as $key) {
                if (($beforePivot[$key] ?? null) !== ($afterPivot[$key] ?? null)) {
                    $pivotChanges[$key] = ['old' => $beforePivot[$key] ?? null, 'new' => $afterPivot[$key] ?? null];
                }
            }

            if (! empty($pivotChanges)) {
                $changed[$id] = $pivotChanges;
            }
        }

        return [
            'added' => array_values(array_diff($afterIds, $beforeIds)),
            'removed' => array_values(array_diff($beforeIds, $afterIds)),
            'changed' => $changed,
        ];
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

        $beforeIds = array_column($before['records']['items'] ?? [], $primaryKey);
        $afterIds = array_column($after['records']['items'] ?? [], $primaryKey);

        return [
            'added' => array_values(array_diff($afterIds, $beforeIds)),
            'removed' => array_values(array_diff($beforeIds, $afterIds)),
            'changed' => [],
        ];
    }
}
