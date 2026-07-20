<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\Enums\RevisionType;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\UserResolver;

class ReplacingRevisionsTest extends TestCase
{
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

        // The snapshot holds the post-save state, so after the replacement it holds
        // the most recent save ('Third name').
        $this->assertEquals('Third name', $post->revisions->first()->metadata['attributes']['name']);
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

        // Each revision captures the post-save state.
        // Revision 1 was replaced while in draft, ending up with 'Draft revision two'.
        // Revision 2 captured 'Final name' when the condition turned false.
        $this->assertEquals('Draft revision two', $revisions->first()->metadata['attributes']['name']);
        $this->assertEquals('Final name', $revisions->last()->metadata['attributes']['name']);
    }

    #[Test]
    public function it_passes_the_latest_revision_to_the_callable_condition()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->replaceWhen(fn (self $model, $latest) => $latest->metadata()['attributes']['name'] !== 'Stop replacing');
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post); // creates the first revision
        $this->modifyPost($post, ['name' => 'Second draft']); // replaces (latest name != 'Stop replacing')

        // When
        $this->modifyPost($post, ['name' => 'Stop replacing']); // replaces (latest name == 'Second draft', != 'Stop replacing')
        $this->modifyPost($post, ['name' => 'After freeze']); // should NOT replace (latest name == 'Stop replacing')

        // Then
        $revisions = $post->revisions()->oldest('id')->get();

        $this->assertCount(2, $revisions);
        $this->assertEquals('Stop replacing', $revisions->first()->metadata['attributes']['name']);
        $this->assertEquals('After freeze', $revisions->last()->metadata['attributes']['name']);
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

        // The snapshot holds the post-save state.
        $this->assertEquals('Updated name', $revision->metadata['attributes']['name']);
    }

    #[Test]
    public function it_updates_changes_when_a_revision_is_replaced()
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

        // When
        $this->modifyPost($post, ['name' => 'Third name']);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertContains('name', $revision->changed);
        $this->assertNotContains('votes', $revision->changed);
    }

    #[Test]
    public function it_accumulates_changes_across_all_saves_when_replacing()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);

        $post->update(['name' => 'First edit']);

        // When
        $post->update(['votes' => 99]);

        // Then
        $revision = $post->revisions()->where('type', RevisionType::Default)->firstOrFail();

        $this->assertContains('name', $revision->changed);
        $this->assertContains('votes', $revision->changed);
    }

    #[Test]
    public function it_does_not_replace_an_initial_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->enableRevisionOnCreate()
                    ->replaceWhen(true);
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $this->assertCount(2, $post->revisions()->get());

        $types = $post->revisions()->oldest('id')->pluck('type')->all();
        $this->assertEquals([RevisionType::Initial, RevisionType::Default], $types);

        $revisions = $post->revisions()->oldest('id')->get();
        $this->assertTrue($revisions->first()->isInitial());
        $this->assertTrue($revisions->last()->isDefault());
    }

    #[Test]
    public function it_always_creates_a_new_revision_on_rollback_when_replace_when_is_true()
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

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertCount(2, $post->revisions()->get());
    }

    #[Test]
    public function it_does_not_replace_a_rollback_revision_on_subsequent_edits()
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
        $this->modifyPost($post); // Revision 1: 'Another post name'
        $post->rollbackToRevision($post->revisions()->firstOrFail()); // Rollback revision created

        // When
        $this->modifyPost($post, ['name' => 'After rollback edit']);

        // Then
        $this->assertCount(3, $post->revisions()->get());

        $types = $post->revisions()->oldest('id')->pluck('type')->all();
        $this->assertEquals([RevisionType::Default, RevisionType::Rollback, RevisionType::Default], $types);
    }

    #[Test]
    public function it_creates_a_new_revision_when_replace_is_false_regardless_of_configuration()
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

        // When
        $post->saveAsRevision(replace: false);

        // Then
        $this->assertCount(2, $post->revisions()->get());
    }

    #[Test]
    public function it_replaces_the_latest_revision_when_replace_is_true_regardless_of_configuration()
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

        // When
        $post->saveAsRevision(replace: true);

        // Then
        $this->assertCount(1, $post->revisions()->get());
    }

    #[Test]
    public function it_follows_the_configured_replace_when_no_replace_parameter_is_given()
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

        // When
        $post->saveAsRevision();

        // Then
        $this->assertCount(1, $post->revisions()->get());
    }

    #[Test]
    public function it_creates_a_new_revision_when_a_different_user_edits()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->replaceWhen(true);
            }
        };

        $firstAuthor = $this->createAuthor();
        $secondAuthor = $this->createAuthor();

        app(UserResolver::class)->resolveUsing(fn () => $firstAuthor->id);

        $post = $this->createPost($post);
        $this->modifyPost($post);

        // When
        app(UserResolver::class)->resolveUsing(fn () => $secondAuthor->id);
        $this->modifyPost($post, ['name' => 'Edited by second author']);

        // Then
        $revisions = $post->revisions()->oldest('id')->get();
        $this->assertCount(2, $revisions);
        $this->assertEquals($firstAuthor->id, $revisions->first()->user_id);
        $this->assertEquals($secondAuthor->id, $revisions->last()->user_id);
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
    public function it_replaces_the_revision_when_within_the_time_window()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->replaceWhen(true)
                    ->replaceWithin(new \DateInterval('PT1H'));
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);

        // When
        $this->modifyPost($post, ['name' => 'Still within window']);

        // Then
        $this->assertCount(1, $post->revisions()->get());
    }

    #[Test]
    public function it_creates_a_new_revision_when_outside_the_time_window()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->replaceWhen(true)
                    ->replaceWithin(new \DateInterval('PT1H'));
            }
        };

        $post = $this->createPost($post);
        $this->modifyPost($post);

        // Backdate the revision's updated_at beyond the window
        $post->revisions()->update(['updated_at' => now()->subHours(2)]);

        // When
        $this->modifyPost($post, ['name' => 'Outside window']);

        // Then
        $this->assertCount(2, $post->revisions()->get());
    }
}
