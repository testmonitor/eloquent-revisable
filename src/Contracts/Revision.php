<?php

namespace TestMonitor\Revisable\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TestMonitor\Revisable\Diff;

interface Revision
{
    public function user(): BelongsTo;

    public function revisionable(): MorphTo;

    public function previous(): ?static;

    public function diff(?self $target = null): Diff;

    public function toModel(): Model;
}
