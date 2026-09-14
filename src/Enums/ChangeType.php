<?php

namespace TestMonitor\Revisable\Enums;

enum ChangeType
{
    case Added;
    case Removed;
    case Kept;
    case Changed;

    /**
     * Whether content with this change type appears on the "before" side of a diff.
     */
    public function inBefore(): bool
    {
        return $this !== self::Added;
    }

    /**
     * Whether content with this change type appears on the "after" side of a diff.
     */
    public function inAfter(): bool
    {
        return $this !== self::Removed;
    }
}
