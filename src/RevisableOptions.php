<?php

namespace TestMonitor\Revisable;

use Illuminate\Support\Arr;
use TestMonitor\Revisable\Contracts\NameGenerator;
use TestMonitor\Revisable\Generators\NameGeneratorFactory;

class RevisableOptions
{
    /**
     * Controls whether revisioning is active for this model.
     * Accepts a boolean or a callable returning a boolean, allowing
     * dynamic evaluation via feature flags or other conditions.
     *
     * @var bool|callable
     */
    public mixed $enabled = true;

    /**
     * Flag whether to make a revision on model creation.
     */
    public bool $onCreate = false;

    /**
     * Flag whether to create a revision after rolling back.
     * Enabled by default so every rollback is itself captured as a revision.
     */
    public bool $revisionOnRollback = true;

    /**
     * The limit of revisions to be created for a model instance.
     * If the limit is reached, oldest revisions will start getting deleted to make room for new ones.
     */
    public ?int $limit = null;

    /**
     * The fields that should be revisionable.
     * By default all fields are revisionable.
     */
    public array $fields = [];

    /**
     * The fields that should be excluded from revisioning.
     * By default no fields are excluded from revisioning.
     */
    public array $exceptFields = [];

    /**
     * The model's relations that should be revisionable.
     * By default none of the model's relations are revisionable.
     */
    public array $relations = [];

    /**
     * The generator used to produce a name for each revision.
     * Defaults to the generator configured in config/revisable.php. Set to null to disable auto-naming.
     */
    public ?NameGenerator $nameGenerator = null;

    /**
     * Start configuring model with the default options.
     */
    public static function defaults(): self
    {
        $options = new static;

        $options->nameGenerator = NameGeneratorFactory::create();

        return $options;
    }

    /**
     * Control whether revisioning is active using a boolean or a callable.
     */
    public function enabledWhen(mixed $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Resolve the enabled state, evaluating the callable if one was provided.
     */
    public function isEnabled(): bool
    {
        return is_callable($this->enabled) ? (bool) ($this->enabled)() : (bool) $this->enabled;
    }

    /**
     * Create a revision when the model is first created, in addition to updates.
     */
    public function enableRevisionOnCreate(): self
    {
        $this->onCreate = true;

        return $this;
    }

    /**
     * Do not create a revision after rolling back to a previous revision.
     */
    public function disableRevisionOnRollback(): self
    {
        $this->revisionOnRollback = false;

        return $this;
    }

    /**
     * Keep only the most recent revisions up to the given limit, pruning older ones automatically.
     */
    public function limitRevisionsTo(int $limit): self
    {
        $this->limit = (int) $limit;

        return $this;
    }

    /**
     * Set the fields to include when creating a revision.
     */
    public function onlyFields(...$fields): self
    {
        $this->fields = Arr::flatten($fields);

        return $this;
    }

    /**
     * Set the fields to exclude when creating a revision.
     */
    public function exceptFields(...$fields): self
    {
        $this->exceptFields = Arr::flatten($fields);

        return $this;
    }

    /**
     * Set the relations to include when creating a revision.
     */
    public function withRelations(...$relations): self
    {
        $this->relations = Arr::flatten($relations);

        return $this;
    }

    /**
     * Set a custom name generator for revisions. Pass null to disable auto-naming.
     */
    public function nameRevisionUsing(?NameGenerator $generator): self
    {
        $this->nameGenerator = $generator;

        return $this;
    }
}
