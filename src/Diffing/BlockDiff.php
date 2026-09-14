<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * One block of content (a line of plain text, or a markdown block node) and how it changed.
 */
readonly class BlockDiff
{
    /**
     * @param list<Segment> $segments
     */
    public function __construct(
        public ChangeType $status,
        public array $segments = [],
    ) {}
}
