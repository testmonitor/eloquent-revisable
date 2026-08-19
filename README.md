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

Once published, you can configure your user model and revision model in `config/revisable.php`.

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

#### Excluding relations from restoration

Relations are always tracked when listed in `withRelations()`, but you can prevent specific relations — or all of them — from being restored during a rollback. This is useful when a relation is managed by another system or process and should not be overwritten on rollback.

Exclude specific relations:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->withRelations('author', 'tags')
        ->withoutRestoringRelations('tags'); // tags are tracked but never restored
}
```

Or exclude all relations from restoration:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->withRelations('author', 'tags')
        ->withoutRestoringRelations(); // no relations are restored on rollback
}
```

Excluded relations are still snapshotted and visible in diffs — only the restoration step is skipped.

#### Filtering stale relation values

A captured relation record can become stale by the time you roll back — e.g. it references a row that has since been deleted. Register a predicate with `filterRelation()` to skip restoring any record that no longer passes it. The revision's stored metadata is never altered, so it stays intact for auditing:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->withRelations('customFields')
        ->filterRelation('customFields', fn (array $item) => CustomField::whereKey($item['custom_field_id'])->exists());
}
```

These filters affect restoring and the persisted `changed` column on newly created revisions. They never affect `diff()`, `Revision::diff()`, or `Revision::diffFromPrevious()` — all three always report the honest historical record, never hiding a real change just because a referenced row is gone.

#### Tracking relation changes (optional)

Laravel does not fire model events for certain relation operations, so the package provides two optional traits to fill those gaps. Both respect `withoutRevisioning()` and the `revisioning` event, and only trigger when the relation is listed in `withRelations()`.

> **Note:** Bulk query-builder operations such as `$article->attachments()->delete()` do not fire model events and will not trigger a revision regardless of which traits are used.

**Many-to-many (BelongsToMany / MorphToMany)**

`attach`, `detach`, `sync`, `toggle`, and `updateExistingPivot` bypass model events. Add `HasRevisionablePivots` to the parent to capture these changes:

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

A revision is only triggered when the operation results in an actual change.

**HasOne, MorphOne, HasMany, MorphMany**

Child model saves and deletes do not bubble up to the parent as model events either. Add `HasRevisionableChildren` to the parent and `BelongsToRevisable` to each child model:

```php
// Parent model
use TestMonitor\Revisable\Concerns\HasRevisions;
use TestMonitor\Revisable\Concerns\HasRevisionableChildren;
use TestMonitor\Revisable\RevisableOptions;

class Article extends Model
{
    use HasRevisions, HasRevisionableChildren;

    public function getRevisionOptions(): RevisableOptions
    {
        return RevisableOptions::defaults()
            ->withRelations('attachments');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
```

```php
// Child model
use TestMonitor\Revisable\Concerns\BelongsToRevisable;

class Attachment extends Model
{
    use BelongsToRevisable;

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
```

The child detects its revisable parent by scanning its `BelongsTo` / `MorphTo` methods and the parent's `HasOne` / `MorphOne` / `HasMany` / `MorphMany` methods. **Both sides must declare an explicit return type** — methods without one are skipped silently.

If you cannot add return types, override `revisableParent()` on the child to return the parent directly:

```php
protected function revisableParent(): ?Model
{
    return $this->article;
}
```

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

Use `replaceWhen` with a boolean or a callable that receives the model and the latest revision:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->replaceWhen(fn ($model) => $model->status === 'draft');
}
```

The callable also receives the latest revision as its second argument, which lets you inspect its state when deciding whether to replace:

```php
->replaceWhen(fn ($model, $latest) => $latest->metadata()['attributes']['status'] === 'draft');
```

When the condition is true the latest revision is updated in place; its identity (id, `created_at`) is preserved. When the condition is false a new revision is created as normal, so the transition out of draft becomes its own permanent entry in the history.

If no revision exists yet the first save always creates one, regardless of the condition.

Rollback revisions are never targeted for replacement — they are always preserved as permanent checkpoints regardless of the condition.

If a different user edits the model, a new revision is always created to preserve per-user attribution.

Use `replaceWithin` to limit replacement to a time window. Once the window has passed since the last save, the next edit produces a new revision instead:

```php
public function getRevisionOptions(): RevisableOptions
{
    return RevisableOptions::defaults()
        ->replaceWhen(fn ($model) => $model->status === 'draft')
        ->replaceWithin(new \DateInterval('PT1H'));
}
```

The window is measured from the revision's last update, so it resets on every save within the window.

The living snapshot captures the post-save state, consistent with normal revision behaviour. After two saves in draft, the snapshot holds the state of the most recent save, which serves as the rollback point.

#### Version numbers

Every revision automatically gets a sequential `version` number (1, 2, 3, …), scoped to its model instance. Unlike a generated name, the version is a plain integer, so it carries no language or formatting choices — prefix it with whatever translated string fits your application:

```php
__('Version :number', ['number' => $revision->version]);
```

Use `saveAsRevision()` (see below) if you also want a caller-chosen label on top of the version number.

---

### Reading revisions

Revisions are standard Eloquent models and can be queried directly on any revisionable model, or across all models using the built-in scopes.

#### Accessing revisions

All revisions are available via the `revisions` relationship:

```php
$article = Article::find(1);

foreach ($article->revisions as $revision) {
    echo $revision->name . ' — ' . $revision->created_at . PHP_EOL;
}
```

Use `firstRevision` and `latestRevision` to jump directly to either end of the history:

```php
$article->firstRevision;
$article->latestRevision;
```

#### Querying revisions

Filter revisions using the built-in scopes:

```php
// Revisions created by a specific user
$revisions = Revision::forUser($user)->get();

// All revisions for a specific model instance
$revisions = Revision::forModel($article)->get();

// Exclude rollback revisions
$revisions = $article->revisions()->notRollback()->get();

// Only rollback revisions
$revisions = $article->revisions()->onlyRollbacks()->get();
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
$diff = $revision->diff();              // vs nothing — everything it holds appears as added
$diff = $revision->diff($other);        // vs a specific revision
$diff = $revision->diffFromPrevious();  // vs its predecessor (vs nothing if it's the first revision)

// What changed between the current model and a revision
$diff = $article->diff();               // vs the latest revision (vs nothing if none exist yet)
$diff = $article->diff($revision);      // vs a specific revision
```

The output of `changes()` contains field entries and relation entries in one flat array:

```php
$changes = $diff->changes();

// Field: ['before' => mixed, 'after' => mixed]
$changes['title'];    // ['before' => 'Draft', 'after' => 'Published']

// Relation: ['added' => [...ids], 'removed' => [...ids], 'changed' => [...]]
$changes['tags'];     // ['added' => [4], 'removed' => [1], 'changed' => []]
```

Use `all()` to include fields and relations that did not change:

```php
$all = $diff->all();
```

Call `asHtml()` to render a field diff as HTML with inline change highlights:

```php
$result = $diff->asHtml()->field('title');
// ['before' => 'The quick <del>brown</del> fox', 'after' => 'The quick <ins>red</ins> fox']
```

Use `before()` and `after()` to get back the two revisions the diff was built from (either may be `null` when diffed against nothing), and `beforeMetadata()` / `afterMetadata()` to read their raw captured metadata, optionally by dot-notation subkey:

```php
$diff->before();                        // the "before" revision, or null
$diff->after();                         // the "after" revision, or null

$diff->beforeMetadata();                // the full raw metadata array
$diff->beforeMetadata('attributes.title'); // a specific subkey
```

Fields that contain HTML are handled transparently — markup is rendered rather than escaped (only use this for trusted/sanitized HTML content), and changes are highlighted at the word level within the HTML structure:
```php
$result = $diff->asHtml()->field('body');
// before: '<p>Hello <del>world</del></p>'
// after:  '<p>Hello <ins>universe</ins></p>'
```

---

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

---

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

#### Rollback revisions

The revision created after a rollback is flagged with `rollback = true`. This has two effects:

- **It is never targeted by `replaceWhen`.** When a model uses living snapshots, subsequent edits replace the most recent regular revision — the rollback revision is always preserved as a permanent checkpoint.
- **It can be filtered using the built-in scopes** (`notRollback`, `onlyRollbacks`) — see [Querying revisions](#querying-revisions).

---

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

#### Combining multiple changes into a single revision

Creating a model and then saving one of its tracked relations normally produces two revisions: an initial one for the attributes, and another for the relation change. Use `createWithSingleRevision()` to suspend automatic revisioning while the callback runs, then create one revision from the final state. The callback must return the created model:

```php
$article = Article::createWithSingleRevision(function () {
    $article = Article::create(['title' => 'Title', 'body' => 'Body']);

    $article->tags()->attach($tagIds);

    return $article;
});
```

To batch changes to a model you already have, call `withSingleRevision()` on the instance instead. It only creates a revision if something tracked actually changed, and the callback receives the model as its argument:

```php
$article->withSingleRevision(function ($article) {
    $article->update(['title' => 'New title']);

    $article->tags()->sync($tagIds);
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
