<?php

namespace TestMonitor\Revisable;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use TestMonitor\Revisable\Contracts\DiffDriver;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Diffing\FieldDiff;
use TestMonitor\Revisable\Diffing\ListDiff;
use TestMonitor\Revisable\Diffing\PlainDriver;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;

final class Diff
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
        return new self(null, $revision);
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
     *
     * @return array<string, array{before: mixed, after: mixed}|array{
     *     added: list<mixed>,
     *     removed: list<mixed>,
     *     changed: array<mixed, mixed>,
     *     kept: list<mixed>,
     * }>
     */
    public function all(): array
    {
        return [...$this->fields, ...$this->relations];
    }

    /**
     * Get the diff for a specific field or relation.
     *
     * @return array{before: mixed, after: mixed}|array{
     *     added: list<mixed>,
     *     removed: list<mixed>,
     *     changed: array<mixed, mixed>,
     *     kept: list<mixed>,
     * }|null
     */
    public function get(string $field): ?array
    {
        return $this->all()[$field] ?? null;
    }

    /**
     * Diff a single tracked field. Returns null when the field isn't tracked.
     *
     * @param string|DiffDriver $driver A built-in driver name, or an instance for custom configuration
     */
    public function field(string $field, string|DiffDriver $driver = 'plain'): ?FieldDiff
    {
        $value = $this->get($field);

        if ($value === null || ! array_key_exists('before', $value)) {
            return null;
        }

        if (is_array($value['before']) || is_array($value['after'])) {
            throw InvalidConfiguration::fieldIsList($field);
        }

        if ($this->holdsJsonList($value['before']) || $this->holdsJsonList($value['after'])) {
            throw InvalidConfiguration::fieldIsList($field);
        }

        return $this->driver($driver)->diff(
            $this->stringOrNull($value['before']),
            $this->stringOrNull($value['after']),
        );
    }

    /**
     * Diff a tracked field holding a list of values. Returns null when the field isn't tracked.
     *
     * @param string|DiffDriver $driver A built-in driver name, or an instance for custom configuration
     */
    public function list(string $field, string|DiffDriver $driver = 'plain'): ?ListDiff
    {
        $value = $this->get($field);

        if ($value === null || ! array_key_exists('before', $value)) {
            return null;
        }

        return ListDiff::for(
            ListDiff::entries($value['before']),
            ListDiff::entries($value['after']),
            $this->driver($driver),
        );
    }

    /**
     * Resolve a driver name to an instance. Anything beyond the built-in names is passed
     * in as an instance by the caller.
     */
    protected function driver(string|DiffDriver $driver): DiffDriver
    {
        if ($driver instanceof DiffDriver) {
            return $driver;
        }

        return match ($driver) {
            'plain' => new PlainDriver,
            default => throw InvalidConfiguration::unknownDiffDriver($driver),
        };
    }

    /**
     * Whether a stored value is a JSON-encoded list rather than a scalar.
     */
    protected function holdsJsonList(mixed $value): bool
    {
        return is_string($value) && is_array(json_decode($value, true));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Return the names of the fields and relations that changed between the two revisions.
     *
     * @param array<string, Closure> $except
     * @return list<string>
     */
    public function changed(array $except = []): array
    {
        return array_keys($this->changes($except));
    }

    /**
     * Return only the fields and relations that changed between the two revisions.
     *
     * @param array<string, Closure> $except
     * @return array<string, array{before: mixed, after: mixed}|array{
     *     added: list<mixed>,
     *     removed: list<mixed>,
     *     changed: array<mixed, mixed>,
     *     kept: list<mixed>,
     * }>
     */
    public function changes(array $except = []): array
    {
        $relations = empty($except) ? $this->relations : $this->diffRelations(
            $this->stripExceptRecords($this->before?->metadata()['relations'] ?? [], $except),
            $this->stripExceptRecords($this->after?->metadata()['relations'] ?? [], $except)
        );

        $fieldChanges = Arr::where(
            $this->fields,
            fn ($entry) => $this->valuesAreDifferent($entry['before'], $entry['after'])
        );

        $relationChanges = Arr::where(
            $relations,
            fn ($entry) => ! empty($entry['added']) || ! empty($entry['removed']) || ! empty($entry['changed']),
        );

        return [...$fieldChanges, ...$relationChanges];
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
     * Drop relation records that are stale per their predicate in $except, returning a filtered copy.
     *
     * @param array<string, mixed> $relations
     * @param array<string, Closure> $except
     * @return array<string, mixed>
     */
    protected function stripExceptRecords(array $relations, array $except): array
    {
        foreach ($relations as $name => &$attributes) {
            if (! isset($except[$name], $attributes['records']['items'])) {
                continue;
            }

            $primaryKey = $attributes['records']['primary_key'];

            $attributes['records']['items'] = array_values(
                array_filter($attributes['records']['items'], $except[$name])
            );

            if (isset($attributes['pivots']['items'])) {
                $keptKeys = array_column($attributes['records']['items'], $primaryKey);

                $attributes['pivots']['items'] = array_values(array_filter(
                    $attributes['pivots']['items'],
                    fn ($item) => in_array($item[$attributes['pivots']['related_key']], $keptKeys)
                ));
            }
        }

        return $relations;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
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
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{
     *     added: list<mixed>,
     *     removed: list<mixed>,
     *     changed: array<mixed, mixed>,
     *     kept: list<mixed>,
     * }>
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
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{
     *     added: list<mixed>,
     *     removed: list<mixed>,
     *     changed: array<mixed, mixed>,
     *     kept: list<mixed>,
     * }
     */
    protected function diffRelation(array $before, array $after): array
    {
        $relatedKey = $after['pivots']['related_key'] ?? $before['pivots']['related_key'] ?? null;

        return $relatedKey
            ? $this->diffPivotedRelation($before, $after, $relatedKey)
            : $this->diffDirectRelation($before, $after);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
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
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{added: list<mixed>, removed: list<mixed>, kept: list<mixed>, changed: array<mixed, mixed>}
     */
    protected function diffDirectRelation(array $before, array $after): array
    {
        $primaryKey = $after['records']['primary_key'] ?? $before['records']['primary_key'] ?? null;

        if (! $primaryKey) {
            return ['added' => [], 'removed' => [], 'kept' => [], 'changed' => []];
        }

        return $this->diffItemsByKey(
            $before['records']['items'] ?? [],
            $after['records']['items'] ?? [],
            $primaryKey,
        );
    }

    /**
     * Diff two lists of associative arrays keyed by an identifying column.
     *
     * @param list<array<string, mixed>> $beforeItems
     * @param list<array<string, mixed>> $afterItems
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
