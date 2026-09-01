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
    /**
     * @var list<class-string>
     */
    protected static array $directRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
        BelongsTo::class,
        MorphTo::class,
    ];

    /**
     * @var list<class-string>
     */
    protected static array $pivotedRelations = [
        BelongsToMany::class,
        MorphToMany::class,
    ];

    /**
     * @var list<class-string>
     */
    protected static array $childRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
    ];

    /**
     * @var list<class-string>
     */
    protected static array $singularRelations = [
        HasOne::class,
        MorphOne::class,
        BelongsTo::class,
        MorphTo::class,
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

    public static function isSingular(string $relation): bool
    {
        return static::isInstanceOf($relation, static::$singularRelations);
    }

    /**
     * @param list<class-string> $types
     */
    protected static function isInstanceOf(string $relation, array $types): bool
    {
        return array_any($types, fn ($type) => is_a($relation, $type, true));
    }
}
