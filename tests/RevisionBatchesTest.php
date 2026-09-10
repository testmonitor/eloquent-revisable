<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

final class RevisionBatchesTest extends TestCase
{
    #[Test]
    public function it_creates_a_new_revision_when_none_exists_yet()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->saveAsBatchRevision('import-1');

        // Then
        $this->assertCount(1, $post->revisions);
        $this->assertTrue($post->revisions->first()->belongsToBatch('import-1'));
    }

    #[Test]
    public function it_replaces_the_latest_revision_when_it_belongs_to_the_same_batch()
    {
        // Given
        $post = $this->createPost();
        $post->saveAsBatchRevision('import-1');

        $originalId = $post->revisions()->value('id');

        // When
        $post->updateQuietly(['name' => 'Second row for the same test case']);
        $post->saveAsBatchRevision('import-1');

        // Then
        $revisions = $post->revisions()->get();

        $this->assertCount(1, $revisions);
        $this->assertEquals($originalId, $revisions->first()->id);
        $this->assertEquals('Second row for the same test case', $revisions->first()->metadata['attributes']['name']);
    }

    #[Test]
    public function it_creates_a_new_revision_when_the_latest_belongs_to_a_different_batch()
    {
        // Given
        $post = $this->createPost();
        $post->saveAsBatchRevision('import-1');

        // When
        $post->updateQuietly(['name' => 'Row from a later import']);
        $post->saveAsBatchRevision('import-2');

        // Then
        $revisions = $post->revisions()->oldest('id')->get();

        $this->assertCount(2, $revisions);
        $this->assertTrue($revisions->first()->belongsToBatch('import-1'));
        $this->assertTrue($revisions->last()->belongsToBatch('import-2'));
    }

    #[Test]
    public function it_creates_a_new_revision_when_the_latest_has_no_batch_at_all()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post); // a regular, interactively-edited revision, untagged

        // When
        $post->saveAsBatchRevision('import-1');

        // Then
        $this->assertCount(2, $post->revisions()->get());
    }

    #[Test]
    public function it_accumulates_the_changed_fields_across_replacements_within_a_batch()
    {
        // Given
        $post = $this->createPost();
        $post->saveAsRevision(); // the test case's own revision, from before this batch started

        $post->updateQuietly(['name' => 'First edit']);
        $post->saveAsBatchRevision('import-1');

        // When
        $post->updateQuietly(['votes' => 99]);
        $post->saveAsBatchRevision('import-1');

        // Then
        $revision = $post->revisions()->latest('id')->firstOrFail();

        $this->assertContains('name', $revision->changed);
        $this->assertContains('votes', $revision->changed);
    }

    #[Test]
    public function batch_membership_is_independent_of_the_replace_when_configuration()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(false);
            }
        };

        $post = $this->createPost($post);
        $post->saveAsBatchRevision('import-1');

        // When
        $post->updateQuietly(['name' => 'Still the same batch']);
        $post->saveAsBatchRevision('import-1');

        // Then
        $this->assertCount(1, $post->revisions()->get());
    }

    #[Test]
    public function it_creates_a_single_batch_tagged_revision_when_creating_a_model()
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
        $post = $postClass::createWithBatchRevision('import-1', fn () => $postClass::create([
            'author_id' => $author->id,
            'name' => 'Post name',
            'slug' => 'post-slug',
            'content' => 'Post content',
            'votes' => 10,
            'views' => 100,
        ]));

        // Then
        $this->assertEquals(1, Revision::count());

        $revision = $post->revisions()->firstOrFail();
        $this->assertTrue($revision->isInitial());
        $this->assertTrue($revision->belongsToBatch('import-1'));
    }

    #[Test]
    public function it_replaces_the_created_revision_when_a_later_row_in_the_same_batch_merges_into_it()
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

        $post = $postClass::createWithBatchRevision('import-1', fn () => $postClass::create([
            'author_id' => $author->id,
            'name' => 'Post name',
            'slug' => 'post-slug',
            'content' => 'Post content',
            'votes' => 10,
            'views' => 100,
        ]));

        $createdRevisionId = $post->revisions()->value('id');

        // When
        $post->withBatchRevision('import-1', function ($post) {
            $post->update(['content' => 'Content merged in from a later row']);
        });

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals(1, Revision::count());
        $this->assertEquals($createdRevisionId, $revision->id);
        $this->assertTrue($revision->isInitial());
        $this->assertEquals('Content merged in from a later row', $revision->metadata['attributes']['content']);
    }

    #[Test]
    public function it_returns_the_callbacks_result()
    {
        // Given
        $post = $this->createPost();

        // When
        $result = $post->withBatchRevision('import-1', function ($post) {
            $post->update(['name' => 'Merged row']);

            return 'callback result';
        });

        // Then
        $this->assertEquals('callback result', $result);
    }

    #[Test]
    public function it_suspends_automatic_revisioning_so_a_regular_update_inside_the_callback_is_not_double_counted()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->withBatchRevision('import-1', function ($post) {
            $post->update(['name' => 'Merged row']);
        });

        // Then
        $this->assertEquals(1, Revision::count());
        $this->assertTrue($post->revisions()->firstOrFail()->belongsToBatch('import-1'));
    }

    #[Test]
    public function it_does_not_persist_a_revision_when_the_callback_changes_nothing_tracked()
    {
        // Given
        $post = $this->createPost();
        $post->saveAsRevision(); // the test case's own revision, from before this batch started

        // When
        $post->withBatchRevision('import-1', function ($post) {
            // Deliberately empty: nothing tracked changes during this call.
        });

        // Then
        $this->assertCount(1, $post->revisions()->get());
    }

    #[Test]
    public function it_merges_several_separate_calls_into_one_revision_via_the_callback()
    {
        // Given
        $post = $this->createPost();

        $post->withBatchRevision('import-1', function ($post) {
            $post->update(['content' => 'First row']);
        });

        $firstRevisionId = $post->revisions()->value('id');

        // When
        $post->withBatchRevision('import-1', function ($post) {
            $post->update(['content' => $post->content . ' + second row']);
        });

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals(1, Revision::count());
        $this->assertEquals($firstRevisionId, $revision->id);
        $this->assertContains('content', $revision->changed);
        $this->assertEquals('First row + second row', $revision->metadata['attributes']['content']);
    }
}
