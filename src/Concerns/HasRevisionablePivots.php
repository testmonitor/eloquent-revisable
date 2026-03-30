<?php

namespace TestMonitor\Revisable\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TestMonitor\Revisable\Relations\BelongsToMany;
use TestMonitor\Revisable\Relations\MorphToMany;
use TestMonitor\Revisable\Revisioner;

/**
 * Optional trait that creates a revision whenever a tracked BelongsToMany or
 * MorphToMany relation changes via attach, detach, sync, toggle, or
 * updateExistingPivot.
 *
 * Requires HasRevisions to be present on the same model.
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

        if (! $this->revisioningEnabled) {
            return;
        }

        if ($this->fireModelEvent('revisioning') === false) {
            return;
        }

        app(Revisioner::class)
            ->for($this)
            ->nameUsing($options->nameGenerator)
            ->onlyFields($options->fields)
            ->exceptFields($options->exceptFields)
            ->withRelations($options->relations)
            ->limit($options->limit)
            ->when(
                $options->shouldReplace($this) ? $this->revisionToReplace($options) : null,
                fn ($revisioner, $existing) => $revisioner->replace($existing),
                fn ($revisioner) => $revisioner->save()
            );

        $this->fireModelEvent('revisioned', false);
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
