<?php

namespace TestMonitor\Revisable\Contracts;

use TestMonitor\Revisable\Diffing\FieldDiff;

interface DiffDriver
{
    /**
     * Diff two values of this driver's content type; a null side means the value was absent.
     */
    public function diff(?string $before, ?string $after): FieldDiff;

    /**
     * The key this value is aligned by inside a list, so a cosmetic edit still matches.
     */
    public function key(string $value): string;
}
