<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionPropertiesTest extends TestCase
{
    #[Test]
    public function it_can_set_a_single_property_on_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);
        $revision = $post->latestRevision;

        // When
        $revision->setProperty('approved', true);

        // Then
        $this->assertTrue($revision->fresh()->properties['approved']);
    }

    #[Test]
    public function it_can_set_multiple_properties_on_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);
        $revision = $post->latestRevision;

        // When
        $revision->setProperties(['approved' => true, 'score' => 42]);

        // Then
        $fresh = $revision->fresh();
        $this->assertTrue($fresh->properties['approved']);
        $this->assertEquals(42, $fresh->properties['score']);
    }

    #[Test]
    public function it_overwrites_existing_keys_when_setting_properties()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);

        $revision = $post->latestRevision;
        $revision->setProperties(['approved' => true]);

        // When
        $revision->setProperties(['approved' => false, 'score' => 42]);

        // Then
        $fresh = $revision->fresh();
        $this->assertFalse($fresh->properties['approved']);
        $this->assertEquals(42, $fresh->properties['score']);
    }

    #[Test]
    public function it_can_remove_a_single_property_from_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);

        $revision = $post->latestRevision;
        $revision->setProperties(['approved' => true, 'score' => 42]);

        // When
        $revision->removeProperty('approved');

        // Then
        $fresh = $revision->fresh();
        $this->assertArrayNotHasKey('approved', $fresh->properties);
        $this->assertEquals(42, $fresh->properties['score']);
    }

    #[Test]
    public function it_can_clear_all_properties_from_a_revision()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);

        $revision = $post->latestRevision;
        $revision->setProperties(['approved' => true, 'score' => 42]);

        // When
        $revision->clearProperties();

        // Then
        $this->assertEmpty($revision->fresh()->properties);
    }

    #[Test]
    public function it_returns_the_revision_for_chaining()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $post = $this->createPost($post);
        $revision = $post->latestRevision;

        // When / Then
        $this->assertSame($revision, $revision->setProperties(['approved' => true]));
        $this->assertSame($revision, $revision->setProperty('score', 42));
        $this->assertSame($revision, $revision->removeProperty('approved'));
        $this->assertSame($revision, $revision->clearProperties());
    }
}
