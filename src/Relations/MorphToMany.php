<?php

namespace TestMonitor\Revisable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany as BaseMorphToMany;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BaseMorphToMany<TRelatedModel, TDeclaringModel>
 */
class MorphToMany extends BaseMorphToMany
{
    use PivotEventsTrait;
}
