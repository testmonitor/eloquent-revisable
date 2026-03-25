<?php

namespace TestMonitor\Revisable\Tests;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use TestMonitor\Revisable\Contracts\NameGenerator;
use TestMonitor\Revisable\Generators\VersionNameGenerator;
use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Models\Post;

class RevisionNamingTest extends TestCase
{
    #[Test]
    public function it_generates_sequential_version_names_using_the_version_name_generator()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->nameRevisionUsing(new VersionNameGenerator);
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);
        $this->modifyPost($post, ['name' => 'Yet another post name', 'slug' => 'yet-another-post-slug', 'content' => 'Yet another post content', 'votes' => 30, 'views' => 300]);
        $this->modifyPost($post);

        // Then
        $names = $post->revisions()->oldest()->pluck('name');

        $this->assertEquals(['v1', 'v2', 'v3'], $names->all());
    }

    #[Test]
    public function it_uses_a_custom_name_generator_when_configured()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                $generator = new class implements NameGenerator
                {
                    public function generate(Model $model): string
                    {
                        return 'snapshot';
                    }
                };

                return parent::getRevisionOptions()->nameRevisionUsing($generator);
            }
        };

        $post = $this->createPost($post);

        // When
        $this->modifyPost($post);

        // Then
        $this->assertEquals('snapshot', $post->revisions()->firstOrFail()->name);
    }

    #[Test]
    public function it_stores_no_name_when_auto_naming_is_disabled()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->nameRevisionUsing(null);
            }
        };

        $post = $this->createPost($post);

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
    public function it_prioritises_the_manual_name_over_the_configured_generator()
    {
        // Given
        $post = new class extends Post
        {
            public function getRevisionOptions(): RevisableOptions
            {
                return parent::getRevisionOptions()->nameRevisionUsing(new VersionNameGenerator);
            }
        };

        $post = $this->createPost($post);

        // When
        $post->saveAsRevision('explicit-name');

        // Then
        $this->assertEquals('explicit-name', Revision::firstOrFail()->name);
    }
}
