<?php

namespace TestMonitor\Revisable\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use TestMonitor\Revisable\Models\Revision;

/**
 * Implemented by models using the HasRevisions trait; declared separately so
 * Revisioner can be type-checked against it without depending on the trait.
 */
interface Revisable
{
    /**
     * @return MorphMany<Revision, Model>
     */
    public function revisions(): MorphMany;

    /**
     * @return MorphOne<Revision, Model>
     */
    public function latestRevision(): MorphOne;

    /**
     * @return array<string, mixed>
     */
    public function getRevisionOriginal(): array;

    public function withoutRevisioning(Closure $callback): mixed;
}
