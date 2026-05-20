<?php

namespace TestMonitor\Revisable\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional trait that creates a revision whenever a tracked HasOne, MorphOne,
 * HasMany, or MorphMany child is saved or deleted.
 *
 * Requires HasRevisions to be present on the same model.
 * Requires BelongsToRevisable to be present on each child model.
 *
 * @mixin Model
 * @mixin HasRevisions
 *
 * @property bool $revisioningEnabled
 */
trait HasRevisionableChildren
{
    /**
     * Called by BelongsToRevisable on the child after every save or delete.
     * Only proceeds when the relation is explicitly tracked via withRelations().
     */
    public function createRevisionForChildChange(string $relation): void
    {
        $options = $this->getRevisionOptions();

        if (! in_array($relation, $options->relations)) {
            return;
        }

        if (! $this->revisioningEnabled) {
            return;
        }

        if ($this->fireModelEvent('revisioning') === false) {
            return;
        }

        $this->saveAsRevision();

        $this->fireModelEvent('revisioned', false);
    }
}
