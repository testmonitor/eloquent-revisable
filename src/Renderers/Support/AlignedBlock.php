<?php

namespace TestMonitor\Revisable\Renderers\Support;

/**
 * A before/after slice that `ArrayAligner` considers aligned: a matched item, or a
 * differing run to be diffed positionally.
 */
readonly class AlignedBlock
{
    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {}
}
