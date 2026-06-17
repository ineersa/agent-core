<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects run lifecycle terminal events (completed, failed, cancelled)
 * into transcript blocks.
 *
 * Contributes to {@see TranscriptProjector} via Symfony EventDispatcher.
 */
final readonly class RunLifecycleProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::RunFailed->value => 'onRunFailed',
            RuntimeEventTypeEnum::RunCompleted->value => 'onRunCompleted',
        ];
    }

    /**
     * No block for clean completion.
     */
    public function onRunCompleted(TranscriptProjectionEvent $event): void
    {
        // Intentionally blank: run completed needs no block.
    }

    public function onRunFailed(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $runId = $event->runId();

        $error = (string) ($p['error'] ?? '');
        $messageType = (string) ($p['message_type'] ?? '');

        // Build a user-visible error message.
        $parts = ['Run failed'];
        if ('' !== $error) {
            // Strip class prefix from the error if it's a fully-qualified
            // exception message like "Namespace\Exception: message"
            $displayError = $error;
            if ('' !== $messageType) {
                $displayError = \sprintf('Processing %s: %s', $messageType, $error);
            }
            $parts[] = $displayError;
        }

        $text = implode(': ', $parts);

        $state->removeActiveStreamingBlocks($runId);

        $state->addBlock(new TranscriptBlock(
            id: 'run_failed_'.$state->nextSeq(),
            kind: TranscriptBlockKindEnum::Error,
            runId: $runId,
            seq: $state->nextSeq(),
            text: $text,
            meta: [
                'error' => $error,
                'message_type' => $messageType,
                'reason' => (string) ($p['reason'] ?? ''),
            ],
            streaming: false,
        ));
    }
}
