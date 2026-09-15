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
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use TestMonitor\Revisable\Contracts\Differ;
use TestMonitor\Revisable\Diffing\Markdown\BlockChange;
use TestMonitor\Revisable\Diffing\Markdown\ChangeRenderer;
use TestMonitor\Revisable\Diffing\Markdown\FormattingChange;
use TestMonitor\Revisable\Diffing\Markdown\FormattingRenderer;
use TestMonitor\Revisable\Diffing\Markdown\InlineChange;
use TestMonitor\Revisable\Diffing\Support\ArrayAligner;
use TestMonitor\Revisable\Enums\ChangeType;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;

/**
 * Diffs markdown through its CommonMark AST, marking changes in the tree and letting
 * CommonMark render both sides, so the markup is valid by construction.
 */
final class MarkdownDiffer implements Differ
{
    /**
     * Node types never diffed inline. Table ships with GFM, so it may be absent at runtime.
     *
     * @var list<class-string>
     */
    protected const array ATOMIC = [
        FencedCode::class,
        IndentedCode::class,
        ThematicBreak::class,
        HtmlBlock::class,
        Table::class,
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
     *                                      initialised yet, since the differ registers its own renderers on it.
     */
    public function __construct(
        ?Environment $environment = null,
        protected bool $inlineSingleParagraph = false,
        protected WordDiffer $words = new WordDiffer,
    ) {
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
     * The field status, correcting for markdown that parses to no blocks at all.
     *
     * @param list<BlockDiff> $blocks
     */
    protected function statusFor(?string $before, ?string $after, array $blocks): ChangeType
    {
        $status = FieldDiff::statusFor($before, $after, $blocks);

        $bothHeldAValue = $before !== null && $before !== '' && $after !== null && $after !== '';

        // Markdown can parse to no blocks at all (whitespace, or only a link definition),
        // and an empty block list would otherwise read as unchanged.
        return $blocks === [] && $bothHeldAValue && $before !== $after
            ? ChangeType::Changed
            : $status;
    }

    /**
     * Align two nodes' children and diff each pair, emitting one flat list in document order.
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
        if (! $before instanceof Node) {
            return [$this->markWholeBlock($after, ChangeType::Added)];
        }

        if (! $after instanceof Node) {
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
     * @return list<BlockDiff>
     */
    protected function diffAtomic(Node $before, Node $after): array
    {
        // Untrimmed, unlike textOf(): a code block renders its surrounding whitespace verbatim.
        if (StringContainerHelper::getChildText($before) === StringContainerHelper::getChildText($after)) {
            return [new BlockDiff(ChangeType::Kept)];
        }

        return [
            $this->markWholeBlock($before, ChangeType::Removed),
            $this->markWholeBlock($after, ChangeType::Added),
        ];
    }

    /**
     * Diff a leaf block word by word, writing the result into both trees as inline markers.
     */
    protected function diffLeaf(Node $before, Node $after): BlockDiff
    {
        $beforeContainers = $this->stringContainers($before);
        $afterContainers = $this->stringContainers($after);

        // Built from the containers themselves, so the segments stay in step with the nodes.
        $beforeText = $this->literalsOf($beforeContainers);
        $afterText = $this->literalsOf($afterContainers);

        if ($beforeText === $afterText) {
            // Same words, so only the inline structure can have changed.
            if ($this->inlineShapeOf($before) === $this->inlineShapeOf($after)) {
                return new BlockDiff(ChangeType::Kept);
            }

            // Nothing was inserted or removed, so there is no segment to carry a marker.
            $this->markFormatting($beforeContainers, $afterContainers);

            return new BlockDiff(ChangeType::Changed);
        }

        $segments = $this->words->diff($beforeText, $afterText);

        $this->applySegments($beforeContainers, $segments, ChangeType::Removed);
        $this->applySegments($afterContainers, $segments, ChangeType::Added);

        return new BlockDiff(ChangeType::Changed, $segments);
    }

    /**
     * Mark a whole node as added or removed and report it as one block.
     */
    protected function markWholeBlock(Node $node, ChangeType $type): BlockDiff
    {
        $text = $this->textOf($node);

        // The words inside stay unmarked: block level says it all, and marking them too
        // would nest one marker inside another.
        $node instanceof ListItem
            ? $this->markListItem($node, $type)
            : $this->wrapBlock($node, $type);

        return new BlockDiff($type, [new Segment($type, $text)]);
    }

    /**
     * Put a block-level marker in a node's place and move the node inside it.
     */
    protected function wrapBlock(Node $node, ChangeType $type): void
    {
        $marker = new BlockChange($type);

        $node->replaceWith($marker);
        $marker->appendChild($node);
    }

    /**
     * Mark a list item from the inside, leaving the item where the parser put it.
     */
    protected function markListItem(ListItem $item, ChangeType $type): void
    {
        // A marker in the item's own place would put <ins> straight inside <ul>, and would
        // push the item out of the list's tight rendering.
        foreach ($item->children() as $child) {
            $this->isOneOf($child, self::ATOMIC) || $this->isOneOf($child, self::CONTAINERS)
                ? $this->wrapBlock($child, $type)
                : $this->markInlineContent($child, $type);
        }
    }

    /**
     * Move a leaf block's inline children under one marker, leaving the block untouched.
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
     * Mark only the runs whose formatting actually changed.
     *
     * @param list<AbstractStringContainer> $beforeContainers
     * @param list<AbstractStringContainer> $afterContainers
     */
    protected function markFormatting(array $beforeContainers, array $afterContainers): void
    {
        $runs = $this->formattingRuns($beforeContainers);

        $offset = 0;

        foreach ($afterContainers as $container) {
            $literal = $container->getLiteral();
            $path = $this->formattingPathOf($container);
            $length = strlen($literal);

            $pieces = [];
            $consumed = 0;

            while ($consumed < $length) {
                [$before, $available] = $this->runAt($runs, $offset + $consumed);
                $take = min($available, $length - $consumed);

                // A differing path means this run sits inside different inline nodes now.
                $pieces[] = [substr($literal, $consumed, $take), $before !== $path];

                $consumed += $take;
            }

            $offset += $length;

            $this->rewriteFormatting($container, $this->mergeFormattingPieces($pieces));
        }
    }

    /**
     * The before side as a flat run of [byte length, formatting path] pairs.
     *
     * @param list<AbstractStringContainer> $containers
     * @return list<array{int, string}>
     */
    protected function formattingRuns(array $containers): array
    {
        $runs = [];

        foreach ($containers as $container) {
            $runs[] = [strlen($container->getLiteral()), $this->formattingPathOf($container)];
        }

        return $runs;
    }

    /**
     * The formatting path covering $offset, and how many bytes of it remain from there.
     *
     * @param list<array{int, string}> $runs
     * @return array{string, int}
     */
    protected function runAt(array $runs, int $offset): array
    {
        $cursor = 0;

        foreach ($runs as [$length, $path]) {
            if ($offset < $cursor + $length) {
                return [$path, $cursor + $length - $offset];
            }

            $cursor += $length;
        }

        return ['', PHP_INT_MAX];
    }

    /**
     * The chain of inline nodes a run sits inside, which is what its formatting means.
     */
    protected function formattingPathOf(Node $node): string
    {
        $path = [];
        $parent = $node->parent();

        while ($parent instanceof AbstractInline) {
            $path[] = $parent::class . ':' . $this->inlineVariant($parent);
            $parent = $parent->parent();
        }

        return implode('>', array_reverse($path));
    }

    /**
     * Join neighbouring pieces sharing a verdict, so one span yields one marker.
     *
     * @param list<array{string, bool}> $pieces
     * @return list<array{string, bool}>
     */
    protected function mergeFormattingPieces(array $pieces): array
    {
        $merged = [];

        foreach ($pieces as [$text, $changed]) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last][1] === $changed) {
                $merged[$last][0] .= $text;

                continue;
            }

            $merged[] = [$text, $changed];
        }

        return $merged;
    }

    /**
     * Rebuild a container so its reformatted spans sit inside a marker.
     *
     * @param list<array{string, bool}> $pieces
     */
    protected function rewriteFormatting(AbstractStringContainer $container, array $pieces): void
    {
        if ($pieces === [] || ! collect($pieces)->contains(fn (array $piece) => $piece[1])) {
            return;
        }

        // A code span or raw inline carries markup that splitting would destroy, so it is
        // marked whole rather than rebuilt from plain text.
        if (! $container instanceof Text) {
            $marker = new FormattingChange;

            $container->replaceWith($marker);
            $marker->appendChild($container);

            return;
        }

        $replacements = [];

        foreach ($pieces as [$text, $changed]) {
            if ($text === '') {
                continue;
            }

            $node = new Text($text);

            if (! $changed) {
                $replacements[] = $node;

                continue;
            }

            $marker = new FormattingChange;
            $marker->appendChild($node);

            $replacements[] = $marker;
        }

        $first = array_shift($replacements);

        $container->replaceWith($first);

        $previous = $first;

        foreach ($replacements as $replacement) {
            $previous->insertAfter($replacement);
            $previous = $replacement;
        }
    }

    /**
     * Wrap the runs belonging to $side in inline markers, rebuilding each container in place.
     *
     * @param list<AbstractStringContainer> $containers
     * @param list<Segment> $segments
     */
    protected function applySegments(array $containers, array $segments, ChangeType $side): void
    {
        $stream = $this->streamFor($segments, $side);

        // Walked by character position, not Segment's token offsets: one token can span
        // several containers ("A**b**c"), so offsets cannot express a container boundary.
        $index = 0;
        $offset = 0;

        foreach ($containers as $container) {
            // Byte arithmetic is safe here because every cut lands on a boundary that already
            // existed: a parser container edge, or a token edge from WordDiffer's /u tokenizer.
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
     * The segments belonging to one side, in order. Their text concatenates to that side's input.
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
     * The node a piece renders as: bare text when kept, wrapped in a marker otherwise.
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
     * A block's inline types and variants, for telling a formatting-only change from none.
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
     * Inline state that changes the rendered output without showing in the class or text.
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
            // A ticked checkbox renders a checked attribute, but leaves no text behind.
            $node instanceof TaskListItemMarker => $node->isChecked() ? 'checked' : 'unchecked',
            default => '',
        };
    }

    /**
     * The alignment key for a block: its type plus its normalised text.
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
     * Block state that changes the rendered output without showing in the class or text.
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
        return array_any($types, fn ($type) => $node instanceof $type);
    }

    protected function parse(?string $value): Document
    {
        return new MarkdownParser($this->environment)->parse($value ?? '');
    }

    /**
     * Lift a lone paragraph's contents up, so a single-paragraph entry renders inline.
     */
    protected function unwrapLoneParagraph(Document $document): void
    {
        $children = iterator_to_array($document->children(), false);

        if (count($children) !== 1) {
            return;
        }

        // A wholly added or removed entry sits inside a marker node, so look through it.
        $paragraph = $children[0] instanceof BlockChange
            ? $children[0]->firstChild()
            : $children[0];

        if (! $paragraph instanceof Paragraph) {
            return;
        }

        foreach (iterator_to_array($paragraph->children(), false) as $child) {
            $paragraph->insertBefore($child);
        }

        $paragraph->detach();
    }

    protected function render(Document $document): string
    {
        if ($this->inlineSingleParagraph) {
            $this->unwrapLoneParagraph($document);
        }

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
