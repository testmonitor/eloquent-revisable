<?php

namespace TestMonitor\Revisable\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface Revision
{
    public function user(): BelongsTo;

    public function revisionable(): MorphTo;

    public function toModel(): Model;

    #[Scope]
    public function forUser(Builder $query, Authenticatable $user): void;

    #[Scope]
    public function forModel(Builder $query, int $id, string $type): void;
}
