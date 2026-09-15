<?php

namespace TestMonitor\Revisable\Diffing;

use Jfcherng\Diff\SequenceMatcher;
use TestMonitor\Revisable\Enums\ChangeType;

/**
 * Diffs two strings word by word, keeping whitespace as its own tokens so nothing is lost.
 */
final class WordDiffer
{
    /**
     * @return list<Segment>
     */
    public function diff(string $before, string $after): array
    {
        $beforeTokens = $this->tokenize($before);
        $afterTokens = $this->tokenize($after);

        $segments = [];

        foreach (new SequenceMatcher($beforeTokens, $afterTokens)->getOpcodes() as $opcode) {
            [$operation, $beforeStart, $beforeEnd, $afterStart, $afterEnd] = $opcode;

            $segments = [...$segments, ...$this->segmentsFor(
                $operation,
                implode('', array_slice($beforeTokens, $beforeStart, $beforeEnd - $beforeStart)),
                implode('', array_slice($afterTokens, $afterStart, $afterEnd - $afterStart)),
            )];
        }

        return $segments;
    }

    /**
     * Turn one opcode into segments; a replacement becomes a removal then an addition.
     *
     * @return list<Segment>
     */
    protected function segmentsFor(int $operation, string $beforeText, string $afterText): array
    {
        return match ($operation) {
            SequenceMatcher::OP_EQ => [new Segment(ChangeType::Kept, $beforeText)],
            SequenceMatcher::OP_DEL => [new Segment(ChangeType::Removed, $beforeText)],
            SequenceMatcher::OP_INS => [new Segment(ChangeType::Added, $afterText)],
            default => [
                new Segment(ChangeType::Removed, $beforeText),
                new Segment(ChangeType::Added, $afterText),
            ],
        };
    }

    /**
     * Split into alternating word and whitespace tokens, so no character is lost.
     *
     * @return list<string>
     */
    protected function tokenize(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
