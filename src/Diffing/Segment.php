<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * A run of text sharing one change type. Concatenating every segment of one side
 * reproduces that side's text exactly, which is how a driver maps a segment back to the
 * content that produced it.
 */
readonly class Segment
{
    public function __construct(
        public ChangeType $type,
        public string $text,
    ) {}
}
