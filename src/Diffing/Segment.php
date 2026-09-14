<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * A run of text sharing one change type. The offsets locate the run in the tokenised
 * before/after streams, which is how the markdown driver maps a segment back to the
 * AST node that produced it.
 */
readonly class Segment
{
    public function __construct(
        public ChangeType $type,
        public string $text,
        public int $beforeOffset = 0,
        public int $afterOffset = 0,
    ) {}
}
