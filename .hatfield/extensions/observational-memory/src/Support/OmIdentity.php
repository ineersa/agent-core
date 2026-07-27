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

    public static function mapLegacyRelevance(int $legacy): string
    {
        return match (true) {
            $legacy <= 24 => self::RELEVANCE_LOW,
            $legacy <= 49 => self::RELEVANCE_MEDIUM,
            $legacy <= 74 => self::RELEVANCE_HIGH,
            default => self::RELEVANCE_CRITICAL,
        };
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

    public static function compactionGenerationId(
        string $compactionRequestId,
        string $requestFingerprint,
        string $reflectorModel,
        string $reflectorSchemaVersion,
    ): string {
        return OmCanonicalJson::sha256([
            'type' => 'compaction-generation-v1',
            'compaction_request_id' => $compactionRequestId,
            'request_fingerprint' => $requestFingerprint,
            'reflector_model' => $reflectorModel,
            'reflector_schema_version' => $reflectorSchemaVersion,
        ]);
    }

    /**
     * @param array{
     *   run_id: string,
     *   required_start_seq: int,
     *   required_end_seq: int,
     *   required_watermark: int,
     *   custom_instructions: string,
     *   observer_model: string,
     *   observer_context_window: int,
     *   observer_context_window_ratio: float,
     *   renderer_version: string,
     *   observer_schema_version: string,
     *   reflector_model: string,
     *   reflector_context_window: int,
     *   reflector_context_window_ratio: float,
     *   reflector_schema_version: string,
     *   observations_max_tokens: int,
     *   reflections_max_tokens: int
     * } $parts
     */
    public static function compactionRequestFingerprint(array $parts): string
    {
        return OmCanonicalJson::sha256([
            'type' => 'compaction-request-v2',
            'run_id' => $parts['run_id'],
            'required_start_seq' => $parts['required_start_seq'],
            'required_end_seq' => $parts['required_end_seq'],
            'required_watermark' => $parts['required_watermark'],
            'custom_instructions' => $parts['custom_instructions'],
            'observer_model' => $parts['observer_model'],
            'observer_context_window' => $parts['observer_context_window'],
            'observer_context_window_ratio' => $parts['observer_context_window_ratio'],
            'renderer_version' => $parts['renderer_version'],
            'observer_schema_version' => $parts['observer_schema_version'],
            'reflector_model' => $parts['reflector_model'],
            'reflector_context_window' => $parts['reflector_context_window'],
            'reflector_context_window_ratio' => $parts['reflector_context_window_ratio'],
            'reflector_schema_version' => $parts['reflector_schema_version'],
            'observations_max_tokens' => $parts['observations_max_tokens'],
            'reflections_max_tokens' => $parts['reflections_max_tokens'],
        ]);
    }

    public static function compactionRequestId(
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        string $requestFingerprint,
    ): string {
        return OmCanonicalJson::sha256([
            'type' => 'compaction-request-id-v2',
            'run_id' => $runId,
            'required_start_seq' => $requiredStartSeq,
            'required_end_seq' => $requiredEndSeq,
            'request_fingerprint' => $requestFingerprint,
        ]);
    }
}
