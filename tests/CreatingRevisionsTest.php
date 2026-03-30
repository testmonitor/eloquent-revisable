<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\UserResolver;

class CreatingRevisionsTest extends TestCase
{
    #[Test]
    public function it_automatically_creates_a_revision_when_the_record_changes()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_can_access_the_revisionable_model_from_a_revision()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);

        // When
        $revision = $post->revisions()->firstOrFail();

        // Then
        $this->assertTrue($post->is($revision->revisionable));
    }

    #[Test]
    public function it_can_access_the_first_and_latest_revision()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        // When / Then
        $this->assertEquals('Post name', $post->firstRevision->metadata['name']);
        $this->assertEquals('Another post name', $post->latestRevision->metadata['name']);
    }

    #[Test]
    public function it_can_scope_revisions_by_model()
    {
        // Given
        $postA = $this->createPost();
        $postB = Post::create(['author_id' => $postA->author_id, 'name' => 'Post name', 'slug' => 'post-b-slug', 'content' => 'Post content', 'votes' => 10, 'views' => 100]);

        $this->modifyPost($postA);
        $this->modifyPost($postB, ['slug' => 'post-b-modified-slug']);
        $this->modifyPost($postB, ['name' => 'Third', 'slug' => 'post-b-third-slug']);

        // When
        $revisions = Revision::query()->forModel($postA)->get();

        // Then
        $this->assertCount(1, $revisions);
        $this->assertEquals($postA->id, $revisions->first()->revisionable_id);
    }

    #[Test]
    public function it_can_scope_revisions_by_user()
    {
        // Given
        $post = $this->createPost();
        $author = $post->author;

        app(UserResolver::class)->resolveUsing(fn () => $author->id);
        $this->modifyPost($post);

        $otherAuthor = $this->createAuthor();
        app(UserResolver::class)->resolveUsing(fn () => $otherAuthor->id);
        $this->modifyPost($post, ['name' => 'Another name']);

        // When
        $revisions = Revision::query()->forUser($author)->get();

        // Then
        $this->assertCount(1, $revisions);
        $this->assertEquals($author->id, $revisions->first()->user_id);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_model_is_soft_deleted()
    {
        // Given
        $post = new class extends Post
        {
            use SoftDeletes;
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);
        $this->assertEquals(1, Revision::count());

        // When
        $post->delete();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_record_is_first_created()
    {
        // Given
        $post = new Post;

        // When
        $this->createPost($post);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_on_record_creation_when_enabled()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        // When
        $this->createPost($post);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_can_manually_save_a_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->saveAsRevision();

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_stores_the_user_id_using_a_custom_resolver()
    {
        // Given
        app(UserResolver::class)->resolveUsing(fn () => 42);
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertEquals(42, $post->revisions()->firstOrFail()->user_id);
    }

    #[Test]
    public function it_stores_the_original_attribute_values_in_the_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals('Post name', $revision->metadata['name']);
        $this->assertEquals('post-slug', $revision->metadata['slug']);
        $this->assertEquals('Post content', $revision->metadata['content']);
        $this->assertEquals(10, $revision->metadata['votes']);
        $this->assertEquals(100, $revision->metadata['views']);
    }

    #[Test]
    public function it_accumulates_multiple_revisions_across_successive_updates()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);

        // Then
        $this->assertEquals(2, Revision::count());
    }

    #[Test]
    public function it_can_delete_all_revisions_for_a_record()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        $this->assertEquals(2, Revision::count());

        // When
        $post->deleteAllRevisions();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_deletes_all_revisions_when_the_model_is_deleted()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);
        $this->assertEquals(1, Revision::count());

        // When
        $post->delete();

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_clears_excess_revisions_when_manually_pruned()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->limitRevisionsTo(2);
            }
        };

        $post = $this->createPost($post);

        DB::table('revisions')->insert([
            ['revisionable_type' => get_class($post), 'revisionable_id' => $post->id, 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            ['revisionable_type' => get_class($post), 'revisionable_id' => $post->id, 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            ['revisionable_type' => get_class($post), 'revisionable_id' => $post->id, 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertEquals(3, $post->revisions()->count());

        // When
        $post->clearOldRevisions();

        // Then
        $this->assertEquals(2, $post->revisions()->count());
    }

    #[Test]
    public function it_stores_properties_when_manually_saving_a_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $revision = $post->saveAsRevision('Before refactor', ['reason' => 'major rewrite', 'ticket' => 'PROJ-42']);

        // Then
        $this->assertEquals(['reason' => 'major rewrite', 'ticket' => 'PROJ-42'], $revision->properties);
    }

    #[Test]
    public function it_stores_no_properties_when_none_are_provided()
    {
        // Given
        $post = $this->createPost();

        // When
        $revision = $post->saveAsRevision();

        // Then
        $this->assertNull($revision->properties);
    }

    #[Test]
    public function it_can_rollback_to_a_past_revision()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);

        $this->assertEquals('Another post name', $post->name);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals('Post name', $post->name);
        $this->assertEquals('post-slug', $post->slug);
        $this->assertEquals('Post content', $post->content);
        $this->assertEquals(10, $post->votes);
        $this->assertEquals(100, $post->views);
    }

    #[Test]
    public function it_can_rollback_to_the_latest_revision()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);

        $this->assertEquals('Another post name', $post->name);

        // When
        $post->rollback();

        // Then
        $this->assertEquals('Post name', $post->fresh()->name);
    }

    #[Test]
    public function it_returns_false_when_rolling_back_without_any_revisions()
    {
        // Given
        $post = $this->createPost();

        // When
        $result = $post->rollback();

        // Then
        $this->assertFalse($result);
    }

    #[Test]
    public function it_creates_a_revision_after_rolling_back_by_default()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);
        $this->assertEquals(1, Revision::count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(2, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_after_rolling_back_when_disabled()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->disableRevisionOnRollback();
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);
        $this->assertEquals(1, Revision::count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(1, Revision::count());
    }
}
