<?php

namespace TestMonitor\Revisable\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use TestMonitor\Revisable\Models\Revision;

final class InvalidConfiguration extends Exception
{
    public static function invalidRevisionModel(string $className): self
    {
        return new self(
            "The given model class `{$className}` does not implement `"
            . Revision::class
            . '` or it does not extend `'
            . Model::class . '`'
        );
    }

    public static function invalidUserModel(string $className): self
    {
        return new self("The given model class `{$className}` does not extend `" . Model::class . '`');
    }
}
