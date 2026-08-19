<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\PendingRevision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class WithSingleRevisionTest extends TestCase
{
    #[Test]
    public function it_creates_a_single_revision_when_creating_a_model_and_a_child_relation()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->withRelations('attachments');
            }
        };

        $author = $this->createAuthor();

        // When
        $post = $postClass::createWithSingleRevision(function () use ($postClass, $author) {
            $post = $postClass::create([
                'author_id' => $author->id,
                'name' => 'Post name',
                'slug' => 'post-slug',
                'content' => 'Post content',
                'votes' => 10,
                'views' => 100,
            ]);

            $post->attachments()->create(['name' => 'document.pdf']);

            return $post;
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertTrue($revision->isInitial());
        $this->assertArrayHasKey('attachments', $revision->metadata['relations'] ?? []);
        $this->assertCount(1, $revision->metadata['relations']['attachments']['records']['items']);
    }

    #[Test]
    public function it_creates_a_single_revision_when_creating_a_model_and_a_pivot_relation()
    {
        // Given
        $postClass = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->withRelations('tags');
            }
        };

        $author = $this->createAuthor();
        $tags = $this->createTags();

        // When
        $post = $postClass::createWithSingleRevision(function () use ($postClass, $author, $tags) {
            $post = $postClass::create([
                'author_id' => $author->id,
                'name' => 'Post name',
                'slug' => 'post-slug',
                'content' => 'Post content',
                'votes' => 10,
                'views' => 100,
            ]);

            $post->tags()->attach($tags->pluck('id')->toArray());

            return $post;
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertTrue($revision->isInitial());
        $this->assertArrayHasKey('tags', $revision->metadata['relations'] ?? []);
        $this->assertCount(3, $revision->metadata['relations']['tags']['records']['items']);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_callback_throws()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->withRelations('attachments');
            }
        };

        $author = $this->createAuthor();

        // When
        try {
            $postClass::createWithSingleRevision(function () use ($postClass, $author) {
                $postClass::create([
                    'author_id' => $author->id,
                    'name' => 'Post name',
                    'slug' => 'post-slug',
                    'content' => 'Post content',
                    'votes' => 10,
                    'views' => 100,
                ]);

                throw new \RuntimeException('Something went wrong');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_throws_when_the_callback_does_not_return_the_model()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $author = $this->createAuthor();

        // When
        $this->expectException(\InvalidArgumentException::class);

        $postClass::createWithSingleRevision(function () use ($postClass, $author) {
            $postClass::create([
                'author_id' => $author->id,
                'name' => 'Post name',
                'slug' => 'post-slug',
                'content' => 'Post content',
                'votes' => 10,
                'views' => 100,
            ]);

            // Forgot to return the model
        });
    }

    #[Test]
    public function it_resumes_normal_revisioning_after_the_callback_completes()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->withRelations('attachments');
            }
        };

        $author = $this->createAuthor();

        $postClass::createWithSingleRevision(function () use ($postClass, $author) {
            $post = $postClass::create([
                'author_id' => $author->id,
                'name' => 'Post name',
                'slug' => 'post-slug',
                'content' => 'Post content',
                'votes' => 10,
                'views' => 100,
            ]);

            $post->attachments()->create(['name' => 'document.pdf']);

            return $post;
        });

        // When
        $secondPost = $postClass::create([
            'author_id' => $author->id,
            'name' => 'Second post name',
            'slug' => 'second-post-slug',
            'content' => 'Second post content',
            'votes' => 5,
            'views' => 50,
        ]);

        $secondPost->attachments()->create(['name' => 'second.pdf']);

        // Then
        $this->assertEquals(3, Revision::count());
    }

    #[Test]
    public function it_creates_a_single_revision_when_updating_a_model_and_a_child_relation()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('attachments');
            }
        };

        $post = $this->createPost($postClass);

        // When
        $post->withSingleRevision(function ($post) {
            $post->update(['votes' => 42]);

            $post->attachments()->create(['name' => 'document.pdf']);
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertTrue($revision->isDefault());
        $this->assertContains('votes', $revision->changed);
        $this->assertArrayHasKey('attachments', $revision->metadata['relations'] ?? []);
    }

    #[Test]
    public function it_creates_a_single_revision_when_only_a_relation_changes_and_no_tracked_field_is_dirty()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->onlyFields('votes')
                    ->withRelations('attachments');
            }
        };

        $post = $this->createPost($postClass);

        // When
        $post->withSingleRevision(function () use ($post) {
            $post->attachments()->create(['name' => 'document.pdf']);
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertArrayHasKey('attachments', $revision->metadata['relations'] ?? []);
        $this->assertCount(1, $revision->metadata['relations']['attachments']['records']['items']);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_only_an_untracked_relation_changes_during_a_batch()
    {
        // Given
        $postClass = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->onlyFields('votes');
            }
        };

        $post = $this->createPost($postClass);
        $tags = $this->createTags();

        // When
        $post->withSingleRevision(function () use ($post, $tags) {
            $post->tags()->attach($tags->pluck('id')->toArray());
        });

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_queues_a_pending_revision_when_saving_a_revision_during_a_batch()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions();
            }
        };

        $post = $this->createPost($postClass);

        // When
        $post->withSingleRevision(function () use ($post) {
            $pending = $post->saveAsRevision('manual snapshot');

            $this->assertInstanceOf(PendingRevision::class, $pending);
            $this->assertEquals(0, Revision::count());
        });

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_captures_the_full_diff_across_multiple_updates_inside_the_callback()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions();
            }
        };

        $post = $this->createPost($postClass);

        // When
        $post->withSingleRevision(function () use ($post) {
            $post->update(['name' => 'First update name']);
            $post->update(['votes' => 999]);
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertContains('name', $revision->changed);
        $this->assertContains('votes', $revision->changed);
    }

    #[Test]
    public function it_replaces_the_merged_revision_on_a_later_update_when_configured_to_replace()
    {
        // Given
        $postClass = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('attachments')
                    ->replaceWhen(true);
            }
        };

        $post = $this->createPost($postClass);

        $post->withSingleRevision(function () use ($post) {
            $post->update(['votes' => 42]);

            $post->attachments()->create(['name' => 'document.pdf']);
        });

        $first = $post->revisions()->firstOrFail();

        // When
        $post->withSingleRevision(function () use ($post) {
            $post->update(['votes' => 99]);

            $post->attachments()->create(['name' => 'second.pdf']);
        });

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertEquals($first->id, $revision->id);
        $this->assertContains('votes', $revision->changed);
        $this->assertCount(2, $revision->metadata['relations']['attachments']['records']['items']);
    }
}
