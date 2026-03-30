<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class ReplacingRevisionsTest extends TestCase
{
    #[Test]
    public function it_replaces_the_latest_revision_when_the_condition_is_true()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Third name']);

        // When / Then
        $this->assertCount(1, $post->revisions);

        // The living snapshot captures the pre-save state, so after two edits it holds
        // the state before the most recent save ('Another post name' before 'Third name').
        $this->assertEquals('Another post name', $post->revisions->first()->metadata['name']);
    }

    #[Test]
    public function it_creates_a_new_revision_when_none_exists_yet()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(true);
            }
        };

        // When
        $post = $this->createPost($post);
        $this->modifyPost($post);

        // Then
        $this->assertCount(1, $post->revisions);
    }

    #[Test]
    public function it_creates_a_new_revision_when_the_condition_is_false()
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
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Third name']);

        // When / Then
        $this->assertCount(2, $post->revisions);
    }

    #[Test]
    public function it_evaluates_a_callable_condition_against_the_model()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->replaceWhen(fn (self $model) => $model->name !== 'Final name');
            }
        };

        $post = $this->createPost($post);

        // Still "draft" — both edits replace the same revision
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Draft revision two']);

        // Transitions out of draft — creates a new revision
        $this->modifyPost($post, ['name' => 'Final name']);

        // When / Then
        $revisions = $post->revisions()->oldest('id')->get();

        $this->assertCount(2, $revisions);

        // Each revision captures the state before the save that created/replaced it.
        // Revision 1 was replaced twice while in draft, ending up with the pre-'Draft revision two' state.
        // Revision 2 captured the pre-'Final name' state when the condition turned false.
        $this->assertEquals('Another post name', $revisions->first()->metadata['name']);
        $this->assertEquals('Draft revision two', $revisions->last()->metadata['name']);
    }

    #[Test]
    public function it_preserves_the_revision_identity_when_replacing()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);

        $originalId = $post->revisions()->value('id');

        // When
        $this->modifyPost($post, ['name' => 'Updated name']);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals($originalId, $revision->id);

        // The snapshot captures the state before 'Updated name' was saved.
        $this->assertEquals('Another post name', $revision->metadata['name']);
    }

    #[Test]
    public function it_replaces_the_revision_on_pivot_changes()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags(3);

        // First pivot change creates the revision
        $post->tags()->attach($tags[0]->id);

        // Second pivot change should replace it
        $post->tags()->sync($tags->pluck('id')->toArray());

        // When / Then
        $revisions = $post->revisions;

        $this->assertCount(1, $revisions);

        $tagIds = array_column(
            $revisions->first()->metadata['relations']['tags']['records']['items'],
            'id'
        );

        $this->assertCount(3, $tagIds);
    }

    #[Test]
    public function it_fires_events_when_replacing()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);

        $revisioningFired = false;
        $revisionedFired = false;

        $post::revisioning(function () use (&$revisioningFired) {
            $revisioningFired = true;
        });

        $post::revisioned(function () use (&$revisionedFired) {
            $revisionedFired = true;
        });

        // When
        $this->modifyPost($post);

        // Then
        $this->assertTrue($revisioningFired);
        $this->assertTrue($revisionedFired);
    }
}
