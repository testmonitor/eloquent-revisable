<?php

namespace TestMonitor\Revisable\Tests;

use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;
use TestMonitor\Revisable\Generators\NameGeneratorFactory;
use TestMonitor\Revisable\Generators\VersionNameGenerator;
use TestMonitor\Revisable\RevisableServiceProvider;

class InvalidConfigurationTest extends TestCase
{
    #[Test]
    public function it_throws_when_the_configured_revision_model_does_not_extend_the_base_revision()
    {
        // Given
        config()->set('revisable.revision_model', \stdClass::class);

        // When
        // Then
        $this->expectException(InvalidConfiguration::class);

        RevisableServiceProvider::determineRevisionModel();
    }

    #[Test]
    public function it_throws_when_the_configured_revision_model_class_does_not_exist()
    {
        // Given
        config()->set('revisable.revision_model', 'App\\Models\\NonExistentRevision');

        // When
        // Then
        $this->expectException(InvalidConfiguration::class);

        RevisableServiceProvider::determineRevisionModel();
    }

    #[Test]
    public function it_throws_when_the_configured_name_generator_does_not_implement_the_contract()
    {
        // Given
        config()->set('revisable.name_generator', \stdClass::class);

        // When
        // Then
        $this->expectException(InvalidConfiguration::class);

        NameGeneratorFactory::create();
    }

    #[Test]
    public function it_throws_when_the_configured_name_generator_class_does_not_exist()
    {
        // Given
        config()->set('revisable.name_generator', 'App\\Generators\\NonExistentGenerator');

        // When
        // Then
        $this->expectException(InvalidConfiguration::class);

        NameGeneratorFactory::create();
    }

    #[Test]
    public function it_returns_null_when_no_name_generator_is_configured()
    {
        // Given
        config()->set('revisable.name_generator', null);

        // When
        $generator = NameGeneratorFactory::create();

        // Then
        $this->assertNull($generator);
    }

    #[Test]
    public function it_creates_a_generator_instance_when_the_class_is_valid()
    {
        // Given
        config()->set('revisable.name_generator', VersionNameGenerator::class);

        // When
        $generator = NameGeneratorFactory::create();

        // Then
        $this->assertInstanceOf(VersionNameGenerator::class, $generator);
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
