<?php

namespace TestMonitor\Revisable\Diffing\Support;

/**
 * A before/after slice that `ArrayAligner` considers aligned: a matched item, or a
 * differing run to be diffed positionally.
 */
readonly class AlignedBlock
{
    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {}
}
