<?php

namespace TestMonitor\Revisable\Renderers\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * A small, fluent wrapper around DOMDocument for editing an HTML snippet in place.
 *
 * Parses the snippet once, exposes a handful of purpose-built mutations, and
 * serializes it back out — keeping DOM/libxml mechanics out of the caller.
 */
class HtmlFragment
{
    protected DOMDocument $dom;

    protected DOMElement $root;

    protected DOMXPath $xpath;

    public function __construct(string $html)
    {
        $this->dom = new DOMDocument;

        $previousSetting = libxml_use_internal_errors(true);

        $this->dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_use_internal_errors($previousSetting);

        $this->xpath = new DOMXPath($this->dom);
        $this->root = $this->xpath->query('//*[@id="__root__"]')->item(0);
    }

    /**
     * Rename every element matched by $query to $tagName, preserving its attributes and children.
     */
    public function renameElements(string $query, string $tagName): static
    {
        foreach (iterator_to_array($this->xpath->query($query)) as $element) {
            $replacement = $this->dom->createElement($tagName);

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $replacement->setAttribute($attribute->name, $attribute->value);
            }

            while ($element->firstChild) {
                $replacement->appendChild($element->firstChild);
            }

            $element->parentNode->replaceChild($replacement, $element);
        }

        return $this;
    }

    /**
     * Remove every element matched by $query, along with its content.
     */
    public function removeElements(string $query): static
    {
        foreach (iterator_to_array($this->xpath->query($query)) as $element) {
            $element->parentNode->removeChild($element);
        }

        return $this;
    }

    /**
     * Drop an attribute from every element matched by $query.
     */
    public function removeAttribute(string $query, string $attribute): static
    {
        foreach ($this->xpath->query($query) as $element) {
            $element->removeAttribute($attribute);
        }

        return $this;
    }

    /**
     * Repeatedly remove elements matched by $selector once they're left with no visible
     * content — e.g. a <ul> whose items were all removed. Repeats since emptying one
     * element can in turn leave its own parent empty too.
     */
    public function removeEmptyElements(string $selector): static
    {
        do {
            $removed = 0;

            foreach (iterator_to_array($this->xpath->query($selector)) as $element) {
                if (! $this->isBlank($element)) {
                    continue;
                }

                $element->parentNode->removeChild($element);
                $removed++;
            }
        } while ($removed > 0);

        return $this;
    }

    /**
     * Remove elements matched by $selector that are left with no visible content once their
     * $descendantTag descendants are disregarded — regardless of how deeply nested they are.
     * Elements that already had no visible content before this call are left untouched.
     */
    public function removeElementsEmptiedBy(string $selector, string $descendantTag): static
    {
        $candidates = iterator_to_array($this->xpath->query($selector));

        $preexistingEmpty = [];

        foreach ($candidates as $element) {
            if ($this->isBlank($element)) {
                $preexistingEmpty[spl_object_id($element)] = true;
            }
        }

        // Deepest elements first, so clearing an inner element can empty out its ancestor too.
        foreach (array_reverse($candidates) as $element) {
            if (isset($preexistingEmpty[spl_object_id($element)])) {
                continue;
            }

            // A nested candidate that held all of this element's content was just removed.
            if ($this->isBlank($element)) {
                $element->parentNode->removeChild($element);

                continue;
            }

            if ($this->xpath->query(".//{$descendantTag}", $element)->length === 0) {
                continue;
            }

            if (trim($this->textOutside($element, $descendantTag)) === '') {
                $element->parentNode->removeChild($element);
            }
        }

        return $this;
    }

    /**
     * Serialize the fragment back into an HTML string.
     */
    public function toHtml(): string
    {
        // Childless elements would otherwise have their closing tag silently dropped by
        // libxml's HTML serializer (e.g. <li></li> would come back out as just <li>); a
        // placeholder text node forces it to always emit both tags.
        foreach (iterator_to_array($this->xpath->query('.//*[not(node())]', $this->root)) as $element) {
            $element->appendChild($this->dom->createTextNode(''));
        }

        $html = '';

        foreach ($this->root->childNodes as $child) {
            $html .= $this->dom->saveHTML($child);
        }

        return $html;
    }

    /**
     * Concatenate the visible text directly inside $element, excluding any text nested
     * inside a descendant $tagName element (e.g. the <ins>/<del> being disregarded).
     */
    protected function textOutside(DOMNode $element, string $tagName): string
    {
        $text = '';

        foreach ($this->xpath->query('.//text()', $element) as $textNode) {
            if (! $this->hasAncestorNamed($textNode, $tagName, $element)) {
                $text .= $textNode->textContent;
            }
        }

        return $text;
    }

    /**
     * Determine whether $node has an ancestor named $tagName before reaching $boundary.
     */
    protected function hasAncestorNamed(DOMNode $node, string $tagName, DOMNode $boundary): bool
    {
        $ancestor = $node->parentNode;

        while ($ancestor !== null && $ancestor !== $boundary) {
            if ($ancestor->nodeName === $tagName) {
                return true;
            }

            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    /**
     * Determine whether $element has no content beyond whitespace-only text.
     */
    protected function isBlank(DOMNode $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (! ($child instanceof DOMText) || trim($child->wholeText) !== '') {
                return false;
            }
        }

        return true;
    }
}
