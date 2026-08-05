<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Diff;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Comment;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\Tests\Models\Tag;

class RevisionFiltersTest extends TestCase
{
    #[Test]
    public function it_restores_a_relation_record_that_passes_the_configured_filter_when_rolling_back()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->filterRelation('tags', fn (array $item) => Tag::whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        $post->tags()->detach($post->tags()->firstOrFail()->id);
        $this->assertEquals(2, $post->tags()->count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(3, $post->tags()->count());
    }

    #[Test]
    public function it_does_not_recreate_a_direct_relation_record_that_fails_the_configured_filter_when_rolling_back()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('comments')
                    ->filterRelation('comments', fn (array $item) => Comment::withTrashed()->whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        $commentId = $post->comments()->firstOrFail()->id;
        DB::table('comments')->where('id', $commentId)->delete();
        $this->assertEquals(2, $post->comments()->count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(2, $post->comments()->count());
    }

    #[Test]
    public function it_does_not_recreate_or_reattach_a_pivoted_relation_record_that_fails_the_configured_filter_when_rolling_back()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->filterRelation('tags', fn (array $item) => Tag::whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        $tagId = $post->tags()->firstOrFail()->id;
        $revision = $post->revisions()->firstOrFail();
        DB::table('tags')->where('id', $tagId)->delete();
        $this->assertEquals(2, $post->tags()->count());

        // When
        $post->rollbackToRevision($revision);

        // Then
        $this->assertEquals(2, $post->tags()->count());
        $this->assertFalse(Tag::whereKey($tagId)->exists());

        // The filter only affects what gets restored — the revision's own stored metadata,
        // used for auditing, still lists the tag exactly as it was originally captured.
        $this->assertContains(
            $tagId,
            array_column($revision->fresh()->metadata['relations']['tags']['records']['items'], 'id')
        );
    }

    #[Test]
    public function it_reports_the_true_historical_diff_by_default_even_when_a_relation_now_fails_the_configured_filter()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->filterRelation('tags', fn (array $item) => Tag::whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        $revision = $post->revisions()->firstOrFail();
        $tagId = $post->tags()->firstOrFail()->id;

        DB::table('tags')->where('id', $tagId)->delete();

        // When
        $diff = $post->diff($revision->fresh());

        // Then
        $this->assertContains($tagId, $diff->get('tags')['removed']);
    }

    #[Test]
    public function it_reports_a_relation_change_that_passes_the_configured_filter_in_the_persisted_changed_field()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->filterRelation('tags', fn (array $item) => Tag::whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        // A genuine removal — the tag is only detached, not deleted, so it still exists.
        $post->tags()->detach($post->tags()->firstOrFail()->id);

        // When
        $post->update(['votes' => 99]);

        // Then
        $revision = $post->revisions()->latest('id')->firstOrFail();

        $this->assertContains('tags', $revision->changed);
    }

    #[Test]
    public function it_excludes_a_relation_record_that_fails_the_configured_filter_from_the_persisted_changed_field()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()
                    ->withRelations('tags')
                    ->filterRelation('tags', fn (array $item) => Tag::whereKey($item['id'])->exists());
            }
        };

        $post = $this->createPost($post);
        $post = $this->populatePost($post);
        $this->modifyPost($post);

        $tagId = $post->tags()->firstOrFail()->id;
        DB::table('tags')->where('id', $tagId)->delete();

        // When
        $post->update(['votes' => 99]);

        // Then
        $revision = $post->revisions()->latest('id')->firstOrFail();

        $this->assertNotContains('tags', $revision->changed);
    }

    #[Test]
    public function it_excludes_a_relation_record_from_changed_when_it_fails_the_given_predicate()
    {
        // Given
        $before = new Revision(['metadata' => ['relations' => ['tags' => [
            'records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'name' => 'Tag 1']]],
        ]]]]);
        $after = new Revision(['metadata' => ['relations' => ['tags' => [
            'records' => ['primary_key' => 'id', 'items' => []],
        ]]]]);

        $diff = new Diff($before, $after);

        // When / Then
        $this->assertContains('tags', $diff->changed());
        $this->assertNotContains('tags', $diff->changed(['tags' => fn (array $item) => false]));
        $this->assertArrayNotHasKey('tags', $diff->changes(['tags' => fn (array $item) => false]));
    }

    #[Test]
    public function it_leaves_a_relation_without_a_registered_predicate_untouched()
    {
        // Given
        $before = new Revision(['metadata' => ['relations' => [
            'tags' => ['records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'name' => 'Tag 1']]]],
            'comments' => ['records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'title' => 'Comment 1']]]],
        ]]]);
        $after = new Revision(['metadata' => ['relations' => [
            'tags' => ['records' => ['primary_key' => 'id', 'items' => []]],
            'comments' => ['records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'title' => 'Comment 1 updated']]]],
        ]]]);

        $diff = new Diff($before, $after);

        // When: only 'tags' has a registered predicate — 'comments' has none.
        $changed = $diff->changed(['tags' => fn (array $item) => false]);

        // Then
        $this->assertNotContains('tags', $changed);
        $this->assertContains('comments', $changed);
    }

    #[Test]
    public function it_does_not_report_an_unchanged_relation_record_as_a_change_when_it_fails_the_given_predicate_on_both_sides()
    {
        // Given
        // Nothing actually happened to this record between before and after — it's identical
        // on both sides — so it must not be reported as a change just because it now fails
        // the predicate (e.g. it references something that has since been deleted).
        $before = new Revision(['metadata' => ['relations' => ['tags' => [
            'records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'name' => 'Ghost']]],
        ]]]]);
        $after = new Revision(['metadata' => ['relations' => ['tags' => [
            'records' => ['primary_key' => 'id', 'items' => [['id' => 1, 'name' => 'Ghost']]],
        ]]]]);

        $diff = new Diff($before, $after);

        // When / Then
        $this->assertNotContains('tags', $diff->changed(['tags' => fn (array $item) => false]));
    }
}
