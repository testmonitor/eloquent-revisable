<?php

namespace TestMonitor\Revisable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;

class PendingRevision implements RevisionContract
{
    public function __construct(
        protected RevisionContract $revision,
    ) {}

    public function revisionable(): MorphTo
    {
        return $this->revision->revisionable();
    }

    public function user(): BelongsTo
    {
        return $this->revision->user();
    }

    public function getCreatedAtColumn()
    {
        return $this->revision->getCreatedAtColumn();
    }

    public function getUpdatedAtColumn()
    {
        return $this->revision->getUpdatedAtColumn();
    }

    public function previous(): ?static
    {
        $this->unavailableWhilePending();
    }

    public function next(): ?static
    {
        $this->unavailableWhilePending();
    }

    public function is(RevisionContract $model): never
    {
        $this->unavailableWhilePending();
    }

    public function isDefault(): bool
    {
        return $this->revision->isDefault();
    }

    public function isInitial(): bool
    {
        return $this->revision->isInitial();
    }

    public function isRollback(): bool
    {
        return $this->revision->isRollback();
    }

    public function isFirstRevision(): bool
    {
        $this->unavailableWhilePending();
    }

    public function isLastRevision(): bool
    {
        $this->unavailableWhilePending();
    }

    public function isNewerThan(?RevisionContract $revision): bool
    {
        $this->unavailableWhilePending();
    }

    public function isOlderThan(?RevisionContract $revision): bool
    {
        $this->unavailableWhilePending();
    }

    public function metadata(): array
    {
        return $this->revision->metadata();
    }

    public function version(): int
    {
        $this->unavailableWhilePending();
    }

    public function diff(?RevisionContract $target = null): Diff
    {
        return $this->revision->diff($target);
    }

    public function diffFromPrevious(): Diff
    {
        $this->unavailableWhilePending();
    }

    public function toModel(): Model
    {
        $this->unavailableWhilePending();
    }

    /**
     * A pending revision has not been persisted yet, so it has no identity or position in history.
     */
    protected function unavailableWhilePending(): never
    {
        throw new LogicException('Cannot use this on a pending, unsaved revision.');
    }
}
