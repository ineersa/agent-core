<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Diagnostics;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Platform\Diagnostics\PromptCacheRequestDiagnosticsRecorder;
use Psr\Log\LoggerInterface;

/**
 * Builds privacy-safe prompt-cache inspection reports for session:cache:inspect.
 *
 * Reads the requested parent session plus registered child artifacts from existing
 * stores only. Groups by exact (run_id, model, provider, transport, cache_family_fp)
 * and never aggregates incompatible families. Prefix diffs are local structural
 * inference — never claimed as provider cache invalidation.
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
            // Missing/corrupt child artifact is degraded to no events for that child.
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

        $this->collectRun(
            families: $families,
            runId: $entry->agentRunId,
            role: $entry->kind->value,
            events: $events,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @param list<RunEvent>                      $events
     */
    private function collectRun(array &$families, string $runId, string $role, array $events): void
    {
        $canonicalModel = $this->canonicalModel($events);

        foreach ($events as $event) {
            if (!\in_array($event->type, self::LLM_STEP_TYPES, true)) {
                continue;
            }

            $usage = $this->normalizeUsage(\is_array($event->payload['usage'] ?? null) ? $event->payload['usage'] : []);
            $diagnostics = $event->payload['request_diagnostics'] ?? null;
            if (!\is_array($diagnostics) || !array_is_list($diagnostics) || [] === $diagnostics) {
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
                    request: $this->requestRow(
                        event: $event,
                        usage: $usage,
                        mode: 'unknown',
                        promptCacheKeyPresent: null,
                        previousResponseIdPresent: null,
                        components: [],
                    ),
                    hasComponents: false,
                );
                continue;
            }

            $lastIndex = \count($diagnostics) - 1;
            foreach ($diagnostics as $index => $record) {
                if (!\is_array($record)) {
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

                // Multi-record steps (thinking-only second invoke) share one provider usage blob.
                // Attach usage only to the last logical record so family totals are not doubled.
                $requestUsage = $index === $lastIndex
                    ? $usage
                    : $this->normalizeUsage([]);

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
                    request: $this->requestRow(
                        event: $event,
                        usage: $requestUsage,
                        mode: \is_string($record['mode'] ?? null) ? $record['mode'] : 'unknown',
                        promptCacheKeyPresent: \array_key_exists('prompt_cache_key_present', $record)
                            ? (bool) $record['prompt_cache_key_present']
                            : null,
                        previousResponseIdPresent: \array_key_exists('previous_response_id_present', $record)
                            ? (bool) $record['previous_response_id_present']
                            : null,
                        components: $components,
                    ),
                    hasComponents: [] !== $components,
                );
            }
        }

        foreach ($families as $key => $family) {
            if (($family['run_id'] ?? null) !== $runId) {
                continue;
            }
            $families[$key] = $this->finalizeFamily($family);
        }
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
                $compare = PromptCacheRequestDiagnosticsRecorder::compareComponents($previousComponents, $components);
                $requests[$index]['prefix_diff'] = $this->formatPrefixDiff($compare);
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
    private function requestRow(
        RunEvent $event,
        array $usage,
        string $mode,
        ?bool $promptCacheKeyPresent,
        ?bool $previousResponseIdPresent,
        array $components,
    ): array {
        return [
            'seq' => $event->seq,
            'step_id' => \is_string($event->payload['step_id'] ?? null) ? $event->payload['step_id'] : '',
            'created_at' => $event->createdAt,
            'mode' => $mode,
            'prompt_cache_key_present' => $promptCacheKeyPresent,
            'previous_response_id_present' => $previousResponseIdPresent,
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

        // Clamp malformed telemetry so uncached never goes negative and totals stay bounded.
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
