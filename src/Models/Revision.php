<?php

namespace TestMonitor\Revisable\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Enums\RevisionType;
use TestMonitor\Revisable\RevisableServiceProvider;

class Revision extends Model implements RevisionContract
{
    protected $table = 'revisions';

    protected $fillable = [
        'name',
        'metadata',
        'properties',
        'changes',
        'type',
        'revisionable_id',
        'revisionable_type',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'properties' => 'array',
        'changes' => 'array',
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
     * Compare this revision against another revision or its predecessor.
     */
    public function diff(?RevisionContract $target = null): Diff
    {
        if ($target instanceof RevisionContract) {
            return new Diff($this, $target);
        }

        $previous = $this->previous();

        if ($previous === null) {
            return Diff::empty();
        }

        return new Diff($previous, $this);
    }

    /**
     * Reconstruct the revisionable model as it existed at the time of this revision.
     */
    public function toModel(): Model
    {
        $class = Relation::getMorphedModel($this->revisionable_type) ?? $this->revisionable_type;

        /** @var Model $model */
        $model = new $class;

        $attributes = Arr::except($this->metadata, ['relations']);
        $attributes[$model->getKeyName()] = $this->revisionable_id;

        $model->setRawAttributes($attributes);
        $model->exists = true;

        return $model;
    }
}
