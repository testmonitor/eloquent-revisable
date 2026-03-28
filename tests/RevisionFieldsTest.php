<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionFieldsTest extends TestCase
{
    #[Test]
    public function it_only_stores_the_specified_fields_in_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->onlyFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayHasKey('name', $revision->metadata);
        $this->assertArrayHasKey('votes', $revision->metadata);
        $this->assertArrayNotHasKey('slug', $revision->metadata);
        $this->assertArrayNotHasKey('content', $revision->metadata);
        $this->assertArrayNotHasKey('views', $revision->metadata);
    }

    #[Test]
    public function it_excludes_the_specified_fields_from_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->exceptFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();

        $this->assertArrayNotHasKey('name', $revision->metadata);
        $this->assertArrayNotHasKey('votes', $revision->metadata);
        $this->assertArrayHasKey('slug', $revision->metadata);
        $this->assertArrayHasKey('content', $revision->metadata);
        $this->assertArrayHasKey('views', $revision->metadata);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_only_non_revisioned_fields_change()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->onlyFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $post->update([
            'slug' => 'changed-slug',
            'content' => 'Changed content',
        ]);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_does_not_create_a_revision_when_only_excluded_fields_change()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->exceptFields('name', 'votes');
            }
        };

        $post = $this->createPost($post);

        // When
        $post->update([
            'name' => 'Changed name',
            'votes' => 99,
        ]);

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_can_reconstruct_the_model_from_a_revision()
    {
        // Given
        $post = $this->createPost();

        // When
        $this->modifyPost($post);

        // Then
        $revision = $post->revisions()->firstOrFail();
        $model = $revision->toModel();

        $this->assertInstanceOf(Post::class, $model);
        $this->assertTrue($model->exists);
        $this->assertEquals($post->id, $model->id);
        $this->assertEquals('Post name', $model->name);
        $this->assertEquals('post-slug', $model->slug);
    }
}
