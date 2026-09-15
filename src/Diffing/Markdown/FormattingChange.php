<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * Wraps text whose wording is unchanged but whose formatting is not.
 *
 * Carries no ChangeType, unlike InlineChange: a reformatted run belongs to neither side.
 */
final class FormattingChange extends AbstractInline
{
    public function __construct()
    {
        parent::__construct();
    }
}
