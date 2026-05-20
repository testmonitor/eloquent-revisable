<?php

namespace TestMonitor\Revisable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use TestMonitor\Revisable\Concerns\BelongsToRevisable;

class Attachment extends Model
{
    use SoftDeletes, BelongsToRevisable;

    protected $table = 'attachments';

    protected $fillable = ['name'];

    public function attachmentable(): MorphTo
    {
        return $this->morphTo();
    }
}
