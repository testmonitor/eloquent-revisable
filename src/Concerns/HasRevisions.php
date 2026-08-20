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
use TestMonitor\Revisable\PendingRevision;
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
     * Whether automatic revision creation is currently suspended for this model class.
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
     * Revisions queued during a batch instead of persisted immediately, keyed by model class and primary key.
     *
     * @var array<class-string, array<array-key, list<PendingRevision>>>
     */
    protected static array $pendingRevisions = [];

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
     * Register a listener for the revisioning event; return false to abort revision creation.
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
     * Register a listener for the rollingBack event; return false to abort, or mutate the revision to restore.
     */
    public static function rollingBack(Closure $callback): void
    {
        static::registerModelEvent('rollingBack', $callback);
    }

    /**
     * Register a listener for the rolledBack event, fired after a rollback with the target revision.
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
     * Compare the current model state against the latest revision, a specific revision, or nothing.
     */
    public function diff(?RevisionContract $revision = null): Diff
    {
        $revision ??= $this->latestRevision;

        $options = $this->getRevisionOptions();

        $current = app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->build();

        if (! $revision) {
            return Diff::fromNothing($current);
        }

        return new Diff($revision, $current);
    }

    /**
     * Create a new revision record for the model instance.
     */
    public function createNewRevision(): RevisionContract|bool
    {
        $options = $this->getRevisionOptions();

        if (! $this->shouldCreateRevision($options)) {
            return false;
        }

        return $this->buildNewRevision($options) ?? false;
    }

    /**
     * Create a new revision record for the model instance, bypassing the tracked-field dirty check.
     */
    protected function forceCreateNewRevision(): RevisionContract|bool
    {
        $options = $this->getRevisionOptions();

        if (! $this->shouldCreateRevision($options, force: true)) {
            return false;
        }

        return $this->buildNewRevision($options) ?? false;
    }

    /**
     * Manually save a revision, or queue it for later while batching; returns null when disabled
     * for this instance or when the revisioning event aborts it.
     */
    public function saveAsRevision(
        ?string $name = null,
        array $properties = [],
        ?bool $replace = null
    ): ?RevisionContract {
        if ($this->isRevisioningDisabled()) {
            return null;
        }

        return $this->buildNewRevision($this->getRevisionOptions(), $name, $properties, $replace);
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
        if ($this->fireRevisionEvent('rollingBack', true, $revision) === false) {
            return false;
        }

        $options = $this->getRevisionOptions();

        $result = app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->withRelationFilters($options->relationFilters)
            ->withoutRestoringRelations($options->exceptRestoringRelations)
            ->limit($options->limit)
            ->rollback($revision);

        if ($options->revisionOnRollback) {
            $this->saveAsRollbackRevision($options, $revision);
        }

        $this->fireRevisionEvent('rolledBack', false, $revision);

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
     * Remove the oldest revisions once the configured limit is exceeded.
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
     * Suspend revisioning while the callback creates a model (and its relations), then persist one
     * revision; the callback must return the model, and enableRevisionOnCreate() still applies.
     */
    public static function createWithSingleRevision(Closure $callback): mixed
    {
        static::$revisioningSuspended = true;

        try {
            $result = $callback();

            static::$revisioningSuspended = false;

            if (! $result instanceof static) {
                throw new InvalidArgumentException(
                    'createWithSingleRevision() callback must return an instance of ' . static::class . '.'
                );
            }

            $result->forceCreateNewRevision();

            $result->revisionOriginal = [];

            return $result;
        } finally {
            // Runs on every exit path, so queued entries can never leak into a later batch.
            static::$revisioningSuspended = false;

            static::clearPendingRevisions();
        }
    }

    /**
     * Suspend revisioning while the callback runs, then persist one revision if anything tracked
     * changed; the callback receives this model instance.
     */
    public function withSingleRevision(Closure $callback): static
    {
        static::$revisioningSuspended = true;

        try {
            $callback($this);

            static::$revisioningSuspended = false;

            if (! empty($this->pullPendingRevisions())) {
                $this->forceCreateNewRevision();
            }

            $this->revisionOriginal = [];

            return $this;
        } finally {
            // Runs on every exit path, so queued entries can never leak into a later batch.
            static::$revisioningSuspended = false;

            static::clearPendingRevisions();
        }
    }

    /**
     * Return the model attributes before the most recent update, falling back to getRawOriginal() otherwise.
     */
    public function getRevisionOriginal(): array
    {
        return $this->revisionOriginal ?: $this->getRawOriginal();
    }

    /**
     * Determine whether revisioning has been turned off for this instance via withoutRevisioning().
     */
    protected function isRevisioningDisabled(): bool
    {
        return ! $this->revisioningEnabled;
    }

    /**
     * Determine whether revisioning is suspended for batching but would otherwise be enabled.
     */
    protected function isBatchingSuspended(): bool
    {
        return $this->revisioningEnabled && static::$revisioningSuspended;
    }

    /**
     * Queue a revision to persist once the current batch completes.
     */
    protected function queuePendingRevision(PendingRevision $revision): PendingRevision
    {
        static::$pendingRevisions[static::class][$this->getKey()][] = $revision;

        return $revision;
    }

    /**
     * Remove and return the revisions queued for this model during the current batch.
     *
     * @return list<PendingRevision>
     */
    protected function pullPendingRevisions(): array
    {
        $key = $this->getKey();

        $pending = static::$pendingRevisions[static::class][$key] ?? [];

        unset(static::$pendingRevisions[static::class][$key]);

        return $pending;
    }

    /**
     * Discard every revision queued for this class, e.g. when a batch ends without using them.
     */
    protected static function clearPendingRevisions(): void
    {
        unset(static::$pendingRevisions[static::class]);
    }

    /**
     * Determine whether the next revision should be tagged Initial, consuming that state so it only happens once.
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
        if (! $options->isEnabled() || $this->isRevisioningDisabled()) {
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

        return $this->hasDirtyTrackedFields($options);
    }

    /**
     * Determine whether a field tracked by the given options is currently dirty.
     */
    protected function hasDirtyTrackedFields(RevisableOptions $options): bool
    {
        if (! empty($options->fields)) {
            return $this->isDirty($options->fields);
        }

        if (! empty($options->exceptFields)) {
            return ! empty(Arr::except($this->getDirty(), $options->exceptFields));
        }

        return true;
    }

    /**
     * Build and persist (or queue, while batching) a revision; callers that need a dirty-check gate it themselves.
     */
    protected function buildNewRevision(
        RevisableOptions $options,
        ?string $name = null,
        array $properties = [],
        ?bool $replace = null
    ): ?RevisionContract {
        if ($this->fireModelEvent('revisioning') === false) {
            return null;
        }

        $revisioner = app(Revisioner::class)
            ->for($this)
            ->name($name)
            ->properties($properties)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->withRelationFilters($options->relationFilters)
            ->limit($options->limit);

        if ($this->isBatchingSuspended()) {
            return $this->queuePendingRevision(new PendingRevision($revisioner->build()));
        }

        $revisioner->when($this->isInitialRevision(), fn ($revisioner) => $revisioner->type(RevisionType::Initial));

        $revision = ($replace ?? $this->shouldReplaceRevision($options))
            ? $revisioner->replace($this->revisionToReplace())
            : $revisioner->save();

        $this->fireModelEvent('revisioned', false);

        return $revision;
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
     * Fire an event bypassing fireModelEvent(), which only supports a single argument and
     * could be overridden by other traits.
     */
    protected function fireRevisionEvent(string $event, bool $halt, RevisionContract $revision): mixed
    {
        $dispatcher = static::getEventDispatcher();

        if (! $dispatcher) {
            return true;
        }

        $method = $halt ? 'until' : 'dispatch';

        if ($eventClass = $this->dispatchesEvents[$event] ?? null) {
            if ($dispatcher->$method(new $eventClass($this, $revision)) === false && $halt) {
                return false;
            }
        }

        return $dispatcher->$method(
            "eloquent.{$event}: " . static::class,
            [$this, $revision]
        );
    }

    /**
     * Save a rollback revision, always as a new record and marked as a rollback.
     */
    protected function saveAsRollbackRevision(RevisableOptions $options, Revision $revision): Revision
    {
        return app(Revisioner::class)
            ->for($this)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->withRelationFilters($options->relationFilters)
            ->limit($options->limit)
            ->type(RevisionType::Rollback)
            ->properties([
                'rollback_from' => $revision->name,
            ])
            ->save();
    }
}
