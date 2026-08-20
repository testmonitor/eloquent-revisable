<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Tests\Events\PostRolledBack;
use TestMonitor\Revisable\Tests\Events\PostRollingBack;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionEventsTest extends TestCase
{
    #[Test]
    public function it_fires_the_revisioning_event_before_a_revision_is_created()
    {
        // Given
        $fired = false;
        Post::revisioning(function (Post $post) use (&$fired) {
            $fired = true;
        });
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertTrue($fired);
    }

    #[Test]
    public function it_fires_the_revisioned_event_after_a_revision_is_created()
    {
        // Given
        $capturedRevision = null;
        Post::revisioned(function (Post $post) use (&$capturedRevision) {
            $capturedRevision = $post->latestRevision;
        });
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertInstanceOf(Revision::class, $capturedRevision);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_revisioning_event_returns_false()
    {
        // Given
        Post::revisioning(fn () => false);
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_fires_the_rolling_back_event_before_a_rollback()
    {
        // Given
        $fired = false;
        Post::rollingBack(function (Post $post) use (&$fired) {
            $fired = true;
        });
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertTrue($fired);
    }

    #[Test]
    public function it_passes_the_target_revision_to_the_rolling_back_listener()
    {
        // Given
        $capturedRevision = null;
        Post::rollingBack(function (Post $post, Revision $revision) use (&$capturedRevision) {
            $capturedRevision = $revision;
        });
        $post = $this->createPost();
        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();

        // When
        $post->rollbackToRevision($revision);

        // Then
        $this->assertTrue($revision->is($capturedRevision));
    }

    #[Test]
    public function it_allows_the_rolling_back_listener_to_mutate_the_revision_before_restore()
    {
        // Given
        Post::rollingBack(function (Post $post, Revision $revision) {
            $metadata = $revision->metadata;
            $metadata['attributes']['name'] = 'Overridden by listener';
            $revision->metadata = $metadata;
        });
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals('Overridden by listener', $post->fresh()->name);
    }

    #[Test]
    public function it_does_not_roll_back_when_the_rolling_back_event_returns_false()
    {
        // Given
        Post::rollingBack(fn () => false);
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $result = $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertFalse($result);
        $this->assertEquals('Another post name', $post->fresh()->name);
    }

    #[Test]
    public function it_fires_the_rolled_back_event_after_a_rollback()
    {
        // Given
        $fired = false;
        Post::rolledBack(function (Post $post) use (&$fired) {
            $fired = true;
        });
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertTrue($fired);
    }

    #[Test]
    public function it_passes_the_target_revision_to_the_rolled_back_listener()
    {
        // Given
        $capturedRevision = null;
        Post::rolledBack(function (Post $post, Revision $revision) use (&$capturedRevision) {
            $capturedRevision = $revision;
        });
        $post = $this->createPost();
        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();

        // When
        $post->rollbackToRevision($revision);

        // Then
        $this->assertTrue($revision->is($capturedRevision));
    }

    #[Test]
    public function it_dispatches_the_mapped_rolled_back_event_class_with_the_target_revision()
    {
        // Given
        Event::fake([PostRolledBack::class]);
        $post = $this->createPost();
        $this->modifyPost($post);
        $revision = $post->revisions()->firstOrFail();

        // When
        $post->rollbackToRevision($revision);

        // Then
        Event::assertDispatched(
            PostRolledBack::class,
            fn (PostRolledBack $event) => $event->post->is($post) && $event->revision->is($revision)
        );
    }

    #[Test]
    public function it_does_not_roll_back_when_the_mapped_rolling_back_event_class_returns_false()
    {
        // Given
        Event::listen(PostRollingBack::class, fn () => false);
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $result = $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertFalse($result);
        $this->assertEquals('Another post name', $post->fresh()->name);
    }
}
