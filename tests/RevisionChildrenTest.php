<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Attachment;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionChildrenTest extends TestCase
{
    #[Test]
    public function it_creates_a_revision_when_a_tracked_child_is_created()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);

        // When
        $attachment->save();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_tracked_child_is_updated()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);
        $post->withoutRevisioning(fn () => $attachment->save());

        // When
        $attachment->update(['name' => 'updated.pdf']);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_tracked_child_is_deleted()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);
        $post->withoutRevisioning(fn () => $attachment->save());

        // When
        $attachment->delete();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_tracked_child_is_restored()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);
        $post->withoutRevisioning(function () use ($attachment) {
            $attachment->save();
            $attachment->delete();
        });

        // When
        $attachment->restore();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_tracked_child_is_force_deleted()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);
        $post->withoutRevisioning(fn () => $attachment->save());

        // When
        $attachment->forceDelete();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_relation_is_not_tracked()
    {
        // Given
        $post = $this->createPost();

        $attachment = $this->makeAttachment($post);

        // When
        $attachment->save();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_revisioning_is_disabled()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);

        // When
        $post->withoutRevisioning(fn () => $attachment->save());

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_revisioning_event_returns_false()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);

        $post::revisioning(fn () => false);

        // When
        $attachment->save();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    private function makeAttachment(Post $post, array $attributes = []): Attachment
    {
        $attachment = $post->attachments()->make(array_merge(['name' => 'document.pdf'], $attributes));
        $attachment->setRelation('attachmentable', $post);

        return $attachment;
    }
}
