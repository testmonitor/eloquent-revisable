<?php

namespace TestMonitor\Revisable\Tests\Diffing\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\Markdown\BlockChange;
use TestMonitor\Revisable\Diffing\Markdown\ChangeRenderer;
use TestMonitor\Revisable\Diffing\Markdown\FormattingRenderer;
use TestMonitor\Revisable\Diffing\Markdown\InlineChange;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class ChangeNodeTest extends TestCase
{
    // Inline markers

    #[Test]
    public function it_builds_an_inline_marker_for_added_content()
    {
        // Given
        $type = ChangeType::Added;

        // When
        $node = new InlineChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function it_builds_an_inline_marker_for_removed_content()
    {
        // Given
        $type = ChangeType::Removed;

        // When
        $node = new InlineChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function it_rejects_an_inline_marker_for_kept_content()
    {
        // Given
        $type = ChangeType::Kept;

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        new InlineChange($type);
    }

    #[Test]
    public function it_rejects_an_inline_marker_for_changed_content()
    {
        // Given
        $type = ChangeType::Changed;

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        new InlineChange($type);
    }

    // Block markers

    #[Test]
    public function it_builds_a_block_marker_for_added_content()
    {
        // Given
        $type = ChangeType::Added;

        // When
        $node = new BlockChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function it_builds_a_block_marker_for_removed_content()
    {
        // Given
        $type = ChangeType::Removed;

        // When
        $node = new BlockChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function it_rejects_a_block_marker_for_kept_content()
    {
        // Given
        $type = ChangeType::Kept;

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        new BlockChange($type);
    }

    #[Test]
    public function it_rejects_a_block_marker_for_changed_content()
    {
        // Given
        $type = ChangeType::Changed;

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        new BlockChange($type);
    }

    // Rendering

    #[Test]
    public function it_rejects_a_node_that_is_not_a_change_marker()
    {
        // Given
        $renderer = new ChangeRenderer;
        $node = new Text('hello');
        $childRenderer = $this->createStub(ChildNodeRendererInterface::class);

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        $renderer->render($node, $childRenderer);
    }

    #[Test]
    public function it_rejects_a_node_that_is_not_a_formatting_marker()
    {
        // Given
        $renderer = new FormattingRenderer;
        $node = new Text('hello');
        $childRenderer = $this->createStub(ChildNodeRendererInterface::class);

        // When / Then
        $this->expectException(InvalidArgumentException::class);
        $renderer->render($node, $childRenderer);
    }
}
