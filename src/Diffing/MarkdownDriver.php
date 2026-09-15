<?php

namespace TestMonitor\Revisable\Diffing;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use TestMonitor\Revisable\Contracts\DiffDriver;
use TestMonitor\Revisable\Diffing\Markdown\BlockChange;
use TestMonitor\Revisable\Diffing\Markdown\ChangeRenderer;
use TestMonitor\Revisable\Diffing\Markdown\FormattingChange;
use TestMonitor\Revisable\Diffing\Markdown\FormattingRenderer;
use TestMonitor\Revisable\Diffing\Markdown\InlineChange;
use TestMonitor\Revisable\Diffing\Support\ArrayAligner;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;

/**
 * Diffs markdown through its CommonMark AST. Block nodes are aligned by type and text,
 * matched leaves are diffed word by word, and the changes are written back into the tree
 * as marker nodes. CommonMark then renders both sides, so the markup is valid by
 * construction rather than repaired after the fact.
 */
final class MarkdownDriver implements DiffDriver
{
    /**
     * Node types that are never diffed inline. A word diff inside a code block or across
     * table cells produces noise rather than insight. Referenced as strings because Table
     * ships with the GFM extension and may not be loaded.
     *
     * @var list<class-string>
     */
    protected const array ATOMIC = [
        FencedCode::class,
        IndentedCode::class,
        ThematicBreak::class,
        HtmlBlock::class,
        'League\CommonMark\Extension\Table\Table',
    ];

    /**
     * Container types whose children are diffed individually.
     *
     * @var list<class-string>
     */
    protected const array CONTAINERS = [
        ListBlock::class,
        ListItem::class,
        BlockQuote::class,
    ];

    protected Environment $environment;

    /**
     * @param Environment|null $environment A CommonMark environment that has NOT been
     *                                      initialised yet, since the driver registers its own renderers on it.
     */
    public function __construct(?Environment $environment = null, protected WordDiffer $words = new WordDiffer)
    {
        if (! class_exists(Environment::class)) {
            throw InvalidConfiguration::missingCommonMark();
        }

        $this->environment = $environment ?? $this->defaultEnvironment();

        $this->environment->addRenderer(InlineChange::class, new ChangeRenderer);
        $this->environment->addRenderer(BlockChange::class, new ChangeRenderer);
        $this->environment->addRenderer(FormattingChange::class, new FormattingRenderer);
    }

    public function diff(?string $before, ?string $after): FieldDiff
    {
        $beforeDocument = $this->parse($before);
        $afterDocument = $this->parse($after);

        $blocks = $this->diffChildren($beforeDocument, $afterDocument);

        return new FieldDiff(
            $this->statusFor($before, $after, $blocks),
            $blocks,
            $before === null || $before === '' ? '' : $this->render($beforeDocument),
            $after === null || $after === '' ? '' : $this->render($afterDocument),
        );
    }

    public function key(string $value): string
    {
        return $this->textOf($this->parse($value));
    }

    /**
     * The field status, with one markdown-only correction on top of the shared rule:
     * markdown can carry content that produces no block nodes at all (whitespace only, or
     * nothing but a link reference definition). An empty block list reads as unchanged, so
     * two different values would report Kept. Compare the raw values instead in that case.
     *
     * @param list<BlockDiff> $blocks
     */
    protected function statusFor(?string $before, ?string $after, array $blocks): ChangeType
    {
        $status = FieldDiff::statusFor($before, $after, $blocks);

        $bothHeldAValue = $before !== null && $before !== '' && $after !== null && $after !== '';

        return $blocks === [] && $bothHeldAValue && $before !== $after
            ? ChangeType::Changed
            : $status;
    }

    /**
     * Align two nodes' children and diff each aligned pair, emitting one flat block list
     * in document order.
     *
     * @return list<BlockDiff>
     */
    protected function diffChildren(Node $before, Node $after): array
    {
        $beforeChildren = iterator_to_array($before->children(), false);
        $afterChildren = iterator_to_array($after->children(), false);

        $aligner = new ArrayAligner($beforeChildren, $afterChildren, $this->nodeKey(...));

        $blocks = [];

        foreach ($aligner->align() as $aligned) {
            foreach (array_map(null, $aligned->before, $aligned->after) as [$beforeNode, $afterNode]) {
                $blocks = [...$blocks, ...$this->diffNode($beforeNode, $afterNode)];
            }
        }

        return $blocks;
    }

    /**
     * Diff one aligned pair of nodes. A null side is a whole addition or removal.
     *
     * @return list<BlockDiff>
     */
    protected function diffNode(?Node $before, ?Node $after): array
    {
        if ($before === null) {
            return [$this->markWholeBlock($after, ChangeType::Added)];
        }

        if ($after === null) {
            return [$this->markWholeBlock($before, ChangeType::Removed)];
        }

        if ($this->blockSignature($before) !== $this->blockSignature($after)) {
            return [
                $this->markWholeBlock($before, ChangeType::Removed),
                $this->markWholeBlock($after, ChangeType::Added),
            ];
        }

        if ($this->isOneOf($before, self::CONTAINERS)) {
            return $this->diffChildren($before, $after);
        }

        if ($this->isOneOf($before, self::ATOMIC)) {
            return $this->diffAtomic($before, $after);
        }

        return [$this->diffLeaf($before, $after)];
    }

    /**
     * An atomic block is kept when its text matches, and replaced whole when it doesn't.
     *
     * Equality here deliberately does not go through textOf(): its trim() is right for an
     * alignment key, where surrounding whitespace shouldn't stop two blocks from pairing
     * up, but wrong for this comparison, where a fenced or indented code block renders its
     * leading and trailing whitespace verbatim. Trimming it away would let a reindented
     * code sample report as Kept while its rendered HTML actually changed.
     *
     * @return list<BlockDiff>
     */
    protected function diffAtomic(Node $before, Node $after): array
    {
        if (StringContainerHelper::getChildText($before) === StringContainerHelper::getChildText($after)) {
            return [new BlockDiff(ChangeType::Kept)];
        }

        return [
            $this->markWholeBlock($before, ChangeType::Removed),
            $this->markWholeBlock($after, ChangeType::Added),
        ];
    }

    /**
     * Diff a leaf block (paragraph, heading) word by word, writing the result back into
     * both trees as inline marker nodes.
     *
     * The text handed to the word differ is the concatenation of the block's own string
     * containers, not `textOf()`. That keeps the segment stream and the containers exactly
     * in step, which is what lets `applySegments()` map a segment back to the node it
     * came from.
     */
    protected function diffLeaf(Node $before, Node $after): BlockDiff
    {
        $beforeContainers = $this->stringContainers($before);
        $afterContainers = $this->stringContainers($after);

        $beforeText = $this->literalsOf($beforeContainers);
        $afterText = $this->literalsOf($afterContainers);

        if ($beforeText === $afterText) {
            // Same words. The block still counts as changed when the inline structure
            // differs, which is how a formatting-only edit is detected.
            if ($this->inlineShapeOf($before) === $this->inlineShapeOf($after)) {
                return new BlockDiff(ChangeType::Kept);
            }

            // Nothing was inserted or removed, so there is no segment to mark. Mark the
            // after side's text instead, or the change would render as two identical
            // looking sides and read as no change at all.
            $this->markFormatting($afterContainers);

            return new BlockDiff(ChangeType::Changed);
        }

        $segments = $this->words->diff($beforeText, $afterText);

        $this->applySegments($beforeContainers, $segments, ChangeType::Removed);
        $this->applySegments($afterContainers, $segments, ChangeType::Added);

        return new BlockDiff(ChangeType::Changed, $segments);
    }

    /**
     * Mark a whole node as added or removed and report it as one block.
     *
     * The node's words are deliberately left unmarked: a block that is wholly added or
     * wholly removed says everything it needs to at block level, and marking its words as
     * well would only nest one marker inside another.
     */
    protected function markWholeBlock(Node $node, ChangeType $type): BlockDiff
    {
        $text = $this->textOf($node);

        $node instanceof ListItem
            ? $this->markListItem($node, $type)
            : $this->wrapBlock($node, $type);

        return new BlockDiff($type, [new Segment($type, $text)]);
    }

    /**
     * Put a block-level marker in a node's place and move the node inside it. Valid for
     * any block whose parent takes flow content, which is every container this driver
     * recurses into except a list.
     */
    protected function wrapBlock(Node $node, ChangeType $type): void
    {
        $marker = new BlockChange($type);

        $node->replaceWith($marker);
        $marker->appendChild($node);
    }

    /**
     * Mark a list item from the inside, leaving the item itself exactly where the parser
     * put it. Two reasons, both about the markup CommonMark then renders: a list may only
     * hold list items, so a marker in the item's place would put <ins> directly inside
     * <ul>; and a marker between the item and its paragraph would push that paragraph out
     * of the list's tight rendering, so an added item would grow a <p> its siblings do not
     * have. Marking the contents keeps the item tight and the marker inside the <li>,
     * which is what a changed item already renders as.
     */
    protected function markListItem(ListItem $item, ChangeType $type): void
    {
        foreach ($item->children() as $child) {
            $this->isOneOf($child, self::ATOMIC) || $this->isOneOf($child, self::CONTAINERS)
                ? $this->wrapBlock($child, $type)
                : $this->markInlineContent($child, $type);
        }
    }

    /**
     * Move a leaf block's inline children under a single inline marker, so the block
     * itself, and its position in the tree, are left untouched.
     */
    protected function markInlineContent(Node $block, ChangeType $type): void
    {
        $children = iterator_to_array($block->children(), false);

        if ($children === []) {
            return;
        }

        $marker = new InlineChange($type);

        $children[0]->replaceWith($marker);

        foreach ($children as $child) {
            $marker->appendChild($child);
        }
    }

    /**
     * Wrap each piece of text in a formatting marker, leaving the inline nodes around it
     * (emphasis, links) in place so the new formatting still renders.
     *
     * @param list<AbstractStringContainer> $containers
     */
    protected function markFormatting(array $containers): void
    {
        foreach ($containers as $container) {
            if ($container->getLiteral() === '') {
                continue;
            }

            $marker = new FormattingChange;
            $marker->appendChild(new Text($container->getLiteral()));

            $container->replaceWith($marker);
        }
    }

    /**
     * Rewrite a block's string containers so the runs belonging to $side are wrapped in
     * inline markers.
     *
     * The segments of one side concatenate to exactly that side's text, and so do the
     * block's string containers, so the two can be walked together by character position:
     * each container consumes the next slice of the segment stream and is rebuilt from the
     * pieces it received. A container that straddles a segment boundary is split into a
     * Text / InlineChange / Text sequence in place, which leaves every surrounding inline
     * node (emphasis, strong, links) structurally untouched.
     *
     * Character positions are used rather than the token offsets on Segment, because a
     * single token can span several containers ("A**b**c" is one token across three Text
     * nodes) and so token offsets cannot express a container boundary at all.
     *
     * This walk does its arithmetic with strlen()/substr(), which count and slice bytes,
     * not multi-byte characters. That is safe only because every cut this method makes
     * lands on a boundary that already existed in the original string: a container
     * boundary from the parser, or a token boundary from WordDiffer's `/u` (Unicode-mode)
     * tokenizer, which never splits inside a multi-byte codepoint. Byte length still
     * exactly matches character content up to that boundary, so slicing there can never
     * land mid-codepoint. If a future change ever introduces a cut at a position that is
     * not already one of these boundaries, this would need mb_* functions instead.
     *
     * @param list<AbstractStringContainer> $containers
     * @param list<Segment> $segments
     */
    protected function applySegments(array $containers, array $segments, ChangeType $side): void
    {
        $stream = $this->streamFor($segments, $side);

        $index = 0;
        $offset = 0;

        foreach ($containers as $container) {
            $remaining = strlen($container->getLiteral());
            $pieces = [];

            while ($remaining > 0 && $index < count($stream)) {
                $segment = $stream[$index];
                $length = min(strlen($segment->text) - $offset, $remaining);

                if ($length > 0) {
                    $pieces[] = new Segment($segment->type, substr($segment->text, $offset, $length));

                    $offset += $length;
                    $remaining -= $length;
                }

                if ($offset >= strlen($segment->text)) {
                    $index++;
                    $offset = 0;
                }
            }

            $this->rewriteContainer($container, $pieces, $side);
        }
    }

    /**
     * The segments belonging to one side, in order. Concatenating their text reproduces
     * that side's input exactly, which is the invariant applySegments() walks on.
     *
     * @param list<Segment> $segments
     * @return list<Segment>
     */
    protected function streamFor(array $segments, ChangeType $side): array
    {
        return array_values(array_filter(
            $segments,
            fn (Segment $segment) => $side === ChangeType::Removed
                ? $segment->type->inBefore()
                : $segment->type->inAfter(),
        ));
    }

    /**
     * Rebuild one string container from the pieces of the segment stream it covers.
     *
     * @param list<Segment> $pieces
     */
    protected function rewriteContainer(AbstractStringContainer $container, array $pieces, ChangeType $side): void
    {
        if ($pieces === [] || collect($pieces)->every(fn (Segment $piece) => $piece->type === ChangeType::Kept)) {
            return;
        }

        // A code span or a raw inline carries markup that splitting would destroy, so it is
        // marked as a whole rather than rebuilt from plain text.
        if (! $container instanceof Text) {
            $this->wrapInline($container, $side);

            return;
        }

        $this->replaceWithPieces($container, $pieces, $side);
    }

    /**
     * Swap a text node for the sequence of plain and marked nodes its pieces describe.
     *
     * @param list<Segment> $pieces
     */
    protected function replaceWithPieces(AbstractStringContainer $container, array $pieces, ChangeType $side): void
    {
        $replacements = array_map(fn (Segment $piece) => $this->nodeFor($piece, $side), $pieces);

        $previous = array_shift($replacements);

        $container->replaceWith($previous);

        foreach ($replacements as $replacement) {
            $previous->insertAfter($replacement);
            $previous = $replacement;
        }
    }

    /**
     * The node one piece of the stream renders as: bare text when kept, text inside a
     * marker when it belongs to this side alone.
     */
    protected function nodeFor(Segment $piece, ChangeType $side): Node
    {
        $text = new Text($piece->text);

        if ($piece->type === ChangeType::Kept) {
            return $text;
        }

        $marker = new InlineChange($side);
        $marker->appendChild($text);

        return $marker;
    }

    /**
     * Wrap a single inline node in a marker, keeping the node itself intact.
     */
    protected function wrapInline(Node $node, ChangeType $side): void
    {
        $marker = new InlineChange($side);

        $node->replaceWith($marker);
        $marker->appendChild($node);
    }

    /**
     * Collect a node's text-carrying inline descendants, in document order.
     *
     * @return list<AbstractStringContainer>
     */
    protected function stringContainers(Node $node): array
    {
        $containers = [];

        foreach ($node->children() as $child) {
            if ($child instanceof AbstractStringContainer) {
                $containers[] = $child;

                continue;
            }

            $containers = [...$containers, ...$this->stringContainers($child)];
        }

        return $containers;
    }

    /**
     * @param list<AbstractStringContainer> $containers
     */
    protected function literalsOf(array $containers): string
    {
        return implode('', array_map(fn (AbstractStringContainer $container) => $container->getLiteral(), $containers));
    }

    /**
     * The sequence of inline node types in a block, used to tell a formatting-only change
     * from no change at all. Each node's class is paired with its inlineVariant(), the
     * same widening blockSignature() does for block nodes one level up: a node's
     * attributes are otherwise invisible to this comparison, which only ever sees the
     * class and, via the text stream compared before this is reached, the visible text.
     */
    protected function inlineShapeOf(Node $node): string
    {
        $shape = [];

        foreach ($node->children() as $child) {
            $shape[] = $child::class . ':' . $this->inlineVariant($child) . '(' . $this->inlineShapeOf($child) . ')';
        }

        return implode(',', $shape);
    }

    /**
     * The state an inline node carries that changes what CommonMark renders without
     * showing up in its class or its text. Mirrors blockVariant() one level down.
     */
    protected function inlineVariant(Node $node): string
    {
        return match (true) {
            // The destination and the title both reach the output as attributes (href/src
            // and title). The link or image label is a child node and already part of the
            // text comparison, so it does not need to be repeated here.
            $node instanceof Link, $node instanceof Image => $node->getUrl() . ':' . ($node->getTitle() ?? ''),
            // Hard break renders `<br />`; soft break renders the configured separator
            // (a plain newline by default). Neither leaves any text behind.
            $node instanceof Newline => (string) $node->getType(),
            default => '',
        };
    }

    /**
     * The alignment key for a block node: its type plus its normalised text, so a
     * paragraph never aligns to a heading carrying the same words.
     */
    protected function nodeKey(mixed $node): string
    {
        return $node instanceof Node
            ? $this->blockSignature($node) . ':' . $this->textOf($node)
            : (string) $node;
    }

    /**
     * What makes two blocks the same kind of block: their type plus their variant.
     */
    protected function blockSignature(Node $node): string
    {
        return $node::class . ':' . $this->blockVariant($node);
    }

    /**
     * The state a node carries that changes what CommonMark renders without showing up in
     * either its class or its text. Anything missing here is a change the diff cannot see,
     * and a field that renders two different sides would report as kept. Only what is
     * actually rendered belongs here: a list's delimiter and padding, a fenced block's
     * fence character, and a thematic break's style all parse but never reach the output.
     */
    protected function blockVariant(Node $node): string
    {
        return match (true) {
            // One Heading class covers h1 through h6.
            $node instanceof Heading => 'h' . $node->getLevel(),
            // The tag, the start attribute, and whether the items render tight or loose.
            $node instanceof ListBlock => implode(':', [
                $node->getListData()->type,
                $node->getListData()->start ?? 1,
                $node->isTight() ? 'tight' : 'loose',
            ]),
            // The first info word becomes the code element's language class.
            $node instanceof FencedCode => $node->getInfoWords()[0] ?? '',
            default => '',
        };
    }

    protected function textOf(Node $node): string
    {
        return trim(StringContainerHelper::getChildText($node));
    }

    /**
     * @param list<class-string> $types
     */
    protected function isOneOf(Node $node, array $types): bool
    {
        foreach ($types as $type) {
            if ($node instanceof $type) {
                return true;
            }
        }

        return false;
    }

    protected function parse(?string $value): Document
    {
        return new MarkdownParser($this->environment)->parse($value ?? '');
    }

    protected function render(Document $document): string
    {
        return trim(new HtmlRenderer($this->environment)->renderDocument($document)->getContent());
    }

    protected function defaultEnvironment(): Environment
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);

        return $environment;
    }
}
