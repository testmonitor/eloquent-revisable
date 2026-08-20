<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableServiceProvider;
use TestMonitor\Revisable\Tests\Models\Author;

class ConfigurationTest extends TestCase
{
    // Revision model

    #[Test]
    public function it_returns_the_default_revision_model_class()
    {
        // When
        $revisionModel = RevisableServiceProvider::determineRevisionModel();

        // Then
        $this->assertEquals(Revision::class, $revisionModel);
    }

    #[Test]
    public function it_throws_when_the_configured_revision_model_does_not_extend_the_base_revision()
    {
        // Given
        config()->set('revisable.revision_model', \stdClass::class);

        // When / Then
        $this->expectException(InvalidConfiguration::class);

        RevisableServiceProvider::determineRevisionModel();
    }

    #[Test]
    public function it_throws_when_the_configured_revision_model_class_does_not_exist()
    {
        // Given
        config()->set('revisable.revision_model', 'App\\Models\\NonExistentRevision');

        // When / Then
        $this->expectException(InvalidConfiguration::class);

        RevisableServiceProvider::determineRevisionModel();
    }

    #[Test]
    public function it_builds_revisions_as_instances_of_the_configured_revision_model()
    {
        // Given
        $revisionModel = new class extends Revision {};

        config()->set('revisable.revision_model', $revisionModel::class);

        $post = $this->createPost();

        // When
        $revision = $post->createNewRevision();

        // Then
        $this->assertInstanceOf($revisionModel::class, $revision);
    }

    // User model

    #[Test]
    public function it_returns_the_configured_user_model_class()
    {
        // Given
        config()->set('revisable.user_model', Author::class);

        // When
        $userModel = RevisableServiceProvider::determineUserModel();

        // Then
        $this->assertEquals(Author::class, $userModel);
    }

    #[Test]
    public function it_throws_when_the_user_model_is_not_a_valid_model_class()
    {
        // Given
        config()->set('revisable.user_model', \stdClass::class);

        // When / Then
        $this->expectException(InvalidConfiguration::class);

        RevisableServiceProvider::determineUserModel();
    }
}
