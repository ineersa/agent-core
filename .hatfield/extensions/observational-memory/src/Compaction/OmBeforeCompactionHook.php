<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bounded retrieval of the current durable Librarian memory projection.
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
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
    {
        try {
            // A parent snapshot for a fork still has the parent's run identity.
            if ($this->api->isChildRun($context->runId)) {
                return BeforeCompactionHookResultDTO::continue();
            }
            $text = (new OmQueryService($this->api, $this->settings, $this->logger, $this->httpClient))->projection($context->runId);
            if (null === $text || '' === trim($text)) {
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
            // Intentional degradation: never cancel ordinary compaction on OM failure.
            $this->logger->error('om.compaction.hook_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.hook_failed',
                'run_id' => $context->runId,
                'session_id' => $context->runId,
                'exception_class' => $e::class,
            ]);

            return BeforeCompactionHookResultDTO::continue();
        }
    }
}
