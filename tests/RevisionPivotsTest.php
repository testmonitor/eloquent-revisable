<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\Tests\Models\Tag;

class RevisionPivotsTest extends TestCase
{
    #[Test]
    public function it_creates_a_revision_when_attaching_to_a_tracked_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->tags()->attach($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_detaching_from_a_tracked_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        $post->withoutRevisioning(fn () => $post->tags()->attach($tags->pluck('id')->toArray()));

        // When
        $post->tags()->detach($tags->first()->id);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_syncing_a_tracked_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->tags()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_only_one_revision_when_syncing_multiple_tags_at_once()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags(5);

        // When
        $post->tags()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_sync_results_in_no_changes()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        $post->withoutRevisioning(fn () => $post->tags()->sync($tags->pluck('id')->toArray()));

        // When
        $post->tags()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_syncing_without_detaching_a_tracked_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->tags()->syncWithoutDetaching($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_sync_without_detaching_results_in_no_changes()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        $post->withoutRevisioning(fn () => $post->tags()->syncWithoutDetaching($tags->pluck('id')->toArray()));

        // When
        $post->tags()->syncWithoutDetaching($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_toggling_a_tracked_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->tags()->toggle($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_updating_an_existing_pivot()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        $post->withoutRevisioning(fn () => $post->tags()->attach($tags->first()->id));

        // When
        $post->tags()->updateExistingPivot($tags->first()->id, ['position' => 1]);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_creates_a_revision_when_syncing_a_tracked_morph_to_many_relation()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function labels(): MorphToMany
            {
                return $this->morphToMany(Tag::class, 'taggable');
            }

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('labels');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->labels()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_relation_is_not_tracked()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions();
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->tags()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_revisioning_is_disabled()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        // When
        $post->withoutRevisioning(fn () => $post->tags()->sync($tags->pluck('id')->toArray()));

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_the_revisioning_event_returns_false()
    {
        // Given
        $post = new class extends Post
        {
            use HasRevisionablePivots;

            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->withRelations('tags');
            }
        };

        $post = $this->createPost($post);
        $tags = $this->createTags();

        $post::revisioning(fn () => false);

        // When
        $post->tags()->sync($tags->pluck('id')->toArray());

        // Then
        $this->assertEquals(0, Revision::count());
    }
}
