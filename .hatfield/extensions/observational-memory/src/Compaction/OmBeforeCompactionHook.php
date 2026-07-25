<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;

/**
 * Public CompactRun hook: ensure request, dispatch scalar job, poll OM SQLite.
 *
 * Never invokes models, reads session events, renders interactions, or consumes Messenger.
 */
final readonly class OmBeforeCompactionHook implements BeforeCompactionHookInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private OmSettings $settings,
        private LoggerInterface $logger,
        private int $pollIntervalMicros = 50_000,
    ) {
    }

    public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
    {
        if (!$this->settings->enabled) {
            return BeforeCompactionHookResultDTO::continue();
        }

        if (1 !== $context->requiredStartSeq) {
            return BeforeCompactionHookResultDTO::cancel(
                'observational_memory requires requiredStartSeq=1 for session-global coverage.',
            );
        }

        try {
            $this->settings->requireReflectorModel();
            $this->settings->requireObserverModel();
        } catch (\RuntimeException $e) {
            return BeforeCompactionHookResultDTO::cancel($e->getMessage());
        }

        $requestId = $this->requestId($context);
        $requestFingerprint = $this->requestFingerprint($context);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        try {
            $paths = OmPaths::fromSettings($this->settings, $this->api->getCwd());
            $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, $this->logger);
            $repo = new CompactionRepository($connection);

            $ensured = $repo->ensureRequest(
                requestId: $requestId,
                runId: $context->runId,
                requiredStartSeq: $context->requiredStartSeq,
                requiredEndSeq: $context->requiredEndSeq,
                requiredWatermark: $context->requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                now: $now,
            );

            // Terminal request without a readable result row is an impossible durable
            // state (e.g. request marked failed/succeeded but result row missing).
            // Fail closed immediately — never redispatch and wait the full timeout.
            if ($ensured['terminal'] && null === $ensured['result']) {
                $this->logger->error('om.compaction.terminal_without_result', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.compaction.terminal_without_result',
                    'run_id' => $context->runId,
                    'request_id' => $requestId,
                    'request_status' => $ensured['status'],
                ]);

                return BeforeCompactionHookResultDTO::cancel(
                    \sprintf(
                        'observational_memory terminal request has no result row (status=%s)',
                        $ensured['status'],
                    ),
                );
            }

            if ($ensured['terminal'] && null !== $ensured['result']) {
                return $this->resultFromRow($ensured['result'], $requestId, $context);
            }

            $jobId = hash('sha256', $requestId.'|dispatch');
            $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: BuildCompactionMemoryJobHandler::HANDLER_ID,
                payload: [
                    'request_id' => $requestId,
                    'run_id' => $context->runId,
                    'required_start_seq' => $context->requiredStartSeq,
                    'required_end_seq' => $context->requiredEndSeq,
                    'request_fingerprint' => $requestFingerprint,
                    'custom_instructions' => $context->customInstructions,
                    'renderer_version' => $this->settings->rendererVersion,
                    'observer_schema_version' => $this->settings->observerSchemaVersion,
                    'reflector_schema_version' => $this->settings->reflectorSchemaVersion,
                ],
                jobId: $jobId,
                correlationId: $context->runId,
            ));

            return $this->pollForResult($repo, $requestId, $context);
        } catch (OmConflictException $e) {
            $this->logger->error('om.compaction.hook_conflict', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.hook_conflict',
                'run_id' => $context->runId,
                'request_id' => $requestId,
                'exception_class' => $e::class,
            ]);

            return BeforeCompactionHookResultDTO::cancel('observational_memory request conflict');
        } catch (\Throwable $e) {
            $this->logger->error('om.compaction.hook_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.hook_failed',
                'run_id' => $context->runId,
                'request_id' => $requestId,
                'exception_class' => $e::class,
            ]);

            return BeforeCompactionHookResultDTO::cancel(
                'observational_memory compaction dispatch/wait failed: '.$e::class,
            );
        }
    }

    private function pollForResult(
        CompactionRepository $repo,
        string $requestId,
        BeforeCompactionHookContextDTO $context,
    ): BeforeCompactionHookResultDTO {
        $deadline = microtime(true) + $this->settings->waitTimeoutSeconds;

        while (true) {
            $status = $repo->getRequestStatus($requestId);
            $result = $status['result'] ?? null;
            if (\is_array($result)) {
                $terminal = \in_array((string) ($result['status'] ?? ''), [
                    CompactionRepository::STATUS_SUCCEEDED,
                    CompactionRepository::STATUS_FAILED,
                ], true);
                if ($terminal) {
                    return $this->resultFromRow($result, $requestId, $context);
                }
            }

            if (microtime(true) >= $deadline) {
                // Do not persist timed_out terminal state: late success with same
                // request identity remains reusable on a later compaction attempt.
                $this->logger->info('om.compaction.hook_timeout', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.compaction.hook_timeout',
                    'run_id' => $context->runId,
                    'request_id' => $requestId,
                    'wait_timeout_seconds' => $this->settings->waitTimeoutSeconds,
                ]);

                return BeforeCompactionHookResultDTO::cancel(
                    \sprintf(
                        'observational_memory timed out after %ds waiting for compaction result',
                        $this->settings->waitTimeoutSeconds,
                    ),
                );
            }

            usleep($this->pollIntervalMicros);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function resultFromRow(
        array $result,
        string $requestId,
        BeforeCompactionHookContextDTO $context,
    ): BeforeCompactionHookResultDTO {
        $status = (string) ($result['status'] ?? '');
        if (CompactionRepository::STATUS_SUCCEEDED === $status) {
            $text = trim((string) ($result['replacement_text'] ?? ''));
            if ('' === $text) {
                return BeforeCompactionHookResultDTO::cancel('observational_memory returned empty replacement_text');
            }

            try {
                $provenance = $this->decodeResultProvenance($result);
            } catch (\InvalidArgumentException $e) {
                $this->logger->error('om.compaction.unsafe_result_metadata', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.compaction.unsafe_result_metadata',
                    'run_id' => $context->runId,
                    'request_id' => $requestId,
                    'exception_class' => $e::class,
                ]);

                return BeforeCompactionHookResultDTO::cancel(
                    'observational_memory result metadata is not JSON-safe for public replacement',
                );
            }

            $dto = BeforeCompactionHookResultDTO::replaceSummary($text);
            // Public metadata keeps stable request/watermark keys and namespaces
            // worker provenance under om_provenance (never raw metadata_json).
            $dto->metadata = [
                'om_request_id' => $requestId,
                'om_required_start_seq' => $context->requiredStartSeq,
                'om_required_end_seq' => $context->requiredEndSeq,
                'om_source' => 'observational_memory',
                'om_provenance' => $provenance,
            ];

            return $dto;
        }

        $code = (string) ($result['failure_code'] ?? 'failed');

        return BeforeCompactionHookResultDTO::cancel(
            'observational_memory compaction failed: '.$code,
        );
    }

    /**
     * Decode and validate persisted result metadata for public hook metadata.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function decodeResultProvenance(array $result): array
    {
        $raw = $result['metadata_json'] ?? null;
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw new \InvalidArgumentException('metadata_json must be a JSON string when present.');
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('metadata_json is not valid JSON.', previous: $e);
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('metadata_json must decode to a JSON object/array.');
        }

        // Reject list-shaped roots so public provenance stays a map of keys.
        if (array_is_list($decoded) && [] !== $decoded) {
            throw new \InvalidArgumentException('metadata_json must decode to a JSON object, not a list.');
        }

        // Validate JSON-safety (including finite floats) via the public DTO contract
        // without constructing a full result or leaking raw content into logs.
        try {
            new BeforeCompactionHookResultDTO(metadata: ['om_provenance' => $decoded]);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('metadata_json is not JSON-safe for public metadata.', previous: $e);
        }

        // $decoded is array after is_array + non-list checks; method return type is array<string, mixed>.
        return $decoded;
    }

    private function requestId(BeforeCompactionHookContextDTO $context): string
    {
        return hash('sha256', implode('|', [
            $context->runId,
            (string) $context->requiredStartSeq,
            (string) $context->requiredEndSeq,
            $this->requestFingerprint($context),
        ]));
    }

    private function requestFingerprint(BeforeCompactionHookContextDTO $context): string
    {
        $parts = $this->settings->compactionIdentityParts();
        $parts['run_id'] = $context->runId;
        $parts['required_start_seq'] = $context->requiredStartSeq;
        $parts['required_end_seq'] = $context->requiredEndSeq;
        $parts['custom_instructions'] = $context->customInstructions ?? '';

        ksort($parts);

        try {
            return hash('sha256', json_encode($parts, \JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode OM compaction request fingerprint.', previous: $e);
        }
    }
}
