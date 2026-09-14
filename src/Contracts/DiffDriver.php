<?php

namespace TestMonitor\Revisable\Contracts;

use TestMonitor\Revisable\Diffing\FieldDiff;

interface DiffDriver
{
    /**
     * Diff two values of this driver's content type. A null side means the value was
     * absent, which surfaces as an Added or Removed field.
     */
    public function diff(?string $before, ?string $after): FieldDiff;

    /**
     * Derive the key this value is aligned by when it appears inside a list, so that a
     * cosmetic edit still matches the same item.
     */
    public function key(string $value): string;
}
