<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Query;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extension-owned read/query surface for /om-status, /om-view, and recall.
 *
 * Opens OM SQLite only. Never reads Hatfield Messenger tables.
 */
final class OmQueryService
{
    private const string ID_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        private readonly ExtensionApiInterface $api,
        private readonly OmSettings $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function formatStatus(string $runId): string
    {
        $connection = $this->connect();
        $observations = new ObservationRepository($connection);
        $generations = new MemoryGenerationRepository($connection);
        $compaction = new CompactionRepository($connection);

        $covered = $observations->contiguousCoveredEndSeq(
            $runId,
            $this->settings->rendererVersion,
            $this->settings->observerSchemaVersion,
        );
        $activeGenerationId = $generations->activeGenerationId($runId);
        $reflections = $generations->listActiveReflections($runId);
        $candidate = $observations->activeCandidateSet($runId);
        $counts = $compaction->countRequestsByStatus($runId);

        $reflectionTokens = 0;
        foreach ($reflections as $reflection) {
            $reflectionTokens += (int) ($reflection['token_count'] ?? 0);
        }

        $lines = [
            'Observational memory status',
            '',
            'Topology',
            '- worker: Hatfield-managed single FIFO extension_agent',
            '- max_retries: 1',
            '- failure_transport: none',
            '',
            'Coverage',
            null === $covered
                ? '- covered_through_seq: none'
                : \sprintf('- covered_through_seq: %d', $covered),
            '  (OM contiguous watermark only; does not claim canonical completeness)',
            '',
            'Active generation',
            null === $activeGenerationId
                ? '- generation_id: none'
                : \sprintf('- generation_id: %s', $activeGenerationId),
            \sprintf(
                '- reflections: %d tokens / limit %d (count %d)',
                $reflectionTokens,
                $this->settings->reflectionsMaxTokens,
                \count($reflections),
            ),
            \sprintf(
                '- candidate observations: %d tokens / limit %d (count %d)',
                (int) ($candidate['token_count'] ?? 0),
                $this->settings->observationsMaxTokens,
                \count($candidate['observations'] ?? []),
            ),
            '',
            'Compaction requests (durable OM SQLite)',
            \sprintf('- queued: %d', $counts[CompactionRepository::STATUS_QUEUED] ?? 0),
            \sprintf('- running: %d', $counts[CompactionRepository::STATUS_RUNNING] ?? 0),
            \sprintf('- succeeded: %d', $counts[CompactionRepository::STATUS_SUCCEEDED] ?? 0),
            \sprintf('- failed: %d', $counts[CompactionRepository::STATUS_FAILED] ?? 0),
            \sprintf('- timed_out: %d', $counts[CompactionRepository::STATUS_TIMED_OUT] ?? 0),
        ];

        return implode("\n", $lines);
    }

    public function formatView(string $runId): string
    {
        $connection = $this->connect();
        $observations = new ObservationRepository($connection);
        $generations = new MemoryGenerationRepository($connection);

        $activeGenerationId = $generations->activeGenerationId($runId);
        $reflections = $generations->listActiveReflections($runId);
        $candidates = $observations->listActiveCandidateObservations($runId);

        $lines = [
            'Observational memory view',
            '',
            null === $activeGenerationId
                ? 'Active generation: none'
                : \sprintf('Active generation: %s', $activeGenerationId),
            '',
            '## Reflections',
        ];

        if ([] === $reflections) {
            $lines[] = '(none)';
        } else {
            foreach ($reflections as $reflection) {
                $support = $this->currentRunSupportIds(
                    $observations,
                    $runId,
                    $this->decodeStringList((string) ($reflection['supporting_observation_ids_json'] ?? '[]')),
                );
                $lines[] = \sprintf(
                    '- [%s] %s',
                    (string) $reflection['reflection_id'],
                    $this->condense((string) $reflection['content']),
                );
                $lines[] = \sprintf(
                    '  supporting: %s',
                    [] === $support ? '(none)' : implode(', ', $support),
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Observations';
        if ([] === $candidates) {
            $lines[] = '(none)';
        } else {
            foreach ($candidates as $observation) {
                $refs = $this->formatSourceRefs(
                    $runId,
                    (string) ($observation['source_refs_json'] ?? '[]'),
                );
                $lines[] = \sprintf(
                    '- [%s] %s [%s] %s',
                    (string) $observation['observation_id'],
                    (string) $observation['timestamp'],
                    (string) $observation['relevance'],
                    $this->condense((string) $observation['content']),
                );
                $lines[] = \sprintf('  sources: %s', $refs);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Exact recall for one observation or reflection id in the current run.
     *
     * @return array<string, mixed>
     */
    public function recall(string $runId, string $id): array
    {
        $id = strtolower(trim($id));
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return [
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase 64-character hex SHA-256.',
            ];
        }

        $connection = $this->connect();
        $observations = new ObservationRepository($connection);
        $generations = new MemoryGenerationRepository($connection);

        $observation = $observations->findObservation($runId, $id);
        if (null !== $observation) {
            $refs = $this->currentRunSourceRefs(
                $runId,
                $this->decodeSourceRefs((string) $observation['source_refs_json']),
            );

            return [
                'ok' => true,
                'kind' => 'observation',
                'id' => $id,
                'content' => (string) $observation['content'],
                'timestamp' => (string) $observation['timestamp'],
                'relevance' => (string) $observation['relevance'],
                'source_refs' => $refs,
                'events' => $this->loadEventsForRefs($runId, $refs),
            ];
        }

        $reflection = $generations->findReflection($runId, $id);
        if (null === $reflection) {
            return [
                'ok' => false,
                'error' => 'not_found',
                'message' => 'No observation or reflection with that id in the current session.',
            ];
        }

        $supportIds = $this->currentRunSupportIds(
            $observations,
            $runId,
            $this->decodeStringList((string) $reflection['supporting_observation_ids_json']),
        );
        $refs = [];
        foreach ($supportIds as $supportId) {
            $support = $observations->findObservation($runId, $supportId);
            if (null === $support) {
                continue;
            }
            foreach ($this->currentRunSourceRefs(
                $runId,
                $this->decodeSourceRefs((string) $support['source_refs_json']),
            ) as $ref) {
                $key = $ref['run_id'].':'.$ref['seq'];
                $refs[$key] = $ref;
            }
        }
        $refs = array_values($refs);
        usort($refs, static function (array $a, array $b): int {
            $byRun = strcmp($a['run_id'], $b['run_id']);
            if (0 !== $byRun) {
                return $byRun;
            }

            return $a['seq'] <=> $b['seq'];
        });

        return [
            'ok' => true,
            'kind' => 'reflection',
            'id' => $id,
            'content' => (string) $reflection['content'],
            'supporting_observation_ids' => $supportIds,
            'source_refs' => $refs,
            'events' => $this->loadEventsForRefs($runId, $refs),
        ];
    }

    private function connect(): \Doctrine\DBAL\Connection
    {
        $paths = OmPaths::fromSettings($this->settings, $this->api->getCwd());

        return OmDatabaseFactory::connectAndMigrate($paths->databasePath, $this->logger);
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>
     */
    private function loadEventsForRefs(string $currentRunId, array $refs): array
    {
        if ([] === $refs) {
            return [];
        }

        /** @var array<string, list<int>> $byRun */
        $byRun = [];
        /** @var array<string, true> $wanted */
        $wanted = [];
        foreach ($refs as $ref) {
            $run = (string) $ref['run_id'];
            $seq = (int) $ref['seq'];
            if ($seq < 1) {
                continue;
            }
            // Current-session enforcement: only resolve refs whose run matches the active session.
            if ($run !== $currentRunId) {
                continue;
            }
            $byRun[$run][] = $seq;
            $wanted[$run.':'.$seq] = true;
        }

        $events = [];
        foreach ($byRun as $runId => $seqs) {
            $start = min($seqs);
            $end = max($seqs);
            foreach ($this->api->sessionEvents()->readRange($runId, $start, $end) as $event) {
                if (!$event instanceof SessionEventDTO) {
                    continue;
                }
                $key = $event->runId.':'.$event->seq;
                if (!isset($wanted[$key])) {
                    continue;
                }
                $events[] = [
                    'run_id' => $event->runId,
                    'seq' => $event->seq,
                    'type' => $event->type,
                    'created_at' => $event->createdAt,
                    'payload' => $event->payload,
                ];
            }
        }

        usort($events, static function (array $a, array $b): int {
            $byRun = strcmp($a['run_id'], $b['run_id']);
            if (0 !== $byRun) {
                return $byRun;
            }

            return $a['seq'] <=> $b['seq'];
        });

        return $events;
    }

    /**
     * @return list<array{run_id: string, seq: int}>
     */
    private function decodeSourceRefs(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $runId = isset($item['run_id']) && \is_string($item['run_id'])
                ? $item['run_id']
                : (isset($item['runId']) && \is_string($item['runId']) ? $item['runId'] : '');
            $seq = isset($item['seq']) && is_numeric($item['seq']) ? (int) $item['seq'] : 0;
            if ('' === $runId || $seq < 1) {
                continue;
            }
            $out[] = ['run_id' => $runId, 'seq' => $seq];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (\is_string($item) && '' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function formatSourceRefs(string $runId, string $json): string
    {
        $refs = $this->currentRunSourceRefs($runId, $this->decodeSourceRefs($json));
        if ([] === $refs) {
            return '(none)';
        }

        $parts = [];
        foreach ($refs as $ref) {
            $parts[] = \sprintf('(%s,%d)', $ref['run_id'], $ref['seq']);
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int}>
     */
    private function currentRunSourceRefs(string $runId, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if ($ref['run_id'] !== $runId) {
                continue;
            }
            $out[] = $ref;
        }

        return $out;
    }

    /**
     * @param list<string> $supportIds
     *
     * @return list<string>
     */
    private function currentRunSupportIds(
        ObservationRepository $observations,
        string $runId,
        array $supportIds,
    ): array {
        $out = [];
        $seen = [];
        foreach ($supportIds as $supportId) {
            if (isset($seen[$supportId])) {
                continue;
            }
            $seen[$supportId] = true;
            if (null === $observations->findObservation($runId, $supportId)) {
                continue;
            }
            $out[] = $supportId;
        }

        return $out;
    }

    private function condense(string $content): string
    {
        // Collapse whitespace only — no arbitrary character cap/truncation.
        return preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
    }
}
