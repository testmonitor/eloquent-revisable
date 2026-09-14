<?php

namespace TestMonitor\Revisable\Tests\Diffing\Markdown;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diffing\Markdown\BlockChange;
use TestMonitor\Revisable\Diffing\Markdown\ChangeRenderer;
use TestMonitor\Revisable\Diffing\Markdown\InlineChange;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Tests\TestCase;

final class ChangeNodeTest extends TestCase
{
    // InlineChange

    #[Test]
    public function inline_change_constructed_with_added_succeeds()
    {
        // Given
        $type = ChangeType::Added;

        // When
        $node = new InlineChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function inline_change_constructed_with_removed_succeeds()
    {
        // Given
        $type = ChangeType::Removed;

        // When
        $node = new InlineChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function inline_change_constructed_with_kept_throws()
    {
        // Given
        $type = ChangeType::Kept;

        // When/Then
        $this->expectException(\InvalidArgumentException::class);
        new InlineChange($type);
    }

    #[Test]
    public function inline_change_constructed_with_changed_throws()
    {
        // Given
        $type = ChangeType::Changed;

        // When/Then
        $this->expectException(\InvalidArgumentException::class);
        new InlineChange($type);
    }

    // BlockChange

    #[Test]
    public function block_change_constructed_with_added_succeeds()
    {
        // Given
        $type = ChangeType::Added;

        // When
        $node = new BlockChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function block_change_constructed_with_removed_succeeds()
    {
        // Given
        $type = ChangeType::Removed;

        // When
        $node = new BlockChange($type);

        // Then
        $this->assertSame($type, $node->changeType());
    }

    #[Test]
    public function block_change_constructed_with_kept_throws()
    {
        // Given
        $type = ChangeType::Kept;

        // When/Then
        $this->expectException(\InvalidArgumentException::class);
        new BlockChange($type);
    }

    #[Test]
    public function block_change_constructed_with_changed_throws()
    {
        // Given
        $type = ChangeType::Changed;

        // When/Then
        $this->expectException(\InvalidArgumentException::class);
        new BlockChange($type);
    }

    // ChangeRenderer

    #[Test]
    public function change_renderer_throws_on_non_change_node()
    {
        // Given
        $renderer = new ChangeRenderer;
        $node = new Text('hello');
        $childRenderer = $this->createStub(ChildNodeRendererInterface::class);

        // When/Then
        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($node, $childRenderer);
    }
}
