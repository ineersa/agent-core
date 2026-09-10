<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears still-streaming transcript blocks before an LLM retry attempt.
 *
 * Failed partial output must not remain visible when the next attempt starts.
 */
final readonly class LlmRequestRetryProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::LlmRequestRetrying->value => 'onLlmRequestRetrying',
        ];
    }

    public function onLlmRequestRetrying(TranscriptProjectionEvent $event): void
    {
        $event->state->removeActiveStreamingBlocks($event->runId());
    }
}
