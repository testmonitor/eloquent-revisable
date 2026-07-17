<?php

namespace TestMonitor\Revisable\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Enums\RevisionType;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\RevisableServiceProvider;
use TestMonitor\Revisable\Revisioner;
use TestMonitor\Revisable\UserResolver;

/**
 * @mixin Model
 *
 * @property-read Collection<int, Revision> $revisions
 * @property-read Revision|null $latestRevision
 * @property-read Revision|null $firstRevision
 */
trait HasRevisions
{
    /**
     * Whether revisioning is currently active for this model instance.
     */
    protected bool $revisioningEnabled = true;

    /**
     * Whether automatic revision creation is currently suspended for this model class,
     * e.g. while creating a model and its relations inside withSingleRevision().
     */
    protected static bool $revisioningSuspended = false;

    /**
     * Model attributes captured before the most recent update, used as the diff baseline.
     */
    protected array $revisionOriginal = [];

    /**
     * Whether this instance has already produced its Initial revision.
     */
    protected bool $revisionInitialCreated = false;

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

        static::updating(function (Model $model) {
            if (static::$revisioningSuspended && ! empty($model->revisionOriginal)) {
                return;
            }

            $model->revisionOriginal = $model->getRawOriginal();
        });

        static::updated(function (Model $model) {
            $model->createNewRevision();

            if (! static::$revisioningSuspended) {
                $model->revisionOriginal = [];
            }
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
     * Get the oldest revision for a given model instance.
     *
     * @return MorphOne<Revision, $this>
     */
    public function firstRevision(): MorphOne
    {
        return $this->morphOne(RevisableServiceProvider::determineRevisionModel(), 'revisionable')
            ->oldestOfMany();
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
     * Compare the current model state against the latest revision or a specific revision.
     */
    public function diff(?RevisionContract $revision = null): Diff
    {
        $revision ??= $this->latestRevision;

        if (! $revision) {
            return Diff::empty();
        }

        $options = $this->getRevisionOptions();

        $current = app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->build();

        return Diff::fromRevisions($revision, $current);
    }

    /**
     * Create a new revision record for the model instance.
     */
    public function createNewRevision(): Revision|bool
    {
        return $this->buildNewRevision($this->getRevisionOptions());
    }

    /**
     * Create a new revision record for the model instance, bypassing the tracked-field dirty check.
     */
    protected function forceCreateNewRevision(): Revision|bool
    {
        return $this->buildNewRevision($this->getRevisionOptions(), force: true);
    }

    /**
     * Build and save a new revision record for the model instance.
     */
    protected function buildNewRevision(RevisableOptions $options, bool $force = false): Revision|bool
    {
        if (! $this->shouldCreateRevision($options, $force)) {
            return false;
        }

        if ($this->fireModelEvent('revisioning') === false) {
            return false;
        }

        $revision = app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->when($this->isInitialRevision(), fn ($revisioner) => $revisioner->type(RevisionType::Initial))
            ->when(
                $this->shouldReplaceRevision($options) ? $this->revisionToReplace() : null,
                fn ($revisioner, $existing) => $revisioner->replace($existing),
                fn ($revisioner) => $revisioner->save()
            );

        $this->fireModelEvent('revisioned', false);

        return $revision;
    }

    /**
     * Manually save a revision for a model instance. Returns null when revisioning is suppressed.
     */
    public function saveAsRevision(?string $name = null, array $properties = [], ?bool $replace = null): ?Revision
    {
        if ($this->isRevisioningSuppressed()) {
            return null;
        }

        $options = $this->getRevisionOptions();

        $existing = $replace ?? $this->shouldReplaceRevision($options)
            ? $this->revisionToReplace()
            : null;

        return app(Revisioner::class)
            ->for($this)
            ->name($name)
            ->properties($properties)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->when(
                $existing,
                fn ($revisioner, $existing) => $revisioner->replace($existing),
                fn ($revisioner) => $revisioner->save()
            );
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
            ->withoutRestoringRelations($options->exceptRestoringRelations)
            ->limit($options->limit)
            ->rollback($revision);

        if ($options->revisionOnRollback) {
            $this->saveAsRollbackRevision($options, $revision);
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

    /**
     * Execute a callback with automatic revisioning suspended for this model class,
     * then create a single revision from the final state. The callback must return
     * the model to be revisioned.
     */
    public static function withSingleRevision(Closure $callback): mixed
    {
        static::$revisioningSuspended = true;

        try {
            $result = $callback();
        } finally {
            static::$revisioningSuspended = false;
        }

        if (! $result instanceof static) {
            throw new InvalidArgumentException(
                'withSingleRevision() callback must return an instance of ' . static::class . '.'
            );
        }

        $result->forceCreateNewRevision();

        $result->revisionOriginal = [];

        return $result;
    }

    /**
     * Return the model attributes as they were before the most recent update.
     * Falls back to getRawOriginal() when called outside an update lifecycle (e.g. saveAsRevision).
     */
    public function getRevisionOriginal(): array
    {
        return $this->revisionOriginal ?: $this->getRawOriginal();
    }

    /**
     * Determine whether revisioning is currently suppressed, either for this instance
     * (via withoutRevisioning()) or for the whole class (via withSingleRevision()).
     */
    protected function isRevisioningSuppressed(): bool
    {
        return ! $this->revisioningEnabled || static::$revisioningSuspended;
    }

    /**
     * Determine whether the next revision should be tagged as Initial, consuming that state
     * so later revisions on the same instance are tagged Default.
     */
    protected function isInitialRevision(): bool
    {
        if (! $this->wasRecentlyCreated || $this->revisionInitialCreated) {
            return false;
        }

        return $this->revisionInitialCreated = true;
    }

    /**
     * Determine if a revision should be created for the current model state.
     */
    protected function shouldCreateRevision(RevisableOptions $options, bool $force = false): bool
    {
        if (! $options->isEnabled() || $this->isRevisioningSuppressed()) {
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

        if ($force) {
            return true;
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
     * Determine whether the latest revision should be replaced instead of creating a new one.
     */
    protected function shouldReplaceRevision(RevisableOptions $options): bool
    {
        $latest = $this->latestRevision()->first();

        if ($latest === null || ! $latest->isDefault()) {
            return false;
        }

        if (! $options->shouldReplace($this, $latest)) {
            return false;
        }

        if (! app(UserResolver::class)->matches($latest->user_id)) {
            return false;
        }

        if (! $options->isWithinReplaceWindow($latest->{$latest->getUpdatedAtColumn()})) {
            return false;
        }

        return true;
    }

    /**
     * Return the latest revision to replace, or null if a new one should be created.
     */
    protected function revisionToReplace(): ?Revision
    {
        return $this->latestRevision()->first();
    }

    /**
     * Save a rollback revision, always as a new record and marked as a rollback.
     */
    protected function saveAsRollbackRevision(RevisableOptions $options, RevisionContract $revision): Revision
    {
        return app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->type(RevisionType::Rollback)
            ->properties([
                'rollback_from' => $revision->name,
            ])
            ->save();
    }
}
