<?php

namespace TestMonitor\Revisable\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TestMonitor\Revisable\Relations\BelongsToMany;
use TestMonitor\Revisable\Relations\MorphToMany;

/**
 * Optional trait that creates a revision whenever a tracked BelongsToMany or
 * MorphToMany relation changes via attach, detach, sync, toggle, or
 * updateExistingPivot.
 *
 * Requires HasRevisions to be present on the same model.
 *
 * @mixin Model
 * @mixin HasRevisions
 */
trait HasRevisionablePivots
{
    /**
     * Called by the custom relation classes after every pivot mutation.
     * Only proceeds when the relation is explicitly tracked via withRelations().
     */
    public function createRevisionForRelationChange(string $relationName): void
    {
        $options = $this->getRevisionOptions();

        if (! in_array($relationName, $options->relations)) {
            return;
        }

        if (! $options->isEnabled()) {
            return;
        }

        if ($this->isRevisioningDisabled()) {
            return;
        }

        $this->unsetRelation($relationName);

        $this->saveAsRevision();
    }

    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null
    ): BelongsToMany {
        return new BelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );
    }

    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false
    ): MorphToMany {
        return new MorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse
        );
    }
}
