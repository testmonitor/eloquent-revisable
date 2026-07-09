<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionNavigationTest extends TestCase
{
    #[Test]
    public function it_returns_the_previous_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name']);

        $revisions = $post->revisions()->orderBy('id')->get();

        // When / Then
        $this->assertNull($revisions->first()->previous());
        $this->assertTrue($revisions->last()->previous()->is($revisions->first()));
    }

    #[Test]
    public function it_returns_the_next_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name']);

        $revisions = $post->revisions()->orderBy('id')->get();

        // When / Then
        $this->assertTrue($revisions->first()->next()->is($revisions->last()));
        $this->assertNull($revisions->last()->next());
    }

    #[Test]
    public function it_determines_whether_a_revision_is_the_first_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name']);

        $revisions = $post->revisions()->orderBy('id')->get();

        // When / Then
        $this->assertTrue($revisions->first()->isFirstRevision());
        $this->assertFalse($revisions->last()->isFirstRevision());
    }

    #[Test]
    public function it_determines_whether_a_revision_is_the_last_revision()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name']);

        $revisions = $post->revisions()->orderBy('id')->get();

        // When / Then
        $this->assertFalse($revisions->first()->isLastRevision());
        $this->assertTrue($revisions->last()->isLastRevision());
    }

    #[Test]
    public function it_treats_a_single_revision_as_both_the_first_and_the_last()
    {
        // Given
        $post = $this->createPost();

        $this->modifyPost($post);

        // When
        $revision = $post->revisions()->firstOrFail();

        // Then
        $this->assertTrue($revision->isFirstRevision());
        $this->assertTrue($revision->isLastRevision());
    }

    #[Test]
    public function it_treats_an_unsaved_revision_as_neither_the_first_nor_the_last()
    {
        // Given
        $revision = new Revision([
            'revisionable_type' => Post::class,
            'revisionable_id' => 1,
        ]);

        // When / Then
        $this->assertFalse($revision->isFirstRevision());
        $this->assertFalse($revision->isLastRevision());
    }
}
