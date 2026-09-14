<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Implemented by the inline and block marker nodes, so one renderer serves both.
 */
interface ChangeNode
{
    public function changeType(): ChangeType;
}
