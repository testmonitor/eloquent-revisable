<?php

namespace TestMonitor\Revisable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use TestMonitor\Revisable\Concerns\BelongsToRevisable;

class Comment extends Model
{
    use BelongsToRevisable, SoftDeletes;

    protected $table = 'comments';

    protected $fillable = [
        'post_id',
        'title',
        'content',
        'date',
        'active',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
