<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Models\Revision;

class WithoutRevisioningTest extends TestCase
{
    #[Test]
    public function it_suppresses_revision_creation_inside_a_callback()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->withoutRevisioning(function () use ($post) {
            $this->modifyPost($post);
        });

        // Then
        $this->assertEquals(0, Revision::count());
    }

    #[Test]
    public function it_resumes_revisioning_after_the_callback_completes()
    {
        // Given
        $post = $this->createPost();

        // When
        $post->withoutRevisioning(fn () => $this->modifyPost($post));
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);

        // Then
        $this->assertEquals(1, Revision::count());
    }

    #[Test]
    public function it_resumes_revisioning_even_when_the_callback_throws()
    {
        // Given
        $post = $this->createPost();

        // When
        try {
            $post->withoutRevisioning(function () use ($post) {
                $this->modifyPost($post);
                throw new \RuntimeException('Something went wrong');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // Then
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);

        $this->assertEquals(1, Revision::count());
    }
}
