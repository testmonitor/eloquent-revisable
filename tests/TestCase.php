<?php

namespace TestMonitor\Revisable\Tests;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase as Orchestra;
use TestMonitor\Revisable\RevisableServiceProvider;
use TestMonitor\Revisable\Tests\Models\Author;
use TestMonitor\Revisable\Tests\Models\Post;
use TestMonitor\Revisable\Tests\Models\Tag;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            RevisableServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('revisable.auth_driver', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }

    protected function createAuthor(): Author
    {
        return Author::create([
            'title' => 'Author title',
            'name' => 'Author name',
            'age' => 30,
        ]);
    }

    /**
     * @return Collection<int, Tag>
     */
    protected function createTags(int $count = 3)
    {
        for ($i = 1; $i <= $count; $i++) {
            Tag::create(['name' => 'Tag name '.$i]);
        }

        return Tag::all();
    }

    /**
     * Populate a post with its related models (reply, comments, tags).
     */
    protected function populatePost(Post $post): Post
    {
        $post->reply()->create([
            'post_id' => $post->id,
            'subject' => 'Reply subject',
            'content' => 'Reply content',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $post->comments()->create([
                'id' => $i,
                'post_id' => $post->id,
                'title' => 'Comment title '.$i,
                'content' => 'Comment content '.$i,
                'date' => Carbon::now(),
                'active' => true,
            ]);
        }

        $tags = $this->createTags();

        $post->tags()->attach($tags->pluck('id')->toArray());

        return $post->fresh();
    }

    protected function createPost(Post $model = new Post): Post
    {
        $author = $this->createAuthor();

        return $model->create([
            'author_id' => $author->id,
            'name' => 'Post name',
            'slug' => 'post-slug',
            'content' => 'Post content',
            'votes' => 10,
            'views' => 100,
        ])->fresh();
    }

    protected function modifyPost(Post $post, array $overrides = []): void
    {
        $post->update(array_merge([
            'name' => 'Another post name',
            'slug' => 'another-post-slug',
            'content' => 'Another post content',
            'votes' => 20,
            'views' => 200,
        ], $overrides));
    }
}
