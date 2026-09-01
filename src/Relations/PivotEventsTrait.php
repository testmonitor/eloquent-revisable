<?php

namespace TestMonitor\Revisable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Overrides mutating BelongsToMany/MorphToMany methods so that the parent model
 * is notified after every pivot change. A bulk-operation guard prevents
 * double-firing when high-level methods (sync, toggle) call attach/detach
 * internally.
 *
 * @property Model $parent
 * @property string $relationName
 */
trait PivotEventsTrait
{
    private bool $isBulkPivotOperation = false;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     */
    public function attach(mixed $ids, array $attributes = [], $touch = true): void
    {
        parent::attach($ids, $attributes, $touch);

        if (! $this->isBulkPivotOperation) {
            $this->touchParentRevision();
        }
    }

    /**
     * @param  bool  $touch
     */
    public function detach(mixed $ids = null, $touch = true): int
    {
        $result = parent::detach($ids, $touch);

        if ($result > 0 && ! $this->isBulkPivotOperation) {
            $this->touchParentRevision();
        }

        return $result;
    }

    /**
     * @param  Collection<array-key, Model>|Model|array<array-key, mixed>|int|string  $ids
     * @param  bool  $detaching
     * @return array{
     *     attached: array<array-key, int|string>,
     *     detached: array<array-key, int|string>,
     *     updated: array<array-key, int|string>,
     * }
     */
    public function sync(mixed $ids, $detaching = true): array
    {
        $this->isBulkPivotOperation = true;

        try {
            $changes = parent::sync($ids, $detaching);
        } finally {
            $this->isBulkPivotOperation = false;
        }

        if ($this->hasPivotChanges($changes)) {
            $this->touchParentRevision();
        }

        return $changes;
    }

    /**
     * @param  bool  $touch
     * @return array{
     *     attached: array<array-key, int|string>,
     *     detached: array<array-key, int|string>,
     * }
     */
    public function toggle(mixed $ids, $touch = true): array
    {
        $this->isBulkPivotOperation = true;

        try {
            $changes = parent::toggle($ids, $touch);
        } finally {
            $this->isBulkPivotOperation = false;
        }

        if ($this->hasPivotChanges($changes)) {
            $this->touchParentRevision();
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     */
    public function updateExistingPivot(mixed $id, array $attributes, $touch = true): int
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        if ($result > 0) {
            $this->touchParentRevision();
        }

        return $result;
    }

    /**
     * @param  array{
     *     attached: array<array-key, int|string>,
     *     detached: array<array-key, int|string>,
     *     updated?: array<array-key, int|string>,
     * }  $changes
     */
    private function hasPivotChanges(array $changes): bool
    {
        return ! empty($changes['attached'])
            || ! empty($changes['detached'])
            || ! empty($changes['updated']);
    }

    private function touchParentRevision(): void
    {
        if (method_exists($this->parent, 'createRevisionForRelationChange')) {
            $this->parent->createRevisionForRelationChange($this->relationName);
        }
    }
}
