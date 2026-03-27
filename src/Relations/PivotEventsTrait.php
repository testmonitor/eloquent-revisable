<?php

namespace TestMonitor\Revisable\Relations;

/**
 * Overrides mutating BelongsToMany/MorphToMany methods so that the parent model
 * is notified after every pivot change. A bulk-operation guard prevents
 * double-firing when high-level methods (sync, toggle) call attach/detach
 * internally.
 */
trait PivotEventsTrait
{
    private bool $isBulkPivotOperation = false;

    public function attach($id, array $attributes = [], $touch = true): void
    {
        parent::attach($id, $attributes, $touch);

        if (! $this->isBulkPivotOperation) {
            $this->touchParentRevision();
        }
    }

    public function detach($ids = null, $touch = true): int
    {
        $result = parent::detach($ids, $touch);

        if ($result > 0 && ! $this->isBulkPivotOperation) {
            $this->touchParentRevision();
        }

        return $result;
    }

    public function sync($ids, $detaching = true): array
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

    public function syncWithoutDetaching($ids): array
    {
        $this->isBulkPivotOperation = true;

        try {
            $changes = parent::syncWithoutDetaching($ids);
        } finally {
            $this->isBulkPivotOperation = false;
        }

        if ($this->hasPivotChanges($changes)) {
            $this->touchParentRevision();
        }

        return $changes;
    }

    public function toggle($ids, $touch = true): array
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

    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        if ($result > 0) {
            $this->touchParentRevision();
        }

        return $result;
    }

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
