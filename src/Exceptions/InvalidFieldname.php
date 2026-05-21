<?php

namespace TestMonitor\Revisable\Exceptions;

use Exception;

class InvalidFieldname extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @return static
     */
    public static function doesNotExist(string $fieldname): self
    {
        return new self(
            sprintf('The fieldname "%s" does not exist.', $fieldname)
        );
    }
}
