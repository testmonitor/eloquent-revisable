<?php

namespace TestMonitor\Revisable\Renderers\Support;

/**
 * Marks matching content at $beforeIndex in one array and $afterIndex in the other —
 * a reliable alignment point since it's unique on both sides.
 */
readonly class AnchorPair
{
    public function __construct(
        public int $beforeIndex,
        public int $afterIndex,
    ) {}
}
