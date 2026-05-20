<?php

namespace TestMonitor\Revisable\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use TestMonitor\Revisable\RelationType;
use Throwable;

/**
 * Add to a child model in a HasOne, MorphOne, HasMany, or MorphMany relationship
 * to automatically trigger a revision on the parent whenever the child is saved or deleted.
 *
 * Requires HasRevisionableChildren to be present on the parent model.
 *
 * @mixin Model
 */
trait BelongsToRevisable
{
    public static function bootBelongsToRevisable(): void
    {
        static::saved(fn (Model $model) => $model->notifyRevisableParent());
        static::deleted(fn (Model $model) => $model->notifyRevisableParent());
    }

    /**
     * Return the parent model that uses HasRevisions and HasRevisionableChildren.
     * Override to specify explicitly when auto-detection is ambiguous.
     */
    protected function revisableParent(): ?Model
    {
        return $this->detectRevisableParent();
    }

    protected function notifyRevisableParent(): void
    {
        $parent = $this->revisableParent();

        if (! $parent || ! method_exists($parent, 'createRevisionForChildChange')) {
            return;
        }

        $relation = $this->detectRevisableParentRelation($parent);

        if ($relation !== null) {
            $parent->createRevisionForChildChange($relation);
        }
    }

    /**
     * Scans the child's own BelongsTo/MorphTo relations to find the parent model
     * that uses HasRevisionableChildren. Result is cached per child class.
     */
    private function detectRevisableParent(): ?Model
    {
        static $cache = [];

        $class = static::class;

        if (! array_key_exists($class, $cache)) {
            $cache[$class] = $this->scanForParentRelationMethod();
        }

        if ($cache[$class] === null) {
            return null;
        }

        [$methodName, $isMorphTo] = $cache[$class];

        $related = $this->getRelationValue($methodName);

        if (! $related instanceof Model) {
            return null;
        }

        // MorphTo related class varies per record, so verify HasRevisionableChildren at runtime.
        if ($isMorphTo && ! in_array(HasRevisionableChildren::class, class_uses_recursive($related))) {
            return null;
        }

        return $related;
    }

    /**
     * Scans public no-arg methods for BelongsTo/MorphTo relations.
     * Prefers a BelongsTo whose related class statically declares HasRevisionableChildren
     * over a MorphTo (which can only be verified at runtime).
     */
    private function scanForParentRelationMethod(): ?array
    {
        $morphToMethod = null;

        foreach ((new ReflectionClass($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }

            try {
                $relation = $this->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }

            if ($relation instanceof MorphTo) {
                $morphToMethod ??= [$method->getName(), true];

                continue;
            }

            if ($relation instanceof BelongsTo) {
                $relatedClass = get_class($relation->getRelated());

                if (in_array(HasRevisionableChildren::class, class_uses_recursive($relatedClass))) {
                    return [$method->getName(), false];
                }
            }
        }

        return $morphToMethod;
    }

    /**
     * Scans the parent's public no-arg methods for a HasOne/MorphOne/HasMany/MorphMany
     * whose related class matches this child. Result is cached per parent–child class pair.
     */
    private function detectRevisableParentRelation(Model $parent): ?string
    {
        static $cache = [];

        $cacheKey = get_class($parent) . ':' . static::class;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        foreach ((new ReflectionClass($parent))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }

            try {
                $relation = $parent->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation || ! RelationType::isChild(get_class($relation))) {
                continue;
            }

            if (is_a($this, get_class($relation->getRelated()))) {
                return $cache[$cacheKey] = $method->getName();
            }
        }

        return $cache[$cacheKey] = null;
    }
}
