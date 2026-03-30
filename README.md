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

Each model can be configured individually through `getRevisionOptions()`, independently of the global settings in `config/revisable.php`.

#### Creating a revision on model creation

By default, revisions are only created on updates:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->enableRevisionOnCreate();
}
```

#### Enabling and disabling revisioning

Accepts a boolean or a callable, evaluated at revision time — suitable for feature flags or any other runtime condition:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->enabledWhen(fn () => Feature::active('revision-tracking'));
}
```

`enabledWhen` controls whether revisions are created at all for a model. To suppress revisioning temporarily for a specific operation, use `withoutRevisioning()` instead — see [Suppressing revisioning](#suppressing-revisioning).

#### Tracking specific fields

By default all fields are tracked. Use `onlyFields` to include a specific set, or `exceptFields` to exclude certain fields and track everything else:

```php
// Include only these fields
return RevisableOptions::defaults()
    ->onlyFields('title', 'body', 'status');

// Or exclude specific fields and track everything else
return RevisableOptions::defaults()
    ->exceptFields('views', 'cached_at');
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

> **Warning:** Rolling back a revision that includes relations will delete related records created after the snapshot was taken (or soft-delete them if the model uses `SoftDeletes`). Only opt in when you are prepared to handle this.

#### Tracking many-to-many changes (optional)

Laravel does not fire model events for `BelongsToMany` or `MorphToMany` mutations (`attach`, `detach`, `sync`, `toggle`, `updateExistingPivot`), so the package cannot detect them automatically. Add the optional `HasRevisionablePivots` trait to make pivot changes trigger revisions:

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

A revision is only triggered when the relation is listed in `withRelations()` and the operation results in an actual change. The `withoutRevisioning()` helper and the `revisioning` event continue to work as expected.

If you prefer not to use the built-in trait, [laravel-pivot-events](https://github.com/mikebronner/laravel-pivot-events) fires `pivotAttached`, `pivotDetached`, and `pivotUpdated` events on the parent model — hook into those and call `$model->saveAsRevision()` directly.

#### Limiting the number of stored revisions

Automatically prune the oldest revisions once the limit is reached:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->limitRevisionsTo(10);
}
```

#### Living snapshots (replace instead of accumulate)

By default every save creates a new revision. When a model goes through many minor edits before reaching a stable state — such as a draft document — you may prefer to keep a single *living snapshot* that is overwritten on each save, rather than accumulating many interim revisions.

Use `replaceWhen` with a boolean or a callable that receives the model:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->replaceWhen(fn ($model) => $model->status === 'draft');
}
```

When the condition is true the latest revision is updated in place; its identity (id, `created_at`) is preserved. When the condition is false a new revision is created as normal, so the transition out of draft becomes its own permanent entry in the history.

If no revision exists yet the first save always creates one, regardless of the condition.

The living snapshot captures the pre-save state, consistent with normal revision behaviour. After two saves in draft, the snapshot holds the state before the most recent save, which serves as the rollback point.

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

Revisions are standard Eloquent models and can be queried directly on any revisionable model, or across all models using the built-in scopes.

#### Accessing revisions

All revisions are available via the `revisions` relationship, and the most recent one via `latestRevision`:

```php
$article = Article::find(1);

foreach ($article->revisions as $revision) {
    echo $revision->name . ' — ' . $revision->created_at . PHP_EOL;
}

// Most recent and oldest revision
$revision = $article->latestRevision;
$revision = $article->firstRevision;
```

#### Querying revisions

Filter revisions by user or model using the built-in scopes:

```php
// Revisions created by a specific user
$revisions = Revision::forUser($user)->get();

// All revisions for a specific model instance
$revisions = Revision::forModel($article)->get();
```

#### Reconstructing a model from a revision

Any revision can be reconstructed as a model instance reflecting the state at the time it was captured:

```php
$snapshot = $article->firstRevision->toModel(); // an Article instance, not a live record
echo $snapshot->title;
```

#### Comparing revisions

Use `diff()` to compare two states and inspect what changed. It returns a `Diff` object with `changes()` (only differing fields and relations) and `all()` (everything, including unchanged).

```php
// What changed between two revisions
$diff = $revision->diff();              // vs its predecessor
$diff = $revision->diff($other);        // vs a specific revision

// What changed between the current model and a revision
$diff = $article->diff();               // vs the latest revision
$diff = $article->diff($revision);      // vs a specific revision
```

The output of `changes()` contains field entries and relation entries in one flat array:

```php
$changes = $diff->changes();

// Field: ['old' => mixed, 'new' => mixed]
$changes['title'];    // ['old' => 'Draft', 'new' => 'Published']

// Relation: ['added' => [...ids], 'removed' => [...ids], 'changed' => [...]]
$changes['tags'];     // ['added' => [4], 'removed' => [1], 'changed' => []]
```

Use `all()` to include fields and relations that did not change:

```php
$all = $diff->all();
```

### Saving revisions

Revisions are created automatically on every save. Use `saveAsRevision()` when you need a named snapshot or want to attach additional context.

#### Manually saving a revision

Save a named snapshot at any point without waiting for a model update, optionally with extra context:

```php
$article->saveAsRevision('Before major refactor');

// Attach arbitrary key/value context via the properties argument
$article->saveAsRevision('Before major refactor', [
    'reason' => 'Restructuring content',
    'ticket' => 'PROJ-42',
]);
```

Properties are stored as JSON and available on the revision instance:

```php
$revision->properties['ticket']; // 'PROJ-42'
```

### Rolling back

Any revision can be used to restore a model — and its tracked relations — to an earlier state.

#### Rolling back to the latest revision

To roll back a model to its most recent revision:

```php
$article->rollback(); // returns false if no revisions exist
```

#### Rolling back to a specific revision

To restore a model to any earlier revision, pass the revision instance directly:

```php
$article->rollbackToRevision($article->firstRevision);
```

#### Disabling revision creation on rollback

By default, every rollback creates a new revision capturing the restored state. Disable this per model:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->disableRevisionOnRollback();
}
```

### Events & control

The package fires events before and after revisioning and rollback. These can be used to add behaviour, abort operations, or integrate with other systems. Individual saves can also be excluded from revision tracking.

#### Listening to events

The package fires four model events you can hook into directly or via an observer:

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

An observer class is useful when handling multiple events on the same model:

```php
class PostObserver
{
    public function revisioned(Post $post): void { ... }
    public function rolledBack(Post $post): void { ... }
}

// In a service provider:
Post::observe(PostObserver::class);
```

#### Suppressing revisioning

To run an operation without creating a revision:

```php
$article->withoutRevisioning(function () use ($article) {
    $article->update(['views' => $article->views + 1]);
});
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
