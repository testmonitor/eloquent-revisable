<?php

namespace TestMonitor\Revisable\Generators;

use TestMonitor\Revisable\Contracts\NameGenerator;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;

class NameGeneratorFactory
{
    public static function create(): ?NameGenerator
    {
        $class = config('revisable.name_generator');

        if ($class === null) {
            return null;
        }

        static::guardAgainstInvalidGenerator($class);

        return app($class);
    }

    protected static function guardAgainstInvalidGenerator(string $class): void
    {
        if (! class_exists($class) || ! is_a($class, NameGenerator::class, true)) {
            throw InvalidConfiguration::invalidNameGenerator($class);
        }
    }
}
