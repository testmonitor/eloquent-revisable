<?php

namespace TestMonitor\Revisable\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TestMonitor\Revisable\Diff;

interface Revision
{
    /**
     * @return MorphTo<Model, covariant Model>
     */
    public function revisionable(): MorphTo;

    /**
     * @return BelongsTo<Model, covariant Model>
     */
    public function user(): BelongsTo;

    /**
     * @return string|null
     */
    public function getCreatedAtColumn();

    /**
     * @return string|null
     */
    public function getUpdatedAtColumn();

    public function previous(): ?static;

    public function next(): ?static;

    /**
     * @return bool
     */
    public function is(self $model);

    public function isDefault(): bool;

    public function isInitial(): bool;

    public function isRollback(): bool;

    public function isFirstRevision(): bool;

    public function isLastRevision(): bool;

    public function isNewerThan(?self $revision): bool;

    public function isOlderThan(?self $revision): bool;

    /**
     * @return array{attributes?: array<string, mixed>, relations?: array<string, mixed>}
     */
    public function metadata(): array;

    public function version(): int;

    public function diff(?self $target = null): Diff;

    public function diffFromPrevious(): Diff;

    public function toModel(): Model;
}
