<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * Wraps text whose wording did not change but whose formatting did, for example a
 * sentence that gained emphasis. Inserted into the AST by MarkdownDriver, never
 * produced by parsing.
 *
 * Unlike InlineChange this carries no ChangeType: a formatting change belongs to
 * neither side's content, it only restates the same words differently.
 */
final class FormattingChange extends AbstractInline
{
    public function __construct()
    {
        parent::__construct();
    }
}
