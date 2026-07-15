<?php

namespace TestMonitor\Revisable\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Enums\RevisionType;
use TestMonitor\Revisable\RelationType;
use TestMonitor\Revisable\RevisableServiceProvider;

class Revision extends Model implements RevisionContract
{
    protected $table = 'revisions';

    protected $fillable = [
        'name',
        'metadata',
        'properties',
        'changed',
        'type',
        'revisionable_id',
        'revisionable_type',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'properties' => 'array',
        'changed' => 'array',
        'type' => RevisionType::class,
    ];

    /**
     * Get the model that this revision belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function revisionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user that created this revision.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(RevisableServiceProvider::determineUserModel(), 'user_id');
    }

    /**
     * Scope revisions to those created by the given user.
     */
    #[Scope]
    protected function forUser(Builder $query, Model $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope revisions to those belonging to a specific model instance.
     */
    #[Scope]
    protected function forModel(Builder $query, Model $model): void
    {
        $query->where([
            'revisionable_id' => $model->getKey(),
            'revisionable_type' => get_class($model),
        ]);
    }

    /**
     * Scope revisions to those that are not rollback revisions.
     */
    #[Scope]
    protected function notRollback(Builder $query): void
    {
        $query->whereNot('type', RevisionType::Rollback->value);
    }

    /**
     * Scope revisions to those that are rollback revisions.
     */
    #[Scope]
    protected function onlyRollbacks(Builder $query): void
    {
        $query->where('type', RevisionType::Rollback->value);
    }

    /**
     * Scope revisions to those that are initial revisions.
     */
    #[Scope]
    protected function onlyInitial(Builder $query): void
    {
        $query->where('type', RevisionType::Initial->value);
    }

    /**
     * Determine whether this is a regular revision.
     */
    public function isDefault(): bool
    {
        return $this->type === RevisionType::Default;
    }

    /**
     * Determine whether this revision was created on model creation.
     */
    public function isInitial(): bool
    {
        return $this->type === RevisionType::Initial;
    }

    /**
     * Determine whether this revision was created after a rollback.
     */
    public function isRollback(): bool
    {
        return $this->type === RevisionType::Rollback;
    }

    /**
     * Merge one or more key/value pairs into the properties column and save.
     */
    public function setProperties(array $properties): static
    {
        $this->fill(['properties' => [...($this->properties ?? []), ...$properties]])->save();

        return $this;
    }

    /**
     * Set a single key/value pair on the properties column and save.
     */
    public function setProperty(string $key, mixed $value): static
    {
        return $this->setProperties([$key => $value]);
    }

    /**
     * Remove all properties from the properties column and save.
     */
    public function clearProperties(): static
    {
        $this->fill(['properties' => []])->save();

        return $this;
    }

    /**
     * Remove a single key from the properties column and save.
     */
    public function removeProperty(string $key): static
    {
        $properties = $this->properties ?? [];
        unset($properties[$key]);

        $this->fill(['properties' => $properties])->save();

        return $this;
    }

    /**
     * Return the revision that directly precedes this one for the same model.
     */
    public function previous(): ?static
    {
        return static::query()
            ->where([
                ['revisionable_type', $this->revisionable_type],
                ['revisionable_id', $this->revisionable_id],
                [$this->getKeyName(), '<', $this->getKey()],
            ])
            ->latest($this->getKeyName())
            ->first();
    }

    /**
     * Return the revision that directly follows this one for the same model.
     */
    public function next(): ?static
    {
        return static::query()
            ->where([
                ['revisionable_type', $this->revisionable_type],
                ['revisionable_id', $this->revisionable_id],
                [$this->getKeyName(), '>', $this->getKey()],
            ])
            ->oldest($this->getKeyName())
            ->first();
    }

    /**
     * Determine whether this is the oldest revision for its model.
     */
    public function isFirstRevision(): bool
    {
        return $this->exists && $this->previous() === null;
    }

    /**
     * Determine whether this is the most recent revision for its model.
     */
    public function isLastRevision(): bool
    {
        return $this->exists && $this->next() === null;
    }

    /**
     * Compare this revision against another revision, or against nothing (everything it holds appears as added).
     */
    public function diff(?RevisionContract $target = null): Diff
    {
        if ($target === null) {
            return Diff::fromRevision($this);
        }

        return Diff::fromRevisions($this, $target);
    }

    /**
     * Compare this revision against the one directly preceding it, if any.
     */
    public function diffFromPrevious(): Diff
    {
        $previous = $this->previous();

        if ($previous === null) {
            return Diff::empty();
        }

        return Diff::fromRevisions($previous, $this);
    }

    /**
     * Reconstruct the revisionable model as it existed at the time of this revision.
     */
    public function toModel(): Model
    {
        $class = Relation::getMorphedModel($this->revisionable_type) ?? $this->revisionable_type;

        /** @var Model $model */
        $model = new $class;

        $attributes = $this->metadata['attributes'] ?? [];
        $attributes[$model->getKeyName()] = $this->revisionable_id;

        $model->setRawAttributes($attributes);
        $model->exists = true;

        foreach ($this->metadata['relations'] ?? [] as $name => $data) {
            $model->setRelation($name, $this->buildRelatedModels($data));
        }

        return $model;
    }

    /**
     * Reconstruct related models from stored revision data, returning a single model or collection.
     */
    protected function buildRelatedModels(array $data): Model|EloquentCollection|null
    {
        $relatedClass = $data['class'];
        $items = $data['records']['items'] ?? [];

        $collection = new EloquentCollection;

        foreach ($items as $index => $attributes) {
            /** @var Model $related */
            $related = new $relatedClass;
            $related->setRawAttributes($attributes);
            $related->exists = true;

            if (RelationType::isPivoted($data['type']) && isset($data['pivots']['items'][$index])) {
                $pivot = new Pivot;
                $pivot->setRawAttributes($data['pivots']['items'][$index]);
                $pivot->exists = true;
                $related->setRelation('pivot', $pivot);
            }

            $collection->push($related);
        }

        if (RelationType::isSingular($data['type'])) {
            return $collection->first();
        }

        return $collection;
    }
}
