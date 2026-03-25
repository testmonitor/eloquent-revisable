<?php

namespace TestMonitor\Revisable\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use TestMonitor\Revisable\Contracts\NameGenerator;
use TestMonitor\Revisable\Models\Revision;

class InvalidConfiguration extends Exception
{
    public static function invalidRevisionModel(string $className): self
    {
        return new static(
            "The given model class `{$className}` does not implement `"
            . Revision::class
            . '` or it does not extend `'
            . Model::class . '`'
        );
    }

    public static function invalidUserModel(string $className): self
    {
        return new static("The given model class `{$className}` does not extend `" . Model::class . '`');
    }

    public static function invalidNameGenerator(string $className): self
    {
        return new static(
            "The given class `{$className}` does not exist or does not implement `"
            . NameGenerator::class . '`'
        );
    }
}
