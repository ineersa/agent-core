<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Query;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
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
    private const string ID_PATTERN = '/^[a-f0-9]{12,64}$/';

    private const int DISPLAY_ID_LEN = 12;

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

        $recordedObs = $observations->listObservationsForRun($runId);
        $recordedCount = \count($recordedObs);
        $candidate = $observations->activeCandidateSet($runId);
        $activeObs = $candidate['observations'] ?? [];
        $activeCount = \count($activeObs);
        $droppedCount = max(0, $recordedCount - $activeCount);
        $activeTokens = (int) ($candidate['token_count'] ?? 0);

        $activeGenerationId = $generations->activeGenerationId($runId);
        $visibleObsCount = 0;
        if (null !== $activeGenerationId) {
            $visibleObsCount = \count($generations->listRetainedObservationIds($activeGenerationId));
        }

        $recordedRefCount = $generations->countReflectionsForRun($runId);
        $activeReflections = $generations->listActiveReflections($runId);
        $visibleRefCount = \count($activeReflections);
        $reflectionTokens = 0;
        foreach ($activeReflections as $reflection) {
            $reflectionTokens += (int) ($reflection['token_count'] ?? 0);
        }

        $covered = $observations->contiguousCoveredEndSeq(
            $runId,
            $this->settings->rendererVersion,
            $this->settings->observerSchemaVersion,
        );
        $coverageLine = null === $covered || $covered < 1
            ? 'no events covered yet'
            : \sprintf('through event %s', $this->formatInt($covered));

        $reflectAfter = $this->settings->reflectAfterObservationTokens;
        $obsMax = $this->settings->observationsMaxTokens;

        $lines = [
            '## Observational memory',
            '',
            '### Memory',
            \sprintf(
                '- **Observations:** %s recorded / %s dropped / %s active / %s visible',
                $this->formatInt($recordedCount),
                $this->formatInt($droppedCount),
                $this->formatInt($activeCount),
                $this->formatInt($visibleObsCount),
            ),
            \sprintf(
                '- **Reflections:** %s recorded / %s visible (~%s tokens)',
                $this->formatInt($recordedRefCount),
                $this->formatInt($visibleRefCount),
                $this->formatInt($reflectionTokens),
            ),
            \sprintf('- **Coverage:** %s', $coverageLine),
            '',
            '### Activity',
            \sprintf(
                '- **Next reflection:** ~%s / %s tokens (%d%%)',
                $this->formatInt($activeTokens),
                $this->formatInt($reflectAfter),
                $this->percent($activeTokens, $reflectAfter),
            ),
            \sprintf(
                '- **Active observation pool:** ~%s / %s max tokens (%d%%)',
                $this->formatInt($activeTokens),
                $this->formatInt($obsMax),
                $this->percent($activeTokens, $obsMax),
            ),
            '- **Pipeline:** Observer → delta Reflector → bounded Dropper (async FIFO)',
            '- **Compaction:** instant projection of current durable memory (no model wait)',
            '',
            '> Durable memory state only; worker and queue liveness are not tracked here.',
        ];

        return implode("\n", $lines);
    }

    public function formatView(string $runId): string
    {
        $connection = $this->connect();
        $observations = new ObservationRepository($connection);
        $generations = new MemoryGenerationRepository($connection);

        $reflections = $generations->listActiveReflections($runId);
        $candidates = $observations->listActiveCandidateObservations($runId);

        $lines = [
            '## Reflections',
            '',
        ];

        if ([] === $reflections) {
            $lines[] = '*No reflections yet.*';
        } else {
            foreach ($reflections as $reflection) {
                $support = $this->currentRunSupportIds(
                    $observations,
                    $runId,
                    $this->decodeStringList((string) ($reflection['supporting_observation_ids_json'] ?? '[]')),
                );
                $lines[] = \sprintf(
                    '`[%s]` %s',
                    $this->displayId((string) $reflection['reflection_id']),
                    $this->condense((string) $reflection['content']),
                );
                if ([] === $support) {
                    $lines[] = '> Supports observations *(none)*';
                } else {
                    $parts = [];
                    foreach ($support as $supportId) {
                        $parts[] = \sprintf('`[%s]`', $this->displayId($supportId));
                    }
                    $lines[] = '> Supports observations '.implode(', ', $parts);
                }
                $lines[] = '';
            }
        }

        $lines[] = '## Observations';
        $lines[] = '';
        if ([] === $candidates) {
            $lines[] = '*No observations yet.*';
        } else {
            foreach ($candidates as $observation) {
                $refs = $this->currentRunSourceRefs(
                    $runId,
                    $this->decodeSourceRefs((string) ($observation['source_refs_json'] ?? '[]')),
                );
                $lines[] = \sprintf(
                    '`[%s]` %s **[%s]** %s',
                    $this->displayId((string) $observation['observation_id']),
                    (string) $observation['timestamp'],
                    (string) $observation['relevance'],
                    $this->condense((string) $observation['content']),
                );
                $lines[] = '> '.$this->formatSourcesHuman($refs);
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * Exact or unique prefix recall for one observation or reflection id in the current run.
     *
     * Accepts lowercase hex prefixes of length 12..64. Resolves at most one match
     * across observations and reflections separately; ambiguous or missing ids fail closed.
     *
     * Cooperative cancellation/deadline checkpoints run between DB stages and during
     * supporting-observation/event hydration. A single SQLite/DBAL call may still finish
     * before the next checkpoint; this method does not add process isolation.
     *
     * @return array<string, mixed>
     */
    public function recall(
        string $runId,
        string $id,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?int $timeoutSeconds = null,
        ?int $deadlineNs = null,
    ): array {
        $id = strtolower(trim($id));
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return [
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase hex string of 12 to 64 characters.',
            ];
        }

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before database open.'))) {
            return $interrupt;
        }

        $connection = $this->connect();
        $observations = new ObservationRepository($connection);
        $generations = new MemoryGenerationRepository($connection);

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before observation lookup.'))) {
            return $interrupt;
        }

        $obsMatches = $observations->findObservationsByIdPrefix($runId, $id);
        if (\count($obsMatches) > 1) {
            return [
                'ok' => false,
                'error' => 'ambiguous_id',
                'message' => 'Multiple observations match that id prefix in the current session.',
            ];
        }
        if (1 === \count($obsMatches)) {
            if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before observation event hydration.'))) {
                return $interrupt;
            }

            $observation = $obsMatches[0];
            $fullId = $observation['observation_id'];
            $refs = $this->currentRunSourceRefs(
                $runId,
                $this->decodeSourceRefs($observation['source_refs_json']),
            );

            $events = $this->loadEventsForRefs($runId, $refs, $cancellationToken, $timeoutSeconds, $deadlineNs);
            if (isset($events['cancelled']) || isset($events['timed_out'])) {
                return $events;
            }

            return [
                'ok' => true,
                'kind' => 'observation',
                'id' => $fullId,
                'content' => $observation['content'],
                'timestamp' => $observation['timestamp'],
                'relevance' => $observation['relevance'],
                'source_refs' => $refs,
                'events' => $events,
            ];
        }

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before reflection lookup.'))) {
            return $interrupt;
        }

        $refMatches = $generations->findReflectionsByIdPrefix($runId, $id);
        if (\count($refMatches) > 1) {
            return [
                'ok' => false,
                'error' => 'ambiguous_id',
                'message' => 'Multiple reflections match that id prefix in the current session.',
            ];
        }
        if ([] === $refMatches) {
            return [
                'ok' => false,
                'error' => 'not_found',
                'message' => 'No observation or reflection with that id in the current session.',
            ];
        }

        $reflection = $refMatches[0];
        $fullId = (string) $reflection['reflection_id'];
        $supportIds = $this->currentRunSupportIds(
            $observations,
            $runId,
            $this->decodeStringList((string) $reflection['supporting_observation_ids_json']),
        );
        $refs = [];
        foreach ($supportIds as $supportId) {
            if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled while hydrating supporting observations.'))) {
                return $interrupt;
            }
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

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before reflection event hydration.'))) {
            return $interrupt;
        }

        $events = $this->loadEventsForRefs($runId, $refs, $cancellationToken, $timeoutSeconds, $deadlineNs);
        if (isset($events['cancelled']) || isset($events['timed_out'])) {
            return $events;
        }

        return [
            'ok' => true,
            'kind' => 'reflection',
            'id' => $fullId,
            'content' => (string) $reflection['content'],
            'supporting_observation_ids' => $supportIds,
            'source_refs' => $refs,
            'events' => $events,
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
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function loadEventsForRefs(
        string $currentRunId,
        array $refs,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?int $timeoutSeconds = null,
        ?int $deadlineNs = null,
    ): array {
        if ([] === $refs) {
            return [];
        }

        // Never group refs by run_id as an array key: PHP coerces numeric-string
        // keys like "5" to int, which then TypeErrors on strict-typed readRange().
        // Upstream already filters to the current run; re-filter and load once.
        /** @var list<int> $seqs */
        $seqs = [];
        /** @var array<int, true> $wanted */
        $wanted = [];
        foreach ($refs as $ref) {
            if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled while collecting event refs.'))) {
                return $interrupt;
            }
            $run = (string) $ref['run_id'];
            $seq = (int) $ref['seq'];
            if ($seq < 1) {
                continue;
            }
            // Current-session enforcement: only resolve refs whose run matches the active session.
            if ($run !== $currentRunId) {
                continue;
            }
            $seqs[] = $seq;
            $wanted[$seq] = true;
        }

        if ([] === $seqs) {
            return [];
        }

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before reading session events.'))) {
            return $interrupt;
        }

        $events = [];
        $start = min($seqs);
        $end = max($seqs);
        foreach ($this->api->sessionEvents()->readRange($currentRunId, $start, $end) as $event) {
            if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled while reading session events.'))) {
                return $interrupt;
            }
            if (!$event instanceof SessionEventDTO) {
                continue;
            }
            // Defense: reject foreign or out-of-wanted events even if the reader is loose.
            if ($event->runId !== $currentRunId || !isset($wanted[$event->seq])) {
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

        usort($events, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return $events;
    }

    /**
     * @return array{cancelled: true, message: string}|array{timed_out: true, timeout_seconds: int, message: string}|null
     */
    private function interruptMap(
        ?ToolCancellationTokenInterface $cancellationToken,
        ?int $timeoutSeconds,
        ?int $deadlineNs,
        string $message,
    ): ?array {
        if (null !== $cancellationToken && $cancellationToken->isCancellationRequested()) {
            return [
                'cancelled' => true,
                'message' => $message,
            ];
        }

        if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
            return [
                'timed_out' => true,
                'timeout_seconds' => $timeoutSeconds ?? 0,
                'message' => $message,
            ];
        }

        return null;
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

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     */
    private function formatSourcesHuman(array $refs): string
    {
        if ([] === $refs) {
            return 'Sources: *(none)*';
        }

        $parts = [];
        foreach ($refs as $ref) {
            $parts[] = \sprintf('`%d`', $ref['seq']);
        }
        $label = 1 === \count($parts) ? 'event' : 'events';

        return \sprintf('Sources: %s %s', $label, implode(', ', $parts));
    }

    private function displayId(string $id): string
    {
        $id = strtolower($id);
        if (\strlen($id) <= self::DISPLAY_ID_LEN) {
            return $id;
        }

        return substr($id, 0, self::DISPLAY_ID_LEN);
    }

    private function formatInt(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    private function percent(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }

        return (int) round(($numerator / $denominator) * 100);
    }

    private function condense(string $content): string
    {
        // Collapse whitespace only — no arbitrary character cap/truncation.
        return preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
    }
}
