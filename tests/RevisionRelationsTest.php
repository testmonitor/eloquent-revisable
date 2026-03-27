<?php

namespace TestMonitor\Revisable\Tests;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Comment;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\Tests\Models\Tag;

class RevisionRelationsTest extends TestCase
{
    // BelongsTo

    #[Test]
    public function it_includes_belongs_to_relation_data_in_the_revision()
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

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('author', $revision->metadata['relations']);
        $this->assertEquals(BelongsTo::class, $revision->metadata['relations']['author']['type']);
        $this->assertEquals($post->author->title, $revision->metadata['relations']['author']['records']['items'][0]['title']);
        $this->assertEquals($post->author->name, $revision->metadata['relations']['author']['records']['items'][0]['name']);
        $this->assertEquals($post->author->age, $revision->metadata['relations']['author']['records']['items'][0]['age']);
        $this->assertArrayNotHasKey('created_at', $revision->metadata['relations']['author']['records']['items'][0]);
        $this->assertArrayNotHasKey('updated_at', $revision->metadata['relations']['author']['records']['items'][0]);
    }

    #[Test]
    public function it_captures_the_author_values_at_the_time_of_revisioning()
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
        $post->author()->update([
            'title' => 'Author title updated',
            'name' => 'Author name updated',
            'age' => 100,
        ]);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals('Author title', $revision->metadata['relations']['author']['records']['items'][0]['title']);
        $this->assertEquals('Author name', $revision->metadata['relations']['author']['records']['items'][0]['name']);
        $this->assertEquals('30', $revision->metadata['relations']['author']['records']['items'][0]['age']);
    }

    #[Test]
    public function it_restores_the_author_when_rolling_back_to_a_revision()
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

        $post->author()->update([
            'title' => 'Author title updated',
            'name' => 'Author name updated',
            'age' => 100,
        ]);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $author = $post->fresh()->author;

        $this->assertEquals('Author title', $author->title);
        $this->assertEquals('Author name', $author->name);
        $this->assertEquals('30', $author->age);
    }

    // BelongsToMany

    #[Test]
    public function it_includes_belongs_to_many_relation_data_in_the_revision()
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

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('tags', $revision->metadata['relations']);
        $this->assertArrayHasKey('records', $revision->metadata['relations']['tags']);
        $this->assertArrayHasKey('pivots', $revision->metadata['relations']['tags']);
        $this->assertEquals(BelongsToMany::class, $revision->metadata['relations']['tags']['type']);

        for ($i = 1; $i <= 3; $i++) {
            $tag = Tag::find($i);

            $this->assertEquals($tag->name, $revision->metadata['relations']['tags']['records']['items'][$i - 1]['name']);
            $this->assertEquals($post->id, $revision->metadata['relations']['tags']['pivots']['items'][$i - 1]['post_id']);
            $this->assertEquals($tag->id, $revision->metadata['relations']['tags']['pivots']['items'][$i - 1]['tag_id']);
            $this->assertArrayNotHasKey('created_at', $revision->metadata['relations']['tags']['records']['items'][$i - 1]);
            $this->assertArrayNotHasKey('updated_at', $revision->metadata['relations']['tags']['records']['items'][$i - 1]);
        }
    }

    #[Test]
    public function it_captures_the_pivot_state_at_the_time_of_revisioning()
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

        $revision = $post->revisions()->firstOrFail();

        // When
        $post->tags()->detach($post->tags()->firstOrFail()->id);

        // Then
        $this->assertCount(3, $revision->metadata['relations']['tags']['pivots']['items']);
    }

    #[Test]
    public function it_restores_detached_tags_when_rolling_back_to_a_revision()
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
        $post->tags()->detach($post->tags()->firstOrFail()->id);
        $this->assertEquals(2, $post->tags()->count());

        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(3, $post->tags()->count());
    }

    #[Test]
    public function it_recreates_a_hard_deleted_tag_when_rolling_back_to_a_revision()
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

        $tagId = $post->tags()->firstOrFail()->id;
        DB::table('tags')->where('id', $tagId)->delete();
        $this->assertEquals(2, $post->tags()->count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(3, $post->tags()->count());
    }

    // HasMany

    #[Test]
    public function it_includes_has_many_relation_data_in_the_revision()
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

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('comments', $revision->metadata['relations']);
        $this->assertEquals(HasMany::class, $revision->metadata['relations']['comments']['type']);

        for ($i = 1; $i <= 3; $i++) {
            $comment = Comment::limit(1)->offset($i - 1)->firstOrFail();

            $this->assertEquals($post->id, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['post_id']);
            $this->assertEquals($comment->title, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['title']);
            $this->assertEquals($comment->content, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['content']);
        }
    }

    #[Test]
    public function it_captures_the_comment_values_at_the_time_of_revisioning()
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
        for ($i = 1; $i <= 3; $i++) {
            $post->comments()->limit(1)->offset($i - 1)->firstOrFail()->update([
                'title' => 'Comment title ' . $i . ' updated',
                'content' => 'Comment content ' . $i . ' updated',
                'active' => false,
            ]);
        }

        // Then
        $revision = $post->revisions()->firstOrFail();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertEquals('Comment title ' . $i, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['title']);
            $this->assertEquals('Comment content ' . $i, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['content']);
            $this->assertEquals(1, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['active']);
        }
    }

    #[Test]
    public function it_restores_all_comments_when_rolling_back_to_a_revision()
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

        for ($i = 1; $i <= 3; $i++) {
            $post->comments()->limit(1)->offset($i - 1)->firstOrFail()->update([
                'title' => 'Comment title ' . $i . ' updated',
                'content' => 'Comment content ' . $i . ' updated',
                'active' => false,
            ]);
        }

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        for ($i = 1; $i <= 3; $i++) {
            $comment = $post->fresh()->comments()->limit(1)->offset($i - 1)->firstOrFail();

            $this->assertEquals('Comment title ' . $i, $comment->title);
            $this->assertEquals('Comment content ' . $i, $comment->content);
            $this->assertEquals(1, $comment->active);
        }
    }

    #[Test]
    public function it_removes_extra_comments_added_after_the_revision_when_rolling_back()
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

        $commentCountAtRevision = $post->comments()->count();

        // When
        $post->comments()->create([
            'title' => 'Extra comment title',
            'content' => 'Extra comment content',
            'date' => Carbon::now(),
            'active' => true,
        ]);

        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals($commentCountAtRevision, $post->comments()->count());
    }

    #[Test]
    public function it_restores_a_soft_deleted_comment_when_rolling_back_to_a_revision()
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

        $comment = $post->comments()->firstOrFail();
        $comment->delete();
        $this->assertEquals(2, $post->comments()->count());

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals(3, $post->comments()->count());
    }

    // HasOne

    #[Test]
    public function it_includes_has_one_relation_data_in_the_revision()
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

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('reply', $revision->metadata['relations']);
        $this->assertEquals(HasOne::class, $revision->metadata['relations']['reply']['type']);
        $this->assertEquals($post->id, $revision->metadata['relations']['reply']['records']['items'][0]['post_id']);
        $this->assertEquals('Reply subject', $revision->metadata['relations']['reply']['records']['items'][0]['subject']);
        $this->assertEquals('Reply content', $revision->metadata['relations']['reply']['records']['items'][0]['content']);
    }

    #[Test]
    public function it_captures_the_reply_values_at_the_time_of_revisioning()
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
        $post->reply()->update([
            'subject' => 'Reply subject updated',
            'content' => 'Reply content updated',
        ]);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertEquals('Reply subject', $revision->metadata['relations']['reply']['records']['items'][0]['subject']);
        $this->assertEquals('Reply content', $revision->metadata['relations']['reply']['records']['items'][0]['content']);
    }

    #[Test]
    public function it_restores_the_reply_when_rolling_back_to_a_revision()
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

        $post->reply()->update([
            'subject' => 'Reply subject updated',
            'content' => 'Reply content updated',
        ]);

        // When
        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $reply = $post->fresh()->reply;

        $this->assertEquals('Reply subject', $reply->subject);
        $this->assertEquals('Reply content', $reply->content);
    }

    #[Test]
    public function it_removes_extra_replies_added_after_the_revision_when_rolling_back()
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

        $replyCountAtRevision = $post->reply()->count();

        // When
        $post->reply()->create([
            'subject' => 'Extra reply subject',
            'content' => 'Extra reply content',
        ]);

        $post->rollbackToRevision($post->revisions()->firstOrFail());

        // Then
        $this->assertEquals($replyCountAtRevision, $post->reply()->count());
    }
}
