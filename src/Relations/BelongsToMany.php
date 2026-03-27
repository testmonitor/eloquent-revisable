<?php

namespace TestMonitor\Revisable\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany as BaseBelongsToMany;

class BelongsToMany extends BaseBelongsToMany
{
    use PivotEventsTrait;
}
