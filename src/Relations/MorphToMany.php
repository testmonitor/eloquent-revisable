<?php

namespace TestMonitor\Revisable\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany as BaseMorphToMany;

class MorphToMany extends BaseMorphToMany
{
    use PivotEventsTrait;
}
