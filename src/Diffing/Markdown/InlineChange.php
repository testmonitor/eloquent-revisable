<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Wraps changed inline content so the renderer can mark it. Inserted into the AST by
 * MarkdownDriver, never produced by parsing.
 */
final class InlineChange extends AbstractInline implements ChangeNode
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
