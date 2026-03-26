<?php

namespace TestMonitor\Revisable;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class RelationType
{
    protected static array $directRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
        BelongsTo::class,
        MorphTo::class,
    ];

    protected static array $pivotedRelations = [
        BelongsToMany::class,
        MorphToMany::class,
    ];

    protected static array $childRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
    ];

    public static function isDirect(string $relation): bool
    {
        return static::isInstanceOf($relation, static::$directRelations);
    }

    public static function isPivoted(string $relation): bool
    {
        return static::isInstanceOf($relation, static::$pivotedRelations);
    }

    public static function isChild(string $relation): bool
    {
        return static::isInstanceOf($relation, static::$childRelations);
    }

    protected static function isInstanceOf(string $relation, array $types): bool
    {
        foreach ($types as $type) {
            if (is_a($relation, $type, true)) {
                return true;
            }
        }

        return false;
    }
}
