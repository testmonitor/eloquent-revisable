<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionVersioningTest extends TestCase
{
    #[Test]
    public function it_assigns_sequential_version_numbers()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        $this->modifyPost($post);

        // Then
        $versions = $post->revisions()->oldest()->pluck('version');

        $this->assertEquals([1, 2, 3], $versions->all());
    }

    #[Test]
    public function it_preserves_the_revision_version_when_replacing()
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
        $this->assertCount(1, $post->revisions()->get());
        $this->assertEquals(1, $post->revisions()->firstOrFail()->version);
    }

    #[Test]
    public function it_continues_the_version_sequence_after_a_rollback()
    {
        // Given
        $post = $this->createPost();
        $this->modifyPost($post);

        $firstRevision = $post->revisions()->oldest()->firstOrFail();

        // When
        $post->rollbackToRevision($firstRevision);

        // Then
        $versions = $post->revisions()->oldest()->pluck('version');

        $this->assertEquals([1, 2], $versions->all());
    }
}
