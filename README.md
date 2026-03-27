# Eloquent Revisable

[![Latest Stable Version](https://poser.pugx.org/testmonitor/eloquent-revisable/v/stable)](https://packagist.org/packages/testmonitor/eloquent-revisable)
[![CircleCI](https://img.shields.io/circleci/project/github/testmonitor/eloquent-revisable.svg)](https://circleci.com/gh/testmonitor/eloquent-revisable)
[![StyleCI](https://styleci.io/repos/1192066315/shield)](https://styleci.io/repos/1192066315)
[![codecov](https://codecov.io/gh/testmonitor/eloquent-revisable/graph/badge.svg)](https://codecov.io/gh/testmonitor/eloquent-revisable)
[![License](https://poser.pugx.org/testmonitor/eloquent-revisable/license)](https://packagist.org/packages/testmonitor/eloquent-revisable)

A Laravel package that provides revision tracking for Eloquent models. Add the `HasRevisions` trait to any model to automatically snapshot its state on every change, with support for field filtering, relation snapshots, revision limits, rollbacks, and event hooks.

## Table of Contents

- [Installation](#installation)
- [Usage](#usage)
- [Examples](#examples)
  - [Configuration](#configuration)
  - [Reading revisions](#reading-revisions)
  - [Saving revisions](#saving-revisions)
  - [Rolling back](#rolling-back)
  - [Events & control](#events--control)
- [Tests](#tests)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Installation

Install the package via Composer:

	$ composer require testmonitor/eloquent-revisable

Publish the config file and migration:

	$ php artisan vendor:publish --provider="TestMonitor\Revisable\RevisableServiceProvider" --tag="config"
	$ php artisan vendor:publish --provider="TestMonitor\Revisable\RevisableServiceProvider" --tag="migrations"

Once published, you can configure your user model, revision model, and name generator in `config/revisable.php`.

Run the migration to create the `revisions` table:

	$ php artisan migrate

You're all set up now!

## Usage

Add the `HasRevisions` trait to your Eloquent model and implement the `getRevisionOptions` method:

```php
use TestMonitor\Revisable\Concerns\HasRevisions;
use TestMonitor\Revisable\RevisableOptions;

class Article extends Model
{
    use HasRevisions;

    public function getRevisionOptions(): RevisableOptions
    {
        return RevisableOptions::defaults();
    }
}
```

By default, a new revision is created every time the model is updated. The `RevisableOptions` fluent builder lets you control exactly what gets snapshotted and how.

## Examples

### Configuration

#### Tracking specific fields

Only create a revision when certain fields change:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->onlyFields('title', 'body', 'status');
}
```

Alternatively, exclude specific fields and track everything else:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->exceptFields('views', 'cached_at');
}
```

#### Tracking relation snapshots

Capture the state of related models alongside field values:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->withRelations('tags', 'categories');
}
```

> **Note:** Rolling back a revision that includes relations is a potentially destructive operation. Related records that were created *after* the snapshot was taken will be deleted (or soft-deleted, if the related model uses `SoftDeletes`). Only opt in to relation snapshots when you are prepared to handle this.

#### Tracking many-to-many changes (optional)

Laravel does not fire model events when a `BelongsToMany` or `MorphToMany` relation is mutated via `attach`, `detach`, `sync`, `toggle`, or `updateExistingPivot`. This means the package cannot automatically detect these changes and create a revision.

If you want pivot mutations on tracked relations to trigger revisions, add the optional `HasRevisionablePivots` trait to your model alongside `HasRevisions`:

```php
use TestMonitor\Revisable\Concerns\HasRevisions;
use TestMonitor\Revisable\Concerns\HasRevisionablePivots;
use TestMonitor\Revisable\RevisableOptions;

class Article extends Model
{
    use HasRevisions, HasRevisionablePivots;

    public function getRevisionOptions(): RevisableOptions
    {
        return RevisableOptions::defaults()
            ->withRelations('tags');
    }
}
```

With this trait in place, calling `$article->tags()->sync([1, 2, 3])` will automatically create a revision — no other changes required. The `withoutRevisioning()` helper and the `revisioning` event continue to work as expected.

A revision is only created when the relation is explicitly listed in `withRelations()` and the pivot operation results in an actual change. No revision is created when, for example, `sync` is called with the same IDs that are already attached.

> **Alternative:** If you prefer not to use the built-in trait, [laravel-pivot-events](https://github.com/mikebronner/laravel-pivot-events) is a dedicated package that fires `pivotAttached`, `pivotDetached`, and `pivotUpdated` events on the parent model. You can hook into those events and call `$model->saveAsRevision()` directly.

#### Enabling and disabling revisioning

Revisioning can be conditionally enabled or disabled per model using a boolean or a callable:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->enabledWhen(false);
}
```

A callable is evaluated at revision time, making it suitable for feature flags or any other runtime condition:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->enabledWhen(fn () => Feature::active('revision-tracking'));
}
```

> **Note:** `enabledWhen` controls whether revisions are created at all for a model. To suppress revisioning temporarily for a specific operation, use `withoutRevisioning()` instead — see [Suppressing revisioning](#suppressing-revisioning).

#### Creating a revision on model creation

By default, revisions are only created on updates. Enable revision on create as well:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->enableRevisionOnCreate();
}
```

#### Limiting the number of stored revisions

Keep only the most recent revisions and automatically prune the oldest ones:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->limitRevisionsTo(10);
}
```

#### Custom revision naming

The default `VersionNameGenerator` names revisions sequentially (v1, v2, …). You can provide your own generator by implementing the `NameGenerator` contract and registering it in the options:

```php
use TestMonitor\Revisable\Contracts\NameGenerator;

class TimestampNameGenerator implements NameGenerator
{
    public function generate(Model $model): string
    {
        return now()->toDateTimeString();
    }
}
```

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->nameRevisionUsing(new TimestampNameGenerator);
}
```

Pass `null` to disable automatic naming entirely:

```php
return RevisableOptions::defaults()->nameRevisionUsing(null);
```

### Reading revisions

#### Accessing revisions

All revisions are available via the `revisions` relationship:

```php
$article = Article::find(1);

foreach ($article->revisions as $revision) {
    echo $revision->name . ' — ' . $revision->created_at . PHP_EOL;
}
```

Filter revisions by user or model using the built-in scopes:

```php
// Revisions created by a specific user
$revisions = Revision::forUser($user)->get();

// All revisions for a specific model instance
$revisions = Revision::forModel($article->id, Article::class)->get();
```

### Saving revisions

#### Manually saving a revision

Save a named snapshot at any point without waiting for a model update:

```php
$article->saveAsRevision('Before major refactor');
```

Attach arbitrary key/value data to the revision using the `properties` argument:

```php
$article->saveAsRevision('Before major refactor', [
    'reason' => 'Restructuring content',
    'ticket' => 'PROJ-42',
]);
```

The properties are stored as JSON and available on the revision instance:

```php
$revision->properties['ticket']; // 'PROJ-42'
```

### Rolling back

#### Rolling back to a previous revision

Restore a model to the state captured in an earlier revision:

```php
$revision = $article->revisions()->oldest()->first();

$article->rollbackToRevision($revision);
```

To roll back to the most recent revision in one call:

```php
$article->rollback();
```

Returns `false` if no revisions exist.

By default, every rollback automatically creates a new revision capturing the restored state, so the history always reflects what happened. You can disable this per model:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->disableRevisionOnRollback();
}
```

### Events & control

#### Suppressing revisioning

Run operations without creating a revision:

```php
$article->withoutRevisioning(function () use ($article) {
    $article->update(['views' => $article->views + 1]);
});
```

#### Listening to events

The package fires four model events you can hook into directly on your model or via an observer.

```php
// Fires before a revision is created — return false to abort
Post::revisioning(function (Post $post): void {
    // ...
});

// Fires after a revision is created — access the revision via $post->latestRevision
Post::revisioned(function (Post $post): void {
    $post->notify(new PostRevisioned($post->latestRevision));
});

// Fires before a rollback — return false to abort
Post::rollingBack(function (Post $post): void {
    // ...
});

// Fires after a rollback
Post::rolledBack(function (Post $post): void {
    Cache::forget("post.{$post->id}");
});
```

You can also register an observer class, which is useful when handling multiple events on the same model:

```php
class PostObserver
{
    public function revisioned(Post $post): void { ... }
    public function rolledBack(Post $post): void { ... }
}

// In a service provider:
Post::observe(PostObserver::class);
```

## Tests

The package contains integration tests. You can run them using PHPUnit.

    $ vendor/bin/phpunit

## Changelog

Refer to [CHANGELOG](CHANGELOG.md) for more information.

## Contributing

Refer to [CONTRIBUTING](CONTRIBUTING.md) for contributing details.

## Credits

* **Thijs Kok** - *Lead developer* - [ThijsKok](https://github.com/thijskok)
* **Stephan Grootveld** - *Developer* - [Stefanius](https://github.com/stefanius)
* **Frank Keulen** - *Developer* - [FrankIsGek](https://github.com/frankisgek)

## License

The MIT License (MIT). Refer to the [License](LICENSE.md) for more information.
