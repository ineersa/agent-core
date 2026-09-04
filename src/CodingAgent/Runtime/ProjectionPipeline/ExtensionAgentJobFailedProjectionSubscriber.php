<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects extension_agent permanent failures into transcript Error blocks.
 *
 * Does not mark the main agent run failed. Uses the unwrapped extension error
 * from the runtime event payload, with a generic fallback when it is missing.
 */
final readonly class ExtensionAgentJobFailedProjectionSubscriber implements EventSubscriberInterface
{
    private const string SAFE_MESSAGE = 'Extension background job failed after retrying.';

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::ExtensionAgentJobFailed->value => 'onExtensionAgentJobFailed',
        ];
    }

    public function onExtensionAgentJobFailed(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $runId = $event->runId();

        $text = self::SAFE_MESSAGE;
        if (isset($p['message']) && \is_string($p['message']) && '' !== trim($p['message'])) {
            $text = $p['message'];
        }

        $state->addBlock(new TranscriptBlock(
            id: 'extension_agent_job_failed_'.$state->nextSeq(),
            kind: TranscriptBlockKindEnum::Error,
            runId: $runId,
            seq: $state->nextSeq(),
            text: $text,
            meta: [
                'category' => 'extension_agent',
                'reason' => (string) ($p['reason'] ?? 'retry_exhausted'),
                'handler_id' => isset($p['handler_id']) && \is_string($p['handler_id']) ? $p['handler_id'] : null,
                'job_id' => isset($p['job_id']) && \is_string($p['job_id']) ? $p['job_id'] : null,
                'retry_count' => isset($p['retry_count']) && \is_int($p['retry_count']) ? $p['retry_count'] : null,
                'attempts' => isset($p['attempts']) && \is_int($p['attempts']) ? $p['attempts'] : null,
            ],
            streaming: false,
        ));
    }
}
