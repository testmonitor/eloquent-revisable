<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Attachment;
use TestMonitor\Revisable\Tests\Models\Comment;
use TestMonitor\Revisable\Tests\Models\Post;

final class RevisionChildrenTest extends TestCase
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
    public function it_does_not_create_a_revision_when_revisioning_is_globally_disabled_via_options()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('attachments')
                    ->enabledWhen(false);
            }
        };

        $post = $this->createPost($post);

        $attachment = $this->makeAttachment($post);

        // When
        $attachment->save();

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

    #[Test]
    public function it_creates_a_revision_when_a_belongs_to_child_is_created()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('comments');
            }
        };

        $post = $this->createPost($post);

        $comment = $this->makeComment($post);

        // When
        $comment->save();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_belongs_to_child_is_updated()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('comments');
            }
        };

        $post = $this->createPost($post);

        $comment = $this->makeComment($post);
        $post->withoutRevisioning(fn () => $comment->save());

        // When
        $comment->update(['title' => 'Updated title']);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_a_belongs_to_child_is_deleted()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('comments');
            }
        };

        $post = $this->createPost($post);

        $comment = $this->makeComment($post);
        $post->withoutRevisioning(fn () => $comment->save());

        // When
        $comment->delete();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_child_has_no_revisable_parent_relation()
    {
        // Given
        $post = $this->createPost();

        $child = new class extends Comment
        {
            public function post(): BelongsTo
            {
                return $this->belongsTo(Revision::class, 'post_id');
            }
        };

        $child->fill([
            'post_id' => $post->id,
            'title' => 'Test',
            'content' => 'Content',
            'date' => now()->toDateString(),
            'active' => false,
        ]);

        // When
        $child->save();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_parent_record_does_not_exist()
    {
        // Given
        $attachment = (new Attachment)->forceFill([
            'name' => 'orphan.pdf',
            'attachmentable_type' => Post::class,
            'attachmentable_id' => 99999,
        ]);

        // When
        $attachment->save();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_morph_parent_lacks_the_trait()
    {
        // Given
        $post = $this->createPost();

        $attachment = $this->makeAttachment($post);

        $plainModel = new class extends Model
        {
            protected $table = 'authors';
        };

        $attachment->setRelation('attachmentable', $plainModel);

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

    private function makeComment(Post $post, array $attributes = []): Comment
    {
        $comment = $post->comments()->make(array_merge([
            'title' => 'Test comment',
            'content' => 'Test content',
            'date' => now()->toDateString(),
            'active' => false,
        ], $attributes));
        $comment->setRelation('post', $post);

        return $comment;
    }
}
