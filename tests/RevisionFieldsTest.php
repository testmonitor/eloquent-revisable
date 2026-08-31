<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Attachment;
use TestMonitor\Revisable\Tests\Models\Author;
use TestMonitor\Revisable\Tests\Models\Comment;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\Tests\Models\Reply;
use TestMonitor\Revisable\Tests\Models\Tag;

final class RevisionFieldsTest extends TestCase
{
    #[Test]
    public function it_only_stores_the_specified_fields_in_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->onlyFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('name', $revision->metadata['attributes']);
        $this->assertArrayHasKey('votes', $revision->metadata['attributes']);
        $this->assertArrayNotHasKey('slug', $revision->metadata['attributes']);
        $this->assertArrayNotHasKey('content', $revision->metadata['attributes']);
        $this->assertArrayNotHasKey('views', $revision->metadata['attributes']);
    }

    #[Test]
    public function it_excludes_the_specified_fields_from_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->exceptFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayNotHasKey('name', $revision->metadata['attributes']);
        $this->assertArrayNotHasKey('votes', $revision->metadata['attributes']);
        $this->assertArrayHasKey('slug', $revision->metadata['attributes']);
        $this->assertArrayHasKey('content', $revision->metadata['attributes']);
        $this->assertArrayHasKey('views', $revision->metadata['attributes']);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_only_non_revisioned_fields_change()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->onlyFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $post->update([
            'slug' => 'changed-slug',
            'content' => 'Changed content',
        ]);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_only_excluded_fields_change()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->exceptFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $post->update([
            'name' => 'Changed name',
            'votes' => 99,
        ]);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_can_reconstruct_the_model_from_a_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();
        $model = $revision->toModel();

        $this->assertInstanceOf(Post::class, $model);
        $this->assertTrue($model->exists);
        $this->assertEquals($post->id, $model->id);
        $this->assertEquals('Another post name', $model->name);
        $this->assertEquals('another-post-slug', $model->slug);
    }

    #[Test]
    public function it_reconstructs_a_belongs_to_relation_on_to_model()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('author');
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('author'));
        $this->assertInstanceOf(Author::class, $model->author);
        $this->assertEquals('Author name', $model->author->name);
    }

    #[Test]
    public function it_reconstructs_a_has_one_relation_on_to_model()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('reply');
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('reply'));
        $this->assertInstanceOf(Reply::class, $model->reply);
        $this->assertEquals('Reply subject', $model->reply->subject);
    }

    #[Test]
    public function it_reconstructs_a_has_many_relation_on_to_model()
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
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('comments'));
        $this->assertInstanceOf(EloquentCollection::class, $model->comments);
        $this->assertCount(3, $model->comments);
        $this->assertContainsOnlyInstancesOf(Comment::class, $model->comments);
        $this->assertEquals('Comment title 1', $model->comments->first()->title);
    }

    #[Test]
    public function it_reconstructs_a_morph_many_relation_on_to_model()
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

        $post->withoutRevisioning(function () use ($post) {
            foreach (['Attachment 1', 'Attachment 2'] as $name) {
                $attachment = new Attachment(['name' => $name]);
                $attachment->attachmentable()->associate($post);
                $attachment->save();
            }
        });

        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('attachments'));
        $this->assertInstanceOf(EloquentCollection::class, $model->attachments);
        $this->assertCount(2, $model->attachments);
        $this->assertContainsOnlyInstancesOf(Attachment::class, $model->attachments);
        $this->assertEquals('Attachment 1', $model->attachments->first()->name);
    }

    #[Test]
    public function it_reconstructs_a_belongs_to_many_relation_with_pivot_data_on_to_model()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);

        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('tags'));
        $this->assertInstanceOf(EloquentCollection::class, $model->tags);
        $this->assertCount(3, $model->tags);
        $this->assertContainsOnlyInstancesOf(Tag::class, $model->tags);
        $this->assertInstanceOf(Pivot::class, $model->tags->first()->pivot);
        $this->assertEquals($post->id, $model->tags->first()->pivot->post_id);
    }

    #[Test]
    public function it_returns_an_empty_collection_for_a_has_many_relation_with_no_items_on_to_model()
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

        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('comments'));
        $this->assertInstanceOf(EloquentCollection::class, $model->comments);
        $this->assertCount(0, $model->comments);
    }

    #[Test]
    public function it_returns_null_for_a_has_one_relation_with_no_items_on_to_model()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('reply');
            }
        };

        $post = $this->createPost($post);

        $this->modifyPost($post);

        // When
        $model = $post->revisions()->firstOrFail()->toModel();

        // Then
        $this->assertTrue($model->relationLoaded('reply'));
        $this->assertNull($model->reply);
    }
}
