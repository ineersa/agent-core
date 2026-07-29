<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Support;

/**
 * Exact observation / chunk / set identity formulas (task §D/§F/§J).
 */
final class OmIdentity
{
    public const string RELEVANCE_LOW = 'low';

    public const string RELEVANCE_MEDIUM = 'medium';

    public const string RELEVANCE_HIGH = 'high';

    public const string RELEVANCE_CRITICAL = 'critical';

    /**
     * @return list<string>
     */
    public static function relevanceValues(): array
    {
        return [
            self::RELEVANCE_LOW,
            self::RELEVANCE_MEDIUM,
            self::RELEVANCE_HIGH,
            self::RELEVANCE_CRITICAL,
        ];
    }

    public static function relevanceRank(string $relevance): int
    {
        return match ($relevance) {
            self::RELEVANCE_LOW => 0,
            self::RELEVANCE_MEDIUM => 1,
            self::RELEVANCE_HIGH => 2,
            self::RELEVANCE_CRITICAL => 3,
            default => 1,
        };
    }

    public static function backfillTimestamp(string $createdAt): string
    {
        $candidate = substr(str_replace('T', ' ', $createdAt), 0, 16);
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $candidate)) {
            return $candidate;
        }

        return '1970-01-01 00:00';
    }

    /**
     * @param list<array{run_id: string, seq: int}> $sourceRefs
     *
     * @return list<array{run_id: string, seq: int}>
     */
    public static function normalizeSourceRefs(array $sourceRefs): array
    {
        $unique = [];
        foreach ($sourceRefs as $ref) {
            $runId = (string) ($ref['run_id'] ?? '');
            $seq = (int) ($ref['seq'] ?? 0);
            if ('' === $runId || $seq < 1) {
                continue;
            }
            $unique[$runId.'|'.$seq] = ['run_id' => $runId, 'seq' => $seq];
        }

        $out = array_values($unique);
        usort($out, static function (array $a, array $b): int {
            $byRun = strcmp($a['run_id'], $b['run_id']);
            if (0 !== $byRun) {
                return $byRun;
            }

            return $a['seq'] <=> $b['seq'];
        });

        return $out;
    }

    /**
     * @param list<array{run_id: string, seq: int}> $sourceRefs
     */
    public static function observationId(
        string $runId,
        string $observerSchemaVersion,
        string $timestamp,
        string $content,
        array $sourceRefs,
    ): string {
        $normalized = self::normalizeSourceRefs($sourceRefs);

        return OmCanonicalJson::sha256([
            'type' => 'observation-v1',
            'run_id' => $runId,
            'observer_schema_version' => $observerSchemaVersion,
            'timestamp' => $timestamp,
            'content' => $content,
            'source_refs' => $normalized,
        ]);
    }

    /**
     * @param list<string> $observationIds
     */
    public static function observationSetHash(string $runId, array $observationIds): string
    {
        $ids = array_values(array_unique(array_map(static fn (string $id): string => $id, $observationIds)));
        sort($ids, \SORT_STRING);

        return OmCanonicalJson::sha256([
            'type' => 'observation-set-v1',
            'run_id' => $runId,
            'observation_ids' => $ids,
        ]);
    }

    /**
     * @param list<string> $supportingObservationIds
     */
    public static function reflectionId(
        string $runId,
        string $reflectorSchemaVersion,
        string $content,
        array $supportingObservationIds,
    ): string {
        $support = array_values(array_unique(array_map(static fn (string $id): string => $id, $supportingObservationIds)));
        sort($support, \SORT_STRING);

        return OmCanonicalJson::sha256([
            'type' => 'reflection-v1',
            'run_id' => $runId,
            'reflector_schema_version' => $reflectorSchemaVersion,
            'content' => $content,
            'supporting_observation_ids' => $support,
        ]);
    }

    /**
     * @param list<array{run_id: string, seq: int, kind: string, rendered_text: string}> $sourceBlocks
     */
    public static function fullSourceDigest(array $sourceBlocks): string
    {
        $canonical = [];
        foreach ($sourceBlocks as $block) {
            $canonical[] = [
                'run_id' => $block['run_id'],
                'seq' => $block['seq'],
                'kind' => $block['kind'],
                'rendered_text' => $block['rendered_text'],
            ];
        }

        return OmCanonicalJson::sha256($canonical);
    }

    public static function chunkKey(
        string $runId,
        int $sourceStartSeq,
        int $sourceEndSeq,
        string $rendererVersion,
        string $observerSchemaVersion,
        string $sourceDigest,
        int $partCount,
    ): string {
        return OmCanonicalJson::sha256([
            'type' => 'observer-chunk-v1',
            'run_id' => $runId,
            'source_start_seq' => $sourceStartSeq,
            'source_end_seq' => $sourceEndSeq,
            'renderer_version' => $rendererVersion,
            'observer_schema_version' => $observerSchemaVersion,
            'source_digest' => $sourceDigest,
            'part_count' => $partCount,
        ]);
    }

    public static function partDigest(string $renderedPartBytes): string
    {
        return OmCanonicalJson::sha256Bytes($renderedPartBytes);
    }

    public static function coverageKey(string $chunkKey, int $partIndex, string $partDigest): string
    {
        return OmCanonicalJson::sha256([
            'type' => 'observer-chunk-part-v1',
            'chunk_key' => $chunkKey,
            'part_index' => $partIndex,
            'part_digest' => $partDigest,
        ]);
    }

    public static function thresholdGenerationId(
        string $runId,
        ?string $priorActiveGenerationId,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
    ): string {
        return OmCanonicalJson::sha256([
            'type' => 'threshold-generation-v1',
            'run_id' => $runId,
            'prior_active_generation_id' => $priorActiveGenerationId,
            'observation_set_hash' => $observationSetHash,
            'reflector_model' => $reflectorModel,
            'reflector_schema_version' => $reflectorSchemaVersion,
        ]);
    }
}
