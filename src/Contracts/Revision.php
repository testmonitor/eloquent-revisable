<?php

namespace TestMonitor\Revisable\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TestMonitor\Revisable\Diff;

interface Revision
{
    public function revisionable(): MorphTo;

    public function user(): BelongsTo;

    public function previous(): ?static;

    public function next(): ?static;

    public function metadata(): array;

    public function diff(?self $target = null): Diff;

    public function diffFromPrevious(): Diff;

    public function toModel(): Model;
}
