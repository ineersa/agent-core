<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Diagnostics;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\LoggerInterface;

/**
 * Builds privacy-safe prompt-cache inspection reports for session:cache:inspect.
 *
 * Joins CodingAgent diagnostics sidecars with existing parent/child canonical
 * llm_step_* usage events. Groups by exact (run_id, model, provider, transport,
 * cache_family_fp) and never aggregates incompatible families.
 */
final class SessionPromptCacheInspectionService
{
    private const array LLM_STEP_TYPES = [
        'llm_step_completed',
        'llm_step_failed',
        'llm_step_aborted',
    ];

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly SessionRunEventStore $parentEventStore,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunEventStoreFactory $childEventStoreFactory,
        private readonly PromptCacheDiagnosticsStore $diagnosticsStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     found: bool,
     *     session_id?: string,
     *     notice?: string,
     *     families?: list<array<string, mixed>>
     * }
     */
    public function inspect(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId || null === $this->hatfieldSessionStore->findSession($sessionId)) {
            return [
                'found' => false,
                'notice' => \sprintf('Session "%s" not found.', $sessionId),
            ];
        }

        /** @var array<string, array<string, mixed>> $families */
        $families = [];

        $this->collectRun(
            families: $families,
            runId: $sessionId,
            role: 'parent',
            events: $this->parentEventStore->allFor($sessionId),
            diagnostics: $this->diagnosticsStore->readForRun($sessionId),
        );

        foreach ($this->artifactRegistry->list($sessionId) as $entry) {
            $this->collectChild($families, $sessionId, $entry);
        }

        return [
            'found' => true,
            'session_id' => $sessionId,
            'families' => array_values($families),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $families
     */
    private function collectChild(array &$families, string $parentRunId, AgentArtifactEntryDTO $entry): void
    {
        try {
            $childStore = $this->childEventStoreFactory->create(
                $parentRunId,
                $entry->agentRunId,
                $entry->artifactId,
            );
            $events = $childStore->allFor($entry->agentRunId);
        } catch (\Throwable $e) {
            $this->logger->warning('session.cache_inspect.child_events_unavailable', [
                'component' => 'session_prompt_cache_inspection',
                'event_type' => 'session.cache_inspect.child_events_unavailable',
                'parent_run_id' => $parentRunId,
                'run_id' => $entry->agentRunId,
                'artifact_id' => $entry->artifactId,
                'exception_class' => $e::class,
            ]);
            $events = [];
        }

        try {
            $diagnostics = $this->diagnosticsStore->readForRun($entry->agentRunId);
        } catch (\Throwable $e) {
            $this->logger->warning('session.cache_inspect.child_diagnostics_unavailable', [
                'component' => 'session_prompt_cache_inspection',
                'event_type' => 'session.cache_inspect.child_diagnostics_unavailable',
                'parent_run_id' => $parentRunId,
                'run_id' => $entry->agentRunId,
                'artifact_id' => $entry->artifactId,
                'exception_class' => $e::class,
            ]);
            $diagnostics = [];
        }

        $this->collectRun(
            families: $families,
            runId: $entry->agentRunId,
            role: $entry->kind->value,
            events: $events,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @param list<RunEvent>                      $events
     * @param list<array<string, mixed>>          $diagnostics
     */
    private function collectRun(array &$families, string $runId, string $role, array $events, array $diagnostics): void
    {
        $canonicalModel = $this->canonicalModel($events);
        $joined = $this->joinByStep($events, $diagnostics);

        foreach ($joined as $row) {
            $usage = $row['usage'];
            $record = $row['diagnostic'];
            $event = $row['event'];

            if (null === $record) {
                $this->appendRequest(
                    families: $families,
                    familyKeyParts: [
                        'run_id' => $runId,
                        'model' => $canonicalModel ?? 'unknown',
                        'provider' => 'unknown',
                        'transport' => 'unknown',
                        'cache_family_fp' => 'none',
                    ],
                    role: $role,
                    request: $this->requestRow($event, $usage, []),
                    hasComponents: false,
                );
                continue;
            }

            $provider = \is_string($record['provider'] ?? null) && '' !== $record['provider']
                ? $record['provider']
                : 'unknown';
            $transport = \is_string($record['transport'] ?? null) && '' !== $record['transport']
                ? $record['transport']
                : 'unknown';
            $cacheFamilyFp = \is_string($record['cache_family_fp'] ?? null) && '' !== $record['cache_family_fp']
                ? $record['cache_family_fp']
                : 'none';
            $model = $canonicalModel
                ?? (\is_string($record['model'] ?? null) && '' !== $record['model'] ? $record['model'] : 'unknown');
            $components = \is_array($record['components'] ?? null) && array_is_list($record['components'])
                ? $record['components']
                : [];

            $this->appendRequest(
                families: $families,
                familyKeyParts: [
                    'run_id' => $runId,
                    'model' => $model,
                    'provider' => $provider,
                    'transport' => $transport,
                    'cache_family_fp' => $cacheFamilyFp,
                ],
                role: $role,
                request: $this->requestRow($event, $usage, $components),
                hasComponents: [] !== $components,
            );
        }

        foreach ($families as $key => $family) {
            if (($family['run_id'] ?? null) !== $runId) {
                continue;
            }
            $families[$key] = $this->finalizeFamily($family);
        }
    }

    /**
     * Join sidecar diagnostics to llm_step_* events by exact step_id and append order.
     * Multiple diagnostics on one step (thinking-only second invoke) attach usage only to the last.
     *
     * @param list<RunEvent>             $events
     * @param list<array<string, mixed>> $diagnostics
     *
     * @return list<array{
     *     event: ?RunEvent,
     *     diagnostic: ?array<string, mixed>,
     *     usage: array{
     *         input_tokens: int,
     *         output_tokens: int,
     *         thinking_tokens: int,
     *         cache_read_tokens: int,
     *         cache_write_tokens: int,
     *         uncached_tokens: int,
     *         uncached_available: bool,
     *         cache_ratio: ?float,
     *         cost: float
     *     }
     * }>
     */
    private function joinByStep(array $events, array $diagnostics): array
    {
        /** @var array<string, list<RunEvent>> $eventsByStep */
        $eventsByStep = [];
        $orderedStepIds = [];
        foreach ($events as $event) {
            if (!\in_array($event->type, self::LLM_STEP_TYPES, true)) {
                continue;
            }
            $stepId = \is_string($event->payload['step_id'] ?? null) ? $event->payload['step_id'] : '';
            if (!isset($eventsByStep[$stepId])) {
                $eventsByStep[$stepId] = [];
                $orderedStepIds[] = $stepId;
            }
            $eventsByStep[$stepId][] = $event;
        }

        /** @var array<string, list<array<string, mixed>>> $diagsByStep */
        $diagsByStep = [];
        foreach ($diagnostics as $record) {
            $stepId = \is_string($record['step_id'] ?? null) ? $record['step_id'] : '';
            $diagsByStep[$stepId][] = $record;
        }

        $joined = [];
        $seenSteps = [];

        foreach ($orderedStepIds as $stepId) {
            $seenSteps[$stepId] = true;
            $stepEvents = $eventsByStep[$stepId] ?? [];
            $stepDiags = $diagsByStep[$stepId] ?? [];
            $event = $stepEvents[array_key_last($stepEvents)] ?? null;
            $usage = $this->normalizeUsage(
                null !== $event && \is_array($event->payload['usage'] ?? null) ? $event->payload['usage'] : [],
            );

            if ([] === $stepDiags) {
                $joined[] = [
                    'event' => $event,
                    'diagnostic' => null,
                    'usage' => $usage,
                ];
                continue;
            }

            $lastDiagIndex = \count($stepDiags) - 1;
            foreach ($stepDiags as $index => $diag) {
                $joined[] = [
                    'event' => $event,
                    'diagnostic' => $diag,
                    // Multi-record steps share one provider usage blob — attach only to last.
                    'usage' => $index === $lastDiagIndex ? $usage : $this->normalizeUsage([]),
                ];
            }
        }

        // Diagnostics without matching llm_step events (should be rare) still appear for prefix analysis.
        foreach ($diagsByStep as $stepId => $stepDiags) {
            if (isset($seenSteps[$stepId])) {
                continue;
            }
            foreach ($stepDiags as $diag) {
                $joined[] = [
                    'event' => null,
                    'diagnostic' => $diag,
                    'usage' => $this->normalizeUsage([]),
                ];
            }
        }

        return $joined;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function canonicalModel(array $events): ?string
    {
        foreach ($events as $event) {
            if ('run_started' !== $event->type) {
                continue;
            }
            $metadata = $event->payload['metadata'] ?? null;
            if (\is_array($metadata) && \is_string($metadata['model'] ?? null) && '' !== $metadata['model']) {
                return $metadata['model'];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @param array{
     *     run_id: string,
     *     model: string,
     *     provider: string,
     *     transport: string,
     *     cache_family_fp: string
     * } $familyKeyParts
     * @param array<string, mixed> $request
     */
    private function appendRequest(
        array &$families,
        array $familyKeyParts,
        string $role,
        array $request,
        bool $hasComponents,
    ): void {
        $key = implode("\0", [
            $familyKeyParts['run_id'],
            $familyKeyParts['model'],
            $familyKeyParts['provider'],
            $familyKeyParts['transport'],
            $familyKeyParts['cache_family_fp'],
        ]);

        if (!isset($families[$key])) {
            $families[$key] = [
                'role' => $role,
                'run_id' => $familyKeyParts['run_id'],
                'model' => $familyKeyParts['model'],
                'provider' => $familyKeyParts['provider'],
                'transport' => $familyKeyParts['transport'],
                'cache_family_fp' => $familyKeyParts['cache_family_fp'],
                'request_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'thinking_tokens' => 0,
                'cache_read_tokens' => 0,
                'cache_write_tokens' => 0,
                'uncached_tokens' => 0,
                'uncached_available' => false,
                'cache_ratio' => null,
                'cost' => 0.0,
                'prefix_attribution_available' => false,
                'requests' => [],
            ];
        }

        $families[$key]['requests'][] = $request;
        ++$families[$key]['request_count'];
        $families[$key]['input_tokens'] += (int) $request['input_tokens'];
        $families[$key]['output_tokens'] += (int) $request['output_tokens'];
        $families[$key]['thinking_tokens'] += (int) $request['thinking_tokens'];
        $families[$key]['cache_read_tokens'] += (int) $request['cache_read_tokens'];
        $families[$key]['cache_write_tokens'] += (int) $request['cache_write_tokens'];
        $families[$key]['cost'] += (float) $request['cost'];
        if (true === $request['uncached_available']) {
            $families[$key]['uncached_available'] = true;
            $families[$key]['uncached_tokens'] += (int) $request['uncached_tokens'];
        }
        if ($hasComponents) {
            $families[$key]['prefix_attribution_available'] = true;
        }
    }

    /**
     * @param array<string, mixed> $family
     *
     * @return array<string, mixed>
     */
    private function finalizeFamily(array $family): array
    {
        $requests = $family['requests'] ?? [];
        if (!\is_array($requests)) {
            $requests = [];
        }

        $previousComponents = null;
        foreach ($requests as $index => $request) {
            if (!\is_array($request)) {
                continue;
            }
            $components = \is_array($request['components'] ?? null) ? $request['components'] : [];
            if (null !== $previousComponents && [] !== $components) {
                $requests[$index]['prefix_diff'] = $this->formatPrefixDiff(
                    $this->compareComponents($previousComponents, $components),
                );
            }
            if ([] !== $components) {
                $previousComponents = $components;
            }
            unset($requests[$index]['components']);
        }

        $family['requests'] = array_values($requests);
        $input = (int) ($family['input_tokens'] ?? 0);
        $read = (int) ($family['cache_read_tokens'] ?? 0);
        $family['cache_ratio'] = $input > 0 ? $read / $input : null;
        if (true !== ($family['uncached_available'] ?? false)) {
            $family['uncached_tokens'] = 0;
        }

        return $family;
    }

    /**
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $current
     *
     * @return array{
     *     common_prefix_len: int,
     *     first_diff: array{
     *         index: int,
     *         kind: 'changed'|'inserted'|'removed',
     *         previous: ?array<string, mixed>,
     *         current: ?array<string, mixed>
     *     }|null
     * }
     */
    private function compareComponents(array $previous, array $current): array
    {
        $max = max(\count($previous), \count($current));
        $common = 0;
        for ($i = 0; $i < $max; ++$i) {
            $prev = $previous[$i] ?? null;
            $curr = $current[$i] ?? null;
            if (null === $prev && null === $curr) {
                break;
            }
            if (null === $prev) {
                return [
                    'common_prefix_len' => $common,
                    'first_diff' => [
                        'index' => $i,
                        'kind' => 'inserted',
                        'previous' => null,
                        'current' => $curr,
                    ],
                ];
            }
            if (null === $curr) {
                return [
                    'common_prefix_len' => $common,
                    'first_diff' => [
                        'index' => $i,
                        'kind' => 'removed',
                        'previous' => $prev,
                        'current' => null,
                    ],
                ];
            }
            if (($prev['hmac'] ?? null) === ($curr['hmac'] ?? null)
                && ($prev['section'] ?? null) === ($curr['section'] ?? null)
                && ($prev['type'] ?? null) === ($curr['type'] ?? null)
                && ($prev['role'] ?? null) === ($curr['role'] ?? null)
                && ($prev['name'] ?? null) === ($curr['name'] ?? null)
            ) {
                ++$common;
                continue;
            }

            return [
                'common_prefix_len' => $common,
                'first_diff' => [
                    'index' => $i,
                    'kind' => 'changed',
                    'previous' => $prev,
                    'current' => $curr,
                ],
            ];
        }

        return [
            'common_prefix_len' => $common,
            'first_diff' => null,
        ];
    }

    /**
     * @param array{
     *     common_prefix_len: int,
     *     first_diff: array{
     *         index: int,
     *         kind: 'changed'|'inserted'|'removed',
     *         previous: ?array<string, mixed>,
     *         current: ?array<string, mixed>
     *     }|null
     * } $compare
     *
     * @return array<string, mixed>
     */
    private function formatPrefixDiff(array $compare): array
    {
        $diff = $compare['first_diff'];
        if (null === $diff) {
            return [
                'kind' => 'stable',
                'common_prefix_len' => $compare['common_prefix_len'],
            ];
        }

        $previous = \is_array($diff['previous'] ?? null) ? $diff['previous'] : [];
        $current = \is_array($diff['current'] ?? null) ? $diff['current'] : [];

        return [
            'kind' => $diff['kind'],
            'index' => $diff['index'],
            'common_prefix_len' => $compare['common_prefix_len'],
            'previous_section' => $previous['section'] ?? null,
            'previous_type' => $previous['type'] ?? null,
            'previous_role' => $previous['role'] ?? null,
            'previous_name' => $previous['name'] ?? null,
            'previous_bytes' => $previous['bytes'] ?? null,
            'current_section' => $current['section'] ?? null,
            'current_type' => $current['type'] ?? null,
            'current_role' => $current['role'] ?? null,
            'current_name' => $current['name'] ?? null,
            'current_bytes' => $current['bytes'] ?? null,
        ];
    }

    /**
     * @param array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     thinking_tokens: int,
     *     cache_read_tokens: int,
     *     cache_write_tokens: int,
     *     uncached_tokens: int,
     *     uncached_available: bool,
     *     cache_ratio: ?float,
     *     cost: float
     * } $usage
     * @param list<array<string, mixed>> $components
     *
     * @return array<string, mixed>
     */
    private function requestRow(?RunEvent $event, array $usage, array $components): array
    {
        return [
            'seq' => null === $event ? '' : $event->seq,
            'step_id' => null !== $event && \is_string($event->payload['step_id'] ?? null)
                ? $event->payload['step_id']
                : '',
            'created_at' => null === $event ? null : $event->createdAt,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'thinking_tokens' => $usage['thinking_tokens'],
            'cache_read_tokens' => $usage['cache_read_tokens'],
            'cache_write_tokens' => $usage['cache_write_tokens'],
            'uncached_tokens' => $usage['uncached_tokens'],
            'uncached_available' => $usage['uncached_available'],
            'cache_ratio' => $usage['cache_ratio'],
            'cost' => $usage['cost'],
            'components' => $components,
        ];
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     thinking_tokens: int,
     *     cache_read_tokens: int,
     *     cache_write_tokens: int,
     *     uncached_tokens: int,
     *     uncached_available: bool,
     *     cache_ratio: ?float,
     *     cost: float
     * }
     */
    private function normalizeUsage(array $usage): array
    {
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        $thinking = max(0, (int) ($usage['thinking_tokens'] ?? 0));
        $hasCacheTelemetry = \array_key_exists('cache_read_tokens', $usage)
            || \array_key_exists('cached_tokens', $usage)
            || \array_key_exists('cache_creation_tokens', $usage);

        $cacheRead = 0;
        if (\array_key_exists('cache_read_tokens', $usage)) {
            $cacheRead = max(0, (int) $usage['cache_read_tokens']);
        } elseif (\array_key_exists('cached_tokens', $usage)) {
            $cacheRead = max(0, (int) $usage['cached_tokens']);
        }
        $cacheWrite = max(0, (int) ($usage['cache_creation_tokens'] ?? 0));

        if ($cacheRead > $input) {
            $cacheRead = $input;
        }
        if ($cacheRead + $cacheWrite > $input) {
            $cacheWrite = max(0, $input - $cacheRead);
        }

        $uncachedAvailable = $hasCacheTelemetry;
        $uncached = $uncachedAvailable ? max(0, $input - $cacheRead - $cacheWrite) : 0;
        $cost = max(0.0, (float) ($usage['cost'] ?? 0.0));

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'thinking_tokens' => $thinking,
            'cache_read_tokens' => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'uncached_tokens' => $uncached,
            'uncached_available' => $uncachedAvailable,
            'cache_ratio' => $input > 0 ? $cacheRead / $input : null,
            'cost' => $cost,
        ];
    }
}
