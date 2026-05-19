<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects user-message events into UserMessage transcript blocks.
 */
final readonly class UserMessageProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::UserMessageSubmitted->value => 'onUserMessageSubmitted',
        ];
    }

    public function onUserMessageSubmitted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $state->addBlock(new TranscriptBlock(
            id: (string) ($p['message_id'] ?? ''),
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
        ));
    }
}
