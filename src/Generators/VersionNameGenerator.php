<?php

namespace TestMonitor\Revisable\Generators;

use Illuminate\Database\Eloquent\Model;
use TestMonitor\Revisable\Contracts\NameGenerator;

class VersionNameGenerator implements NameGenerator
{
    public function generate(Model $model): string
    {
        return 'v' . ($model->revisions()->count() + 1);
    }
}
