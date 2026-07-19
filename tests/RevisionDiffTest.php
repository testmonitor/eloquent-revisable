<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionDiffTest extends TestCase
{
    // vs nothing (default)

    #[Test]
    public function it_diffs_a_revision_against_nothing_by_default()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post, ['name' => 'Another post name', 'votes' => 20]);

        $revision = $post->revisions()->firstOrFail();

        // When
        $diff = $revision->diff();

        // Then
        $this->assertInstanceOf(Diff::class, $diff);

        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertNull($changes['name']['before']);
        $this->assertEquals('Another post name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertNull($changes['votes']['before']);
        $this->assertEquals(20, $changes['votes']['after']);
    }

    // vs previous revision

    #[Test]
    public function it_diffs_against_the_previous_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name', 'votes' => 30]);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Another post name', $changes['name']['before']);
        $this->assertEquals('Final name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(20, $changes['votes']['before']);
        $this->assertEquals(30, $changes['votes']['after']);
    }

    #[Test]
    public function it_returns_the_names_of_changed_fields_and_relations()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name', 'votes' => 30]);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $changed = $revisions->last()->diffFromPrevious()->changed();

        // Then
        $this->assertContains('name', $changed);
        $this->assertContains('votes', $changed);
        $this->assertNotContains('author_id', $changed);
    }

    #[Test]
    public function it_diffs_against_nothing_when_there_is_no_previous_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();

        // When
        $diff = $revision->diffFromPrevious();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertNull($changes['name']['before']);
        $this->assertEquals('Another post name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertNull($changes['votes']['before']);
        $this->assertEquals(20, $changes['votes']['after']);
    }

    #[Test]
    public function it_returns_all_fields_including_unchanged_ones()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $all = $diff->all();

        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('votes', $all);
        $this->assertArrayHasKey('author_id', $all);

        // author_id did not change between the two revisions
        $this->assertEquals($all['author_id']['before'], $all['author_id']['after']);

        // name is present in all() even though it also appears in changes()
        $this->assertEquals('Another post name', $all['name']['before']);
        $this->assertEquals('Final name', $all['name']['after']);
    }

    #[Test]
    public function it_returns_specific_field_regardless_of_changes()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $name = $diff->get('name');
        $authorId = $diff->get('author_id');

        $this->assertEquals('Another post name', $name['before']);

        // author_id did not change between the two revisions
        $this->assertEquals($authorId['before'], $authorId['after']);
    }

    #[Test]
    public function it_returns_null_when_there_is_an_unknown_revision_field_name()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();
        $result = $diff->get('bogus_field');

        // Then
        $this->assertNull($result);
    }

    // vs another revision

    #[Test]
    public function it_diffs_against_another_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name', 'votes' => 30]);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->first()->diff($revisions->last());

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Another post name', $changes['name']['before']);
        $this->assertEquals('Final name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(20, $changes['votes']['before']);
        $this->assertEquals(30, $changes['votes']['after']);
    }

    // vs current model

    #[Test]
    public function it_diffs_the_current_model_against_the_latest_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post); // Revision 1 (latest): 'Another post name', votes=20

        // Update the DB without creating a new revision to simulate live drift
        $post->withoutRevisioning(fn () => $post->update(['name' => 'Current name', 'votes' => 50]));

        // When
        $diff = $post->fresh()->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Another post name', $changes['name']['before']);
        $this->assertEquals('Current name', $changes['name']['after']);
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
        $this->assertEquals('Another post name', $changes['name']['before']);
        $this->assertEquals('Current name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertEquals(20, $changes['votes']['before']);
        $this->assertEquals(50, $changes['votes']['after']);
    }

    #[Test]
    public function it_diffs_against_nothing_when_the_model_has_no_revisions()
    {
        // Given
        $post = $this->createPost();

        // When
        $diff = $post->diff();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('name', $changes);
        $this->assertNull($changes['name']['before']);
        $this->assertEquals('Post name', $changes['name']['after']);

        $this->assertArrayHasKey('votes', $changes);
        $this->assertNull($changes['votes']['before']);
        $this->assertEquals(10, $changes['votes']['after']);
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
        $diff = $revisions->last()->diffFromPrevious();

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
        $diff = $revisions->last()->diffFromPrevious();

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
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $changes = $diff->changes();

        $this->assertArrayHasKey('tags', $changes);
        $this->assertArrayHasKey($tags[0]->id, $changes['tags']['changed']);
        $this->assertEquals(1, $changes['tags']['changed'][$tags[0]->id]['position']['before']);
        $this->assertEquals(2, $changes['tags']['changed'][$tags[0]->id]['position']['after']);
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
        $diff = $revisions->last()->diffFromPrevious();

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

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $this->assertArrayNotHasKey('comments', $diff->changes());
        $this->assertArrayHasKey('comments', $diff->all());
    }

    #[Test]
    public function it_returns_an_empty_diff_for_a_direct_relation_when_primary_key_is_missing_in_both_revisions()
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
        $this->modifyPost($post, ['name' => 'Final name']);

        $revisions = $post->revisions()->oldest('id')->get();

        // Simulate corrupted/legacy metadata where primary_key is absent
        $revisions->each(function ($revision) {
            $metadata = $revision->metadata;
            if (isset($metadata['relations']['comments']['records'])) {
                $metadata['relations']['comments']['records']['primary_key'] = null;
                $revision->update(['metadata' => $metadata]);
            }
        });

        $revisions = $post->revisions()->oldest('id')->get();

        // When
        $diff = $revisions->last()->diffFromPrevious();

        // Then
        $this->assertArrayNotHasKey('comments', $diff->changes());
        $this->assertArrayHasKey('comments', $diff->all());

        $this->assertEmpty($diff->all()['comments']['added']);
        $this->assertEmpty($diff->all()['comments']['removed']);
        $this->assertEmpty($diff->all()['comments']['changed']);
    }

    // json comparison

    #[Test]
    public function it_reports_a_json_encoded_field_as_changed_when_the_content_differs()
    {
        // Given
        $diff = new Diff(
            new Revision(['metadata' => ['attributes' => ['instructions' => '["a","b","c"]']]]),
            new Revision(['metadata' => ['attributes' => ['instructions' => '["a","b","d"]']]]),
        );

        // Then
        $this->assertArrayHasKey('instructions', $diff->changes());
    }

    #[Test]
    public function it_does_not_report_a_json_encoded_field_as_changed_when_only_whitespace_differs()
    {
        // Given
        $diff = new Diff(
            new Revision(['metadata' => ['attributes' => ['instructions' => '["a","b","c"]']]]),
            new Revision(['metadata' => ['attributes' => ['instructions' => '["a", "b", "c"]']]]),
        );

        // Then
        $this->assertArrayNotHasKey('instructions', $diff->changes());
    }

    // source revision access

    #[Test]
    public function it_returns_the_source_revisions_a_diff_was_built_from()
    {
        // Given
        $before = new Revision(['metadata' => ['attributes' => ['name' => 'Old name']]]);
        $after = new Revision(['metadata' => ['attributes' => ['name' => 'New name']]]);

        $diff = new Diff($before, $after);

        // Then
        $this->assertSame($before, $diff->before());
        $this->assertSame($after, $diff->after());
    }

    #[Test]
    public function it_returns_a_null_before_revision_when_diffing_a_single_revision_against_nothing()
    {
        // Given
        $revision = new Revision(['metadata' => ['attributes' => ['name' => 'New name']]]);

        $diff = Diff::fromNothing($revision);

        // Then
        $this->assertNull($diff->before());
        $this->assertSame($revision, $diff->after());
    }

    // raw metadata access

    #[Test]
    public function it_returns_the_raw_before_and_after_metadata()
    {
        // Given
        $before = ['attributes' => ['name' => 'Old name'], 'relations' => ['tags' => ['pivots' => []]]];
        $after = ['attributes' => ['name' => 'New name'], 'relations' => ['tags' => ['pivots' => []]]];

        $diff = new Diff(new Revision(['metadata' => $before]), new Revision(['metadata' => $after]));

        // Then
        $this->assertEquals($before, $diff->beforeMetadata());
        $this->assertEquals($after, $diff->afterMetadata());
    }

    #[Test]
    public function it_returns_a_subkey_of_the_raw_metadata_using_dot_notation()
    {
        // Given
        $diff = new Diff(
            new Revision(['metadata' => ['attributes' => ['name' => 'Old name']]]),
            new Revision(['metadata' => ['attributes' => ['name' => 'New name']]]),
        );

        // Then
        $this->assertEquals(['name' => 'Old name'], $diff->beforeMetadata('attributes'));
        $this->assertEquals('Old name', $diff->beforeMetadata('attributes.name'));

        $this->assertEquals(['name' => 'New name'], $diff->afterMetadata('attributes'));
        $this->assertEquals('New name', $diff->afterMetadata('attributes.name'));
    }

    #[Test]
    public function it_returns_null_for_a_missing_subkey_of_the_raw_metadata()
    {
        // Given
        $diff = new Diff(
            new Revision(['metadata' => ['attributes' => ['name' => 'Old name']]]),
            new Revision(['metadata' => ['attributes' => ['name' => 'New name']]]),
        );

        // Then
        $this->assertNull($diff->beforeMetadata('bogus.key'));
        $this->assertNull($diff->afterMetadata('bogus.key'));
    }

    // revision metadata accessor

    #[Test]
    public function it_returns_the_captured_attributes_and_relations_as_metadata()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post, ['name' => 'Another post name', 'votes' => 20]);

        $revision = $post->revisions()->firstOrFail();

        // When
        $metadata = $revision->metadata();

        // Then
        $this->assertEquals('Another post name', $metadata['attributes']['name']);
        $this->assertEquals(20, $metadata['attributes']['votes']);
    }

    #[Test]
    public function it_returns_an_empty_array_of_metadata_for_an_unsaved_revision()
    {
        // Given
        $revision = new Revision([
            'revisionable_type' => Post::class,
            'revisionable_id' => 1,
        ]);

        // When / Then
        $this->assertEquals([], $revision->metadata());
    }
}
