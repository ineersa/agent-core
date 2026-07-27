<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * Packs source blocks into envelope-sized chunks with UTF-8 part splits.
 */
final class OmChunkPacker
{
    /**
     * @param list<array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * }> $blocks
     * @param list<array{id: string, line: string, tokens: int, timestamp?: string, relevance?: string}> $memoryObservations
     * @param list<array{id: string, line: string, tokens: int}>                                         $memoryReflections
     *
     * @return list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }>
     */
    public function pack(
        string $runId,
        string $rendererVersion,
        string $observerSchemaVersion,
        array $blocks,
        array $memoryReflections,
        array $memoryObservations,
        int $envelopeTokens,
        string $systemPrompt,
        string $toolSchemaEstimateText,
        string $localTimeFallback,
        int $fixedOverheadTokens,
    ): array {
        if ([] === $blocks) {
            return [];
        }

        $trimmedMemory = $this->trimMemory($memoryReflections, $memoryObservations, $envelopeTokens, $fixedOverheadTokens);
        $memoryText = $this->renderMemorySections($trimmedMemory['reflections'], $trimmedMemory['observations']);
        $memoryTokens = OmTokenEstimator::estimate($memoryText);
        $timestampLine = 'Current local time fallback: '.$localTimeFallback;
        $timestampTokens = OmTokenEstimator::estimate($timestampLine);
        $budgetForSource = max(64, $envelopeTokens - $fixedOverheadTokens - $memoryTokens - $timestampTokens);

        $chunks = [];
        $current = [];
        $currentTokens = 0;

        foreach ($blocks as $block) {
            $blockTokens = OmTokenEstimator::estimate($block['rendered_text']);
            if ([] !== $current && $currentTokens + $blockTokens > $budgetForSource) {
                foreach ($this->finalizeGroup(
                    $runId,
                    $rendererVersion,
                    $observerSchemaVersion,
                    $current,
                    $memoryText,
                    $timestampLine,
                    $systemPrompt,
                    $toolSchemaEstimateText,
                    $budgetForSource,
                ) as $part) {
                    $chunks[] = $part;
                }
                $current = [];
                $currentTokens = 0;
            }

            if ([] === $current && $blockTokens > $budgetForSource) {
                foreach ($this->splitOversizedBlock(
                    $runId,
                    $rendererVersion,
                    $observerSchemaVersion,
                    $block,
                    $memoryText,
                    $timestampLine,
                    $systemPrompt,
                    $toolSchemaEstimateText,
                    $budgetForSource,
                ) as $part) {
                    $chunks[] = $part;
                }
                continue;
            }

            $current[] = $block;
            $currentTokens += $blockTokens;
        }

        if ([] !== $current) {
            foreach ($this->finalizeGroup(
                $runId,
                $rendererVersion,
                $observerSchemaVersion,
                $current,
                $memoryText,
                $timestampLine,
                $systemPrompt,
                $toolSchemaEstimateText,
                $budgetForSource,
            ) as $part) {
                $chunks[] = $part;
            }
        }

        return $chunks;
    }

    /**
     * @param list<array{id: string, line: string, tokens: int}>                                         $reflections
     * @param list<array{id: string, line: string, tokens: int, timestamp?: string, relevance?: string}> $observations
     *
     * @return array{reflections: list<array{id: string, line: string, tokens: int}>, observations: list<array{id: string, line: string, tokens: int, timestamp?: string, relevance?: string}>}
     */
    private function trimMemory(array $reflections, array $observations, int $envelopeTokens, int $fixedOverheadTokens): array
    {
        // Reserve at least ~40% of remaining envelope after fixed overhead for source.
        $memoryBudget = max(64, (int) floor(($envelopeTokens - $fixedOverheadTokens) * 0.55));
        $total = 0;
        foreach ($reflections as $r) {
            $total += $r['tokens'];
        }
        foreach ($observations as $o) {
            $total += $o['tokens'];
        }
        if ($total <= $memoryBudget) {
            return ['reflections' => $reflections, 'observations' => $observations];
        }

        // Drop observations first: oldest timestamp, then lowest relevance, then stable id.
        usort($observations, static function (array $a, array $b): int {
            $byTs = strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
            if (0 !== $byTs) {
                return $byTs;
            }
            $byRel = OmIdentity::relevanceRank((string) ($a['relevance'] ?? 'medium'))
                <=> OmIdentity::relevanceRank((string) ($b['relevance'] ?? 'medium'));
            if (0 !== $byRel) {
                return $byRel;
            }

            return strcmp($a['id'], $b['id']);
        });

        while ($total > $memoryBudget && [] !== $observations) {
            $dropped = array_shift($observations);
            $total -= $dropped['tokens'];
        }

        // Then reflections in reverse generation position (end of list first).
        while ($total > $memoryBudget && [] !== $reflections) {
            $dropped = array_pop($reflections);
            $total -= $dropped['tokens'];
        }

        return ['reflections' => $reflections, 'observations' => $observations];
    }

    /**
     * @param list<array{id: string, line: string, tokens: int}> $reflections
     * @param list<array{id: string, line: string, tokens: int}> $observations
     */
    private function renderMemorySections(array $reflections, array $observations): string
    {
        $lines = [];
        $lines[] = 'CURRENT REFLECTIONS:';
        if ([] === $reflections) {
            $lines[] = '(none yet)';
        } else {
            foreach ($reflections as $reflection) {
                $lines[] = $reflection['line'];
            }
        }
        $lines[] = '';
        $lines[] = 'CURRENT OBSERVATIONS:';
        if ([] === $observations) {
            $lines[] = '(none yet)';
        } else {
            foreach ($observations as $observation) {
                $lines[] = $observation['line'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * }> $blocks
     *
     * @return list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }>
     */
    private function finalizeGroup(
        string $runId,
        string $rendererVersion,
        string $observerSchemaVersion,
        array $blocks,
        string $memoryText,
        string $timestampLine,
        string $systemPrompt,
        string $toolSchemaEstimateText,
        int $budgetForSource,
    ): array {
        $sourceText = implode("\n\n", array_map(static fn (array $b): string => $b['rendered_text'], $blocks));
        $sourceTokens = OmTokenEstimator::estimate($sourceText);
        if ($sourceTokens <= $budgetForSource) {
            return [$this->buildPart(
                $runId,
                $rendererVersion,
                $observerSchemaVersion,
                $blocks,
                $sourceText,
                1,
                1,
                $memoryText,
                $timestampLine,
            )];
        }

        // Should not happen for multi-block groups (caller packs greedily); split as one logical range.
        $parts = $this->utf8Parts($sourceText, $budgetForSource);
        $out = [];
        $partCount = \count($parts);
        foreach ($parts as $index => $partText) {
            $out[] = $this->buildPart(
                $runId,
                $rendererVersion,
                $observerSchemaVersion,
                $blocks,
                $partText,
                $index + 1,
                $partCount,
                $memoryText,
                $timestampLine,
            );
        }

        return $out;
    }

    /**
     * @param array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * } $block
     *
     * @return list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }>
     */
    private function splitOversizedBlock(
        string $runId,
        string $rendererVersion,
        string $observerSchemaVersion,
        array $block,
        string $memoryText,
        string $timestampLine,
        string $systemPrompt,
        string $toolSchemaEstimateText,
        int $budgetForSource,
    ): array {
        $parts = $this->utf8Parts($block['rendered_text'], $budgetForSource);
        $out = [];
        $partCount = \count($parts);
        foreach ($parts as $index => $partText) {
            $out[] = $this->buildPart(
                $runId,
                $rendererVersion,
                $observerSchemaVersion,
                [$block],
                $partText,
                $index + 1,
                $partCount,
                $memoryText,
                $timestampLine,
            );
        }

        return $out;
    }

    /**
     * @param list<array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * }> $blocks
     *
     * @return array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }
     */
    private function buildPart(
        string $runId,
        string $rendererVersion,
        string $observerSchemaVersion,
        array $blocks,
        string $partText,
        int $partIndex,
        int $partCount,
        string $memoryText,
        string $timestampLine,
    ): array {
        $start = min(array_map(static fn (array $b): int => $b['seq'], $blocks));
        $end = max(array_map(static fn (array $b): int => $b['seq'], $blocks));
        $sourceBlocks = [];
        $refs = [];
        foreach ($blocks as $block) {
            $sourceBlocks[] = [
                'run_id' => $block['run_id'],
                'seq' => $block['seq'],
                'kind' => $block['kind'],
                'rendered_text' => $block['rendered_text'],
            ];
            foreach ($block['source_refs'] as $ref) {
                $refs[] = $ref;
            }
        }
        $sourceDigest = OmIdentity::fullSourceDigest($sourceBlocks);
        $chunkKey = OmIdentity::chunkKey(
            $runId,
            $start,
            $end,
            $rendererVersion,
            $observerSchemaVersion,
            $sourceDigest,
            $partCount,
        );
        $partDigest = OmIdentity::partDigest($partText);
        $coverageKey = OmIdentity::coverageKey($chunkKey, $partIndex, $partDigest);
        $userMessage = $memoryText
            ."\n\nNEW SOURCE-ADDRESSED CONVERSATION CHUNK:\n"
            .$partText
            ."\n\n"
            .$timestampLine;

        return [
            'source_start_seq' => $start,
            'source_end_seq' => $end,
            'part_index' => $partIndex,
            'part_count' => $partCount,
            'source_digest' => $sourceDigest,
            'part_digest' => $partDigest,
            'chunk_key' => $chunkKey,
            'coverage_key' => $coverageKey,
            'rendered_part' => $partText,
            'source_refs' => OmIdentity::normalizeSourceRefs($refs),
            'user_message' => $userMessage,
            'token_estimate' => OmTokenEstimator::estimate($userMessage),
        ];
    }

    /**
     * @return list<string>
     */
    private function utf8Parts(string $text, int $budgetTokens): array
    {
        $budgetTokens = max(32, $budgetTokens);
        // Approx chars from tokens * 4, leave headroom.
        $maxChars = max(64, $budgetTokens * 4);
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $maxChars) {
            return [$text];
        }

        $parts = [];
        $offset = 0;
        while ($offset < $length) {
            $parts[] = mb_substr($text, $offset, $maxChars, 'UTF-8');
            $offset += $maxChars;
        }

        return $parts;
    }
}
