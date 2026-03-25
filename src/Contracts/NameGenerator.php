<?php

namespace TestMonitor\Revisable\Contracts;

use Illuminate\Database\Eloquent\Model;

interface NameGenerator
{
    public function generate(Model $model): string;
}
