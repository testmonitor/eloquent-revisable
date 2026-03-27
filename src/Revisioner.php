<?php

namespace TestMonitor\Revisable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use TestMonitor\Revisable\Contracts\NameGenerator;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Models\Revision;

class Revisioner
{
    protected Model $model;

    protected array $fields = [];

    protected array $exceptFields = [];

    protected array $relations = [];

    protected ?int $limit = null;

    protected ?string $name = null;

    protected array $properties = [];

    protected ?NameGenerator $nameGenerator = null;

    public function __construct(protected UserResolver $userResolver) {}

    public function for(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function onlyFields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function exceptFields(array $fields): static
    {
        $this->exceptFields = $fields;

        return $this;
    }

    public function name(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function properties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    public function nameUsing(?NameGenerator $generator): static
    {
        $this->nameGenerator = $generator;

        return $this;
    }

    public function withRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Persist a new revision record for the model and prune old ones if a limit is set.
     */
    public function save(): Revision
    {
        return DB::transaction(function () {
            $revision = $this->model->revisions()->create([
                'user_id' => $this->userResolver->resolve(),
                'name' => $this->resolveName(),
                'metadata' => $this->buildData(),
                'properties' => $this->properties ?: null,
            ]);

            $this->prune();

            return $revision;
        });
    }

    /**
     * Restore the model (and its relations) to the state captured in the given revision.
     */
    public function rollback(RevisionContract $revision): bool
    {
        DB::transaction(function () use ($revision) {
            $this->model->withoutRevisioning(function () use ($revision) {
                $this->restoreModel($revision);

                if ($revision instanceof Revision && isset($revision->metadata['relations'])) {
                    foreach ($revision->metadata['relations'] as $relation => $attributes) {
                        if (RelationType::isDirect($attributes['type'])) {
                            $this->restoreDirectRelation($relation, $attributes);
                        }

                        if (RelationType::isPivoted($attributes['type'])) {
                            $this->restorePivotedRelation($relation, $attributes);
                        }
                    }
                }
            });
        });

        return true;
    }

    /**
     * Delete all revision records belonging to the model.
     */
    public function deleteAll(): void
    {
        $this->model->revisions()->delete();
    }

    /**
     * Remove the oldest revisions when the configured limit is exceeded.
     */
    public function prune(): void
    {
        $count = $this->model->revisions()->count();

        if (is_numeric($this->limit) && $count > $this->limit) {
            $this->model->revisions()->oldest()->take($count - $this->limit)->delete();
        }
    }

    protected function resolveName(): ?string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->nameGenerator?->generate($this->model);
    }

    /**
     * Build the full revision data array, including relations.
     *
     * @throws \ReflectionException
     */
    protected function buildData(): array
    {
        $data = $this->buildModelData();

        foreach ($this->getRelationsForRevision() as $relation => $attributes) {
            if (RelationType::isDirect($attributes['type'])) {
                $data['relations'][$relation] = $this->buildDirectRelationData($relation, $attributes);
            }

            if (RelationType::isPivoted($attributes['type'])) {
                $data['relations'][$relation] = $this->buildPivotedRelationData($relation, $attributes);
            }
        }

        return $data;
    }

    /**
     * Extract the model's own attributes, stripping keys and optionally timestamps.
     */
    protected function buildModelData(): array
    {
        $data = $this->model->wasRecentlyCreated === true
            ? $this->model->getAttributes()
            : $this->model->getRawOriginal();

        unset($data[$this->model->getKeyName()]);

        if ($this->model->usesTimestamps()) {
            unset($data[$this->model->getCreatedAtColumn()]);
            unset($data[$this->model->getUpdatedAtColumn()]);
        }

        if (! empty($this->fields)) {
            foreach (array_keys($data) as $field) {
                if (! in_array($field, $this->fields)) {
                    unset($data[$field]);
                }
            }
        } elseif (! empty($this->exceptFields)) {
            foreach (array_keys($data) as $field) {
                if (in_array($field, $this->exceptFields)) {
                    unset($data[$field]);
                }
            }
        }

        return $data;
    }

    /**
     * Extract revision data for a direct (non-pivot) relation.
     */
    protected function buildDirectRelationData(string $relation, array $attributes = []): array
    {
        $data = [
            'type' => $attributes['type'],
            'class' => get_class($attributes['model']),
            'records' => [
                'primary_key' => null,
                'foreign_key' => null,
                'items' => [],
            ],
        ];

        foreach ($this->model->{$relation}()->get() as $index => $model) {
            $data = $this->withForeignKeys($data, $model->getKeyName(), $this->model->getForeignKey());

            foreach ($model->getRawOriginal() as $field => $value) {
                $data = $this->withAttributeValue($data, $model->getAttributes(), $index, $field, $value);
            }
        }

        return $data;
    }

    /**
     * Extract revision data for a pivoted relation.
     */
    protected function buildPivotedRelationData(string $relation, array $attributes = []): array
    {
        $data = [
            'type' => $attributes['type'],
            'class' => get_class($attributes['model']),
            'records' => [
                'primary_key' => null,
                'foreign_key' => null,
                'items' => [],
            ],
            'pivots' => [
                'primary_key' => null,
                'foreign_key' => null,
                'related_key' => null,
                'items' => [],
            ],
        ];

        foreach ($this->model->{$relation}()->get() as $index => $model) {
            $accessor = $this->model->{$relation}()->getPivotAccessor();
            $pivot = $model->{$accessor};

            foreach ($model->getRawOriginal() as $field => $value) {
                $data = $this->withForeignKeys($data, $model->getKeyName(), $this->model->getForeignKey());
                $data = $this->withAttributeValue($data, $model->getAttributes(), $index, $field, $value);
            }

            foreach ($pivot->getRawOriginal() as $field => $value) {
                $data = $this->withPivotForeignKeys(
                    $data,
                    $pivot->getKeyName(),
                    $pivot->getForeignKey(),
                    $pivot->getRelatedKey()
                );
                $data = $this->withPivotAttributeValue($data, $pivot->getAttributes(), $index, $field, $value);
            }
        }

        return $data;
    }

    /**
     * Resolve the configured relations into their type/model attributes.
     */
    protected function getRelationsForRevision(): array
    {
        $relations = [];

        foreach ($this->relations as $relation) {
            $instance = $this->model->{$relation}();

            if ($instance instanceof Relation) {
                $relations[$relation] = [
                    'type' => get_class($instance),
                    'model' => $instance->getRelated(),
                    'original' => $instance->getParent(),
                ];
            }
        }

        return $relations;
    }

    protected function withForeignKeys(array $data, string $primaryKey, string $foreignKey): array
    {
        if (! ($data['records']['primary_key'] && $data['records']['foreign_key'])) {
            $data['records']['primary_key'] = $primaryKey;
            $data['records']['foreign_key'] = $foreignKey;
        }

        return $data;
    }

    protected function withPivotForeignKeys(
        array $data,
        string $primaryKey,
        string $foreignKey,
        string $relatedKey
    ): array {
        if (! ($data['pivots']['primary_key'] && $data['pivots']['foreign_key'] && $data['pivots']['related_key'])) {
            $data['pivots']['primary_key'] = $primaryKey;
            $data['pivots']['foreign_key'] = $foreignKey;
            $data['pivots']['related_key'] = $relatedKey;
        }

        return $data;
    }

    /**
     * @param  string|int|null  $value
     */
    protected function withAttributeValue(
        array $data,
        array $attributes,
        int $index,
        string $field,
        $value = null
    ): array {
        if (array_key_exists($field, $attributes)) {
            $data['records']['items'][$index][$field] = $value;
        }

        return $data;
    }

    /**
     * @param  string|int|null  $value
     */
    protected function withPivotAttributeValue(
        array $data,
        array $attributes,
        int $index,
        string $field,
        $value = null
    ): array {
        if (array_key_exists($field, $attributes)) {
            $data['pivots']['items'][$index][$field] = $value;
        }

        return $data;
    }

    /**
     * Overwrite the model's attributes with values from the revision, then save.
     */
    protected function restoreModel(RevisionContract $revision): void
    {
        $attributes = $this->model->getAttributes();

        foreach ($revision->metadata as $field => $value) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $value;
            }
        }

        $this->model->setRawAttributes($attributes);
        $this->model->save();
    }

    /**
     * Restore a direct (non-pivot) relation to the state captured in the revision.
     */
    protected function restoreDirectRelation(string $relation, array $attributes): void
    {
        $relatedPrimaryKey = $attributes['records']['primary_key'];
        $relatedRecords = $attributes['records']['items'];

        if (RelationType::isChild($attributes['type'])) {
            $oldRelated = $this->model->{$relation}()->pluck($relatedPrimaryKey)->toArray();

            $currentRelated = array_map(fn ($item) => $item[$relatedPrimaryKey], $relatedRecords);

            $extraRelated = array_diff($oldRelated, $currentRelated);

            if (! empty($extraRelated)) {
                $this->model->{$relation}()->whereIn($relatedPrimaryKey, $extraRelated)->delete();
            }
        }

        foreach ($relatedRecords as $item) {
            $related = $this->model->{$relation}();

            if (array_key_exists(SoftDeletes::class, class_uses($this->model->{$relation}))) {
                $related = $related->withTrashed();
            }

            $relatedModel = $related->findOrNew($item[$relatedPrimaryKey] ?? null);

            $relatedModel->setRawAttributes($item);

            if (array_key_exists(SoftDeletes::class, class_uses($relatedModel))) {
                $relatedModel->{$relatedModel->getDeletedAtColumn()} = null;
            }

            $relatedModel->save();
        }
    }

    /**
     * Restore a pivoted relation to the state captured in the revision.
     */
    protected function restorePivotedRelation(string $relation, array $attributes): void
    {
        foreach ($attributes['records']['items'] as $item) {
            $related = $this->model->{$relation}()->getRelated();

            if (array_key_exists(SoftDeletes::class, class_uses($related))) {
                $related = $related->withTrashed();
            }

            $relatedModel = $related->findOrNew($item[$attributes['records']['primary_key']] ?? null);

            if ($relatedModel->exists === false) {
                foreach ($item as $field => $value) {
                    $relatedModel->attributes[$field] = $value;
                }

                $relatedModel->save();
            }

            if (array_key_exists(SoftDeletes::class, class_uses($relatedModel))) {
                $relatedModel->{$relatedModel->getDeletedAtColumn()} = null;
                $relatedModel->save();
            }
        }

        $this->model->{$relation}()->detach();

        foreach ($attributes['pivots']['items'] as $item) {
            $this->model->{$relation}()->attach(
                $item[$attributes['pivots']['related_key']],
                Arr::except((array) $item, [
                    $attributes['pivots']['primary_key'],
                    $attributes['pivots']['foreign_key'],
                    $attributes['pivots']['related_key'],
                ])
            );
        }
    }
}
