<?php

namespace TestMonitor\Revisable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use TestMonitor\Revisable\Concerns\HasRevisionableChildren;
use TestMonitor\Revisable\Concerns\HasRevisions;
use TestMonitor\Revisable\RevisableOptions;
use TestMonitor\Revisable\Tests\Events\PostRolledBack;
use TestMonitor\Revisable\Tests\Events\PostRollingBack;

class Post extends Model
{
    use HasRevisionableChildren, HasRevisions;

    protected $table = 'posts';

    protected $dispatchesEvents = [
        'rollingBack' => PostRollingBack::class,
        'rolledBack' => PostRolledBack::class,
    ];

    protected $fillable = [
        'author_id',
        'name',
        'slug',
        'content',
        'votes',
        'views',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function reply(): HasOne
    {
        return $this->hasOne(Reply::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachmentable');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id')
            ->withPivot('position');
    }

    public function getRevisionOptions(): RevisableOptions
    {
        return RevisableOptions::defaults();
    }
}
