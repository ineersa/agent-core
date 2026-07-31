<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;

/**
 * Instant deterministic projection of current durable OM memory.
 *
 * Serves CompactRun (paired coverage watermark present) and snapshot/fork
 * compaction (watermark null/null) via the same public before-compaction hook.
 * Pi-style: no Observer catch-up, Reflector, extension_agent job, poll, fingerprint,
 * or request/result row. Consolidation finishing later affects a later compaction.
 */
final readonly class OmBeforeCompactionHook implements BeforeCompactionHookInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private OmSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
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
                'om_has_coverage_watermark' => $context->hasCoverageWatermark(),
            ];

            return $dto;
        } catch (\Throwable $e) {
            // Privacy-safe cancel only; dispatcher would also fail-closed, but local
            // cancel keeps an OM-specific reason without raw exception text.
            $this->logger->error('om.compaction.hook_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.hook_failed',
                'run_id' => $context->runId,
                'exception_class' => $e::class,
            ]);

            return BeforeCompactionHookResultDTO::cancel(
                'observational_memory active-memory projection failed: '.$e::class,
            );
        }
    }
}
