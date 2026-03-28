<?php

namespace TestMonitor\Revisable\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\RevisableServiceProvider;
use TestMonitor\Revisable\Revisioner;

trait HasRevisions
{
    /**
     * Whether revisioning is currently active for this model instance.
     */
    protected bool $revisioningEnabled = true;

    /**
     * Register the custom model events fired during revisioning and rollback.
     */
    public function initializeHasRevisions(): void
    {
        $this->addObservableEvents(['revisioning', 'revisioned', 'rollingBack', 'rolledBack']);
    }

    /**
     * Hook into model lifecycle events to trigger revision creation and cleanup.
     */
    public static function bootHasRevisions(): void
    {
        static::created(function (Model $model) {
            $model->createNewRevision();
        });

        static::updated(function (Model $model) {
            $model->createNewRevision();
        });

        static::deleted(function (Model $model) {
            if ($model->forceDeleting !== false) {
                $model->deleteAllRevisions();
            }
        });
    }

    /**
     * Register a listener for the revisioning event, which fires before a revision is created.
     * Return false from the callback to abort revision creation.
     */
    public static function revisioning(Closure $callback): void
    {
        static::registerModelEvent('revisioning', $callback);
    }

    /**
     * Register a listener for the revisioned event, which fires after a revision is created.
     */
    public static function revisioned(Closure $callback): void
    {
        static::registerModelEvent('revisioned', $callback);
    }

    /**
     * Register a listener for the rollingBack event, which fires before a rollback is performed.
     * Return false from the callback to abort the rollback.
     */
    public static function rollingBack(Closure $callback): void
    {
        static::registerModelEvent('rollingBack', $callback);
    }

    /**
     * Register a listener for the rolledBack event, which fires after a rollback is performed.
     */
    public static function rolledBack(Closure $callback): void
    {
        static::registerModelEvent('rolledBack', $callback);
    }

    /**
     * Return the revision options for this model.
     */
    abstract public function getRevisionOptions(): RevisableOptions;

    /**
     * Get all the revisions for a given model instance.
     *
     * @return MorphMany<Revision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(RevisableServiceProvider::determineRevisionModel(), 'revisionable');
    }

    /**
     * Get the most recent revision for a given model instance.
     *
     * @return MorphOne<Revision, $this>
     */
    public function latestRevision(): MorphOne
    {
        return $this->morphOne(RevisableServiceProvider::determineRevisionModel(), 'revisionable')
            ->latestOfMany();
    }

    /**
     * Create a new revision record for the model instance.
     */
    public function createNewRevision(): Revision|bool
    {
        $options = $this->getRevisionOptions();

        if (! $this->shouldCreateRevision($options)) {
            return false;
        }

        if ($this->fireModelEvent('revisioning') === false) {
            return false;
        }

        $revision = app(Revisioner::class)
            ->for($this)
            ->nameUsing($options->nameGenerator)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->save();

        $this->fireModelEvent('revisioned', false);

        return $revision;
    }

    /**
     * Determine if a revision should be created for the current model state.
     */
    protected function shouldCreateRevision(RevisableOptions $options): bool
    {
        if (! $options->isEnabled() || ! $this->revisioningEnabled) {
            return false;
        }

        if ($this->wasRecentlyCreated && ! $options->onCreate) {
            return false;
        }

        if (
            array_key_exists(SoftDeletes::class, class_uses($this)) &&
            array_key_exists($this->getDeletedAtColumn(), $this->getDirty())
        ) {
            return false;
        }

        if (! empty($options->fields)) {
            return $this->isDirty($options->fields);
        }

        if (! empty($options->exceptFields)) {
            return ! empty(Arr::except($this->getDirty(), $options->exceptFields));
        }

        return true;
    }

    /**
     * Manually save a new revision for a model instance.
     */
    public function saveAsRevision(?string $name = null, array $properties = []): Revision
    {
        $options = $this->getRevisionOptions();

        return app(Revisioner::class)
            ->for($this)
            ->name($name)
            ->properties($properties)
            ->nameUsing($options->nameGenerator)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->save();
    }

    /**
     * Rollback the model instance to its latest revision.
     */
    public function rollback(): bool
    {
        $revision = $this->latestRevision;

        if ($revision === null) {
            return false;
        }

        return $this->rollbackToRevision($revision);
    }

    /**
     * Rollback the model instance to the given revision instance.
     */
    public function rollbackToRevision(RevisionContract $revision): bool
    {
        if ($this->fireModelEvent('rollingBack') === false) {
            return false;
        }

        $options = $this->getRevisionOptions();

        $result = app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->rollback($revision);

        if ($options->revisionOnRollback) {
            $this->saveAsRevision();
        }

        $this->fireModelEvent('rolledBack', false);

        return $result;
    }

    /**
     * Remove all existing revisions from the database, belonging to a model instance.
     */
    public function deleteAllRevisions(): void
    {
        app(Revisioner::class)->for($this)->deleteAll();
    }

    /**
     * If a revision record limit is set on the model and that limit is exceeded,
     * remove the oldest revisions until the limit is met.
     */
    public function clearOldRevisions(): void
    {
        $options = $this->getRevisionOptions();

        app(Revisioner::class)->for($this)->limit($options->limit)->prune();
    }

    /**
     * Execute a callback with revisioning suppressed for this model instance.
     */
    public function withoutRevisioning(Closure $callback): mixed
    {
        $this->revisioningEnabled = false;

        try {
            return $callback();
        } finally {
            $this->revisioningEnabled = true;
        }
    }
}
