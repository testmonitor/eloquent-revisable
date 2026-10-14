<?php

namespace TestMonitor\Revisable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as BaseBelongsToMany;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BaseBelongsToMany<TRelatedModel, TDeclaringModel>
 */
class BelongsToMany extends BaseBelongsToMany
{
    use PivotEventsTrait;
}
