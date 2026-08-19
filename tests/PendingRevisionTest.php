<?php

namespace TestMonitor\Revisable\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;

class PendingRevisionTest extends TestCase
{
    #[Test]
    public function it_reports_the_metadata_captured_at_queue_time()
    {
        // Given / When
        $pending = $this->pendingRevision();

        // Then
        $this->assertArrayHasKey('attributes', $pending->metadata());
    }

    #[Test]
    public function it_reports_default_type_for_an_existing_model()
    {
        // Given / When
        $pending = $this->pendingRevision();

        // Then
        $this->assertTrue($pending->isDefault());
        $this->assertFalse($pending->isInitial());
        $this->assertFalse($pending->isRollback());
    }

    #[Test]
    public function it_delegates_the_timestamp_column_names()
    {
        // Given / When
        $pending = $this->pendingRevision();

        // Then
        $this->assertSame('created_at', $pending->getCreatedAtColumn());
        $this->assertSame('updated_at', $pending->getUpdatedAtColumn());
    }

    #[Test]
    public function it_diffs_against_nothing_by_default()
    {
        // Given / When
        $pending = $this->pendingRevision();

        // Then
        $this->assertInstanceOf(Diff::class, $pending->diff());
    }

    #[Test]
    public function it_throws_when_asked_for_its_position_in_the_revision_history()
    {
        // Given
        $pending = $this->pendingRevision();

        // When / Then
        foreach (['previous', 'next', 'isFirstRevision', 'isLastRevision', 'version', 'toModel'] as $method) {
            try {
                $pending->{$method}();
                $this->fail("Expected {$method}() to throw a LogicException.");
            } catch (LogicException $exception) {
                $this->assertInstanceOf(LogicException::class, $exception);
            }
        }
    }

    #[Test]
    public function it_throws_when_compared_against_another_revision()
    {
        // Given
        $post = $this->createPost();
        $pending = $this->pendingRevision($post);
        $other = $this->pendingRevision($post);

        // When / Then
        foreach (['is', 'isNewerThan', 'isOlderThan'] as $method) {
            try {
                $pending->{$method}($other);
                $this->fail("Expected {$method}() to throw a LogicException.");
            } catch (LogicException $exception) {
                $this->assertInstanceOf(LogicException::class, $exception);
            }
        }
    }
}
