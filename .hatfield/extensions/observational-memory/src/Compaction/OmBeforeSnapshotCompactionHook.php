<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeSnapshotCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeSnapshotCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;

/**
 * Snapshot/fork hook: instant deterministic projection of parent durable OM memory.
 *
 * Same render path as CompactRun {@see OmBeforeCompactionHook}. Empty → continue
 * (legacy model snapshot compaction). Non-empty → replaceSummary (no model call).
 * Failures cancel fail-closed. Never dispatches Observer/Reflector or writes child OM.
 */
final readonly class OmBeforeSnapshotCompactionHook implements BeforeSnapshotCompactionHookInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private OmSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function beforeSnapshotCompaction(BeforeSnapshotCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
    {
        try {
            $text = ActiveMemoryProjector::renderActive(
                $this->api,
                $this->settings,
                $this->logger,
                $context->runId,
            );
            if ('' === $text) {
                return BeforeCompactionHookResultDTO::continue();
            }

            $dto = BeforeCompactionHookResultDTO::replaceSummary($text);
            $dto->metadata = [
                'om_source' => 'observational_memory',
                'om_projection' => 'active_durable_memory',
                'om_path' => 'snapshot',
            ];

            return $dto;
        } catch (\Throwable $e) {
            $this->logger->error('om.snapshot_compaction.hook_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.snapshot_compaction.hook_failed',
                'run_id' => $context->runId,
                'exception_class' => $e::class,
            ]);

            return BeforeCompactionHookResultDTO::cancel(
                'observational_memory snapshot active-memory projection failed: '.$e::class,
            );
        }
    }
}
