<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionDiffTest extends TestCase
{
    #[Test]
    public function it_diffs_against_the_previous_revision()
    {
        // Given
        // Revision metadata captures the pre-save state, so:
        // - revision 1 captures the original values (before 1st modify)
        // - revision 2 captures the values after the 1st modify (before 2nd modify)
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $this->assertInstanceOf(Diff::class, $diff);

        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Post name', $changes['name']['old']);
        $this->assertEquals('Another post name', $changes['name']['new']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(10, $changes['votes']['old']);
        $this->assertEquals(20, $changes['votes']['new']);
    }

    #[Test]
    public function it_returns_an_empty_diff_when_there_is_no_previous_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();

        // When
        $diff = $revision->diff();

        // Then
        $this->assertEmpty($diff->changes());
    }

    #[Test]
    public function it_returns_all_fields_including_unchanged_ones()
    {
        // Given
        // Revision metadata captures the pre-save state, so:
        // - revision 1 captures the original values (before 1st modify)
        // - revision 2 captures the values after the 1st modify (before 2nd modify)
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $all = $diff->all();

        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('votes', $all);
        $this->assertArrayHasKey('author_id', $all);

        // author_id did not change between the two revisions
        $this->assertEquals($all['author_id']['old'], $all['author_id']['new']);

        // name is present in all() even though it also appears in changes()
        $this->assertEquals('Post name', $all['name']['old']);
        $this->assertEquals('Another post name', $all['name']['new']);
    }

    // vs another revision

    #[Test]
    public function it_diffs_against_another_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->first()->diff($revisions->last());

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Post name', $changes['name']['old']);
        $this->assertEquals('Another post name', $changes['name']['new']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(10, $changes['votes']['old']);
        $this->assertEquals(20, $changes['votes']['new']);
    }

    // vs current model

    #[Test]
    public function it_diffs_the_current_model_against_the_latest_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        $post->update(['name' => 'Current name', 'votes' => 50]);

        // When
        $diff = $post->fresh()->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Another post name', $changes['name']['old']);
        $this->assertEquals('Current name', $changes['name']['new']);
    }

    #[Test]
    public function it_diffs_the_current_model_against_a_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();

        $post->update(['name' => 'Current name', 'votes' => 50]);

        // When
        $diff = $post->fresh()->diff($revision);

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Post name', $changes['name']['old']);
        $this->assertEquals('Current name', $changes['name']['new']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(10, $changes['votes']['old']);
        $this->assertEquals(50, $changes['votes']['new']);
    }

    #[Test]
    public function it_returns_an_empty_diff_when_the_model_has_no_revisions()
    {
        // Given
        $post = $this->createPost();

        // When
        $diff = $post->diff();

        // Then
        $this->assertEmpty($diff->changes());
        $this->assertEmpty($diff->all());
    }

    // relations

    #[Test]
    public function it_includes_added_and_removed_relations_in_changes()
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
        $tags = $this->createTags(3);

        // Revision 1: tags 1 and 2
        $post->tags()->attach($tags->take(2)->pluck('id')->toArray());

        // Revision 2: tags 2 and 3 (tag 1 removed, tag 3 added)
        $post->tags()->sync($tags->skip(1)->pluck('id')->toArray());

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('tags', $changes);
        $this->assertContains($tags[2]->id, $changes['tags']['added']);
        $this->assertContains($tags[0]->id, $changes['tags']['removed']);
        $this->assertEmpty($changes['tags']['changed']);
    }

    #[Test]
    public function it_includes_relations_with_no_changes_in_all()
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
        $tags = $this->createTags(2);

        // Revision 1: tags 1 and 2
        $post->tags()->attach($tags->pluck('id')->toArray());

        // Revision 2: same tags, but a field changed
        $this->modifyPost($post);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $this->assertArrayNotHasKey('tags', $diff->changes());
        $this->assertArrayHasKey('tags', $diff->all());
    }

    #[Test]
    public function it_includes_changed_pivot_attributes_in_changes()
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
        $tags = $this->createTags(1);

        // Revision 1: tag attached with position 1
        $post->tags()->attach($tags[0]->id, ['position' => 1]);

        // Revision 2: same tag, position changed to 2
        $post->tags()->updateExistingPivot($tags[0]->id, ['position' => 2]);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('tags', $changes);
        $this->assertArrayHasKey($tags[0]->id, $changes['tags']['changed']);
        $this->assertEquals(1, $changes['tags']['changed'][$tags[0]->id]['position']['old']);
        $this->assertEquals(2, $changes['tags']['changed'][$tags[0]->id]['position']['new']);
    }

    #[Test]
    public function it_diffs_the_current_model_state_including_relations()
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
        $tags = $this->createTags(3);

        // Revision: tags 1 and 2
        $post->tags()->attach($tags->take(2)->pluck('id')->toArray());

        $revision = $post->revisions()->latest('id')->firstOrFail();

        // Change current state without creating a new revision
        DB::table('post_tag')->where('post_id', $post->id)->delete();
        DB::table('post_tag')->insert([
            ['post_id' => $post->id, 'tag_id' => $tags[1]->id],
            ['post_id' => $post->id, 'tag_id' => $tags[2]->id],
        ]);

        // When
        $diff = $post->diff($revision);

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('tags', $changes);
        $this->assertContains($tags[2]->id, $changes['tags']['added']);
        $this->assertContains($tags[0]->id, $changes['tags']['removed']);
    }

    #[Test]
    public function it_diffs_direct_relations()
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

        $post->comments()->create(['title' => 'First comment', 'content' => 'Content', 'date' => now(), 'active' => true]);
        $this->modifyPost($post);

        $post->comments()->create(['title' => 'Second comment', 'content' => 'Content', 'date' => now(), 'active' => true]);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('comments', $changes);
        $this->assertCount(1, $changes['comments']['added']);
        $this->assertEmpty($changes['comments']['removed']);
    }

    #[Test]
    public function it_diffs_direct_relations_with_no_records_in_either_revision()
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

        // Both revisions are created with no comments, leaving primary_key null in the metadata
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diff();

        // Then
        $this->assertArrayNotHasKey('comments', $diff->changes());
        $this->assertArrayHasKey('comments', $diff->all());
    }
}
