<?php

namespace TestMonitor\Revisable\Diffing;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * A run of text sharing one change type; one side's segments concatenate to that side's text.
 */
readonly class Segment
{
    public function __construct(
        public ChangeType $type,
        public string $text,
    ) {}
}
