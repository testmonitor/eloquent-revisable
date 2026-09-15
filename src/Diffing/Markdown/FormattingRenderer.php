<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a formatting-only change as <ins class="mod">, distinguishing it from a real
 * insertion by its class rather than its tag. Children are rendered by CommonMark, so
 * the surrounding markup stays balanced.
 */
final class FormattingRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof FormattingChange) {
            throw new \InvalidArgumentException(
                FormattingRenderer::class . ' received an unexpected node: ' . $node::class
            );
        }

        return '<ins class="mod">' . $childRenderer->renderNodes($node->children()) . '</ins>';
    }
}
