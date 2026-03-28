<?php

namespace TestMonitor\Revisable\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\RevisableServiceProvider;

class Revision extends Model implements RevisionContract
{
    protected $table = 'revisions';

    protected $fillable = [
        'name',
        'metadata',
        'properties',
        'revisionable_id',
        'revisionable_type',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'properties' => 'array',
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
    public function forUser(Builder $query, Authenticatable $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope revisions to those belonging to a specific model instance.
     */
    #[Scope]
    public function forModel(Builder $query, int $id, string $type): void
    {
        $query->where([
            'revisionable_id' => $id,
            'revisionable_type' => $type,
        ]);
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
