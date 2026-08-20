<?php

namespace TestMonitor\Revisable\Tests\Events;

use TestMonitor\Revisable\Models\Revision;
use TestMonitor\Revisable\Tests\Models\Post;

class PostRolledBack
{
    public function __construct(public Post $post, public Revision $revision) {}
}
