<?php

namespace TestMonitor\Revisable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use TestMonitor\Revisable\Concerns\HasRevisions;
use TestMonitor\Revisable\RevisableOptions;

class Post extends Model
{
    use HasRevisions;

    protected $table = 'posts';

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
