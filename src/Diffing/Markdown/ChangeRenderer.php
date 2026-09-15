<?php

namespace TestMonitor\Revisable\Diffing\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Renders both marker node types, leaving their children to CommonMark.
 */
final class ChangeRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof ChangeNode) {
            throw new \InvalidArgumentException(
                ChangeRenderer::class . ' received an unexpected node: ' . $node::class
            );
        }

        $tag = $node->changeType() === ChangeType::Removed ? 'del' : 'ins';

        return "<{$tag}>" . $childRenderer->renderNodes($node->children()) . "</{$tag}>";
    }
}
