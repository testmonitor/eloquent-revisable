<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionNamingTest extends TestCase
{
    #[Test]
    public function it_stores_no_name_by_default()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $this->assertNull($post->revisions()->firstOrFail()->name);
    }

    #[Test]
    public function it_uses_a_manually_provided_name_when_saving_as_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->saveAsRevision('my-checkpoint');

        // Then
        $this->assertEquals('my-checkpoint', Revision::firstOrFail()->name);
    }

    #[Test]
    public function it_preserves_the_revision_name_when_replacing()
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
        $post->saveAsRevision('first-name');

        // When
        $this->modifyPost($post, ['name' => 'Third name']);

        // Then
        $this->assertCount(1, $post->revisions()->get());
        $this->assertEquals('first-name', $post->revisions()->firstOrFail()->name);
    }
}
