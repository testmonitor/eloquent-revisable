<?php

namespace TestMonitor\Revisable\Renderers;

enum FieldType
{
    case Plain;
    case Html;
    case PlainList;
    case HtmlList;

    /**
     * Whether this type stores a JSON-encoded array of items rather than a single value.
     */
    public function isList(): bool
    {
        return $this === self::PlainList || $this === self::HtmlList;
    }

    /**
     * Whether this type needs a $renderer to turn its raw value(s) into HTML before diffing.
     */
    public function isHtml(): bool
    {
        return $this === self::Html || $this === self::HtmlList;
    }
}
