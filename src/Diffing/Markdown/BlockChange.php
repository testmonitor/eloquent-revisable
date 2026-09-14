<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Wraps a wholly added or removed block. Block-level <ins>/<del> is valid HTML, since
 * both elements take transparent content.
 */
final class BlockChange extends AbstractBlock implements ChangeNode
{
    public function __construct(protected ChangeType $type)
    {
        parent::__construct();
    }

    public function changeType(): ChangeType
    {
        return $this->type;
    }
}
