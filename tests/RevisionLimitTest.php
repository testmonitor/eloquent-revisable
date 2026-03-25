<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionLimitTest extends TestCase
{
    #[Test]
    public function it_caps_the_number_of_stored_revisions_at_the_configured_limit()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->limitRevisionsTo(5);
            }
        };

        $post = $this->createPost($post);

        // When
        for ($i = 1; $i <= 10; $i++) {
            $this->modifyPost($post);
            $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        }

        // Then
        $this->assertEquals(5, Revision::count());
    }

    #[Test]
    public function it_removes_the_oldest_revisions_when_the_limit_is_exceeded()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->limitRevisionsTo(5);
            }
        };

        $post = $this->createPost($post);

        // When
        for ($i = 1; $i <= 10; $i++) {
            $this->modifyPost($post);
            $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        }

        // Then
        $this->assertEquals(16, $post->revisions()->oldest()->firstOrFail()->id);
    }

    #[Test]
    public function it_stores_all_revisions_when_no_limit_is_configured()
    {
        // Given
        $post = $this->createPost();

        // When
        for ($i = 1; $i <= 10; $i++) {
            $this->modifyPost($post);
            $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        }

        // Then
        $this->assertEquals(20, Revision::count());
    }
}
