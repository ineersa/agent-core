<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;

/** Keeps native message boundaries around the framework's reasoning/tool reassembly. */
final readonly class AssistantStreamMessageBuilder
{
    /** @param list<DeltaInterface> $deltas */
    public function build(array $deltas): ?AssistantMessage
    {
        $listener = new AssistantMessageStreamListener();
        $parts = [$listener];
        $toolOwners = [];
        $messageSlots = [];
        $messageIndexes = [];
        $activeMessage = null;

        foreach ($deltas as $delta) {
            if ($delta instanceof MessageStart) {
                $key = $delta->getItem()['id'] ?? $delta->getOutputIndex();
                $parts[] = new Text('');
                $activeMessage = array_key_last($parts);
                $messageSlots[$key] = $activeMessage;
                $listener = new AssistantMessageStreamListener();
                $parts[] = $listener;
                continue;
            }
            if ($delta instanceof MessageComplete) {
                $item = MessageItem::fromText($delta->getContent());
                $key = $item['id'] ?? $delta->getOutputIndex();
                $slot = $messageSlots[$key] ?? null;
                if (null === $slot) {
                    $parts[] = $delta->getContent();
                    $slot = array_key_last($parts);
                    $listener = new AssistantMessageStreamListener();
                    $parts[] = $listener;
                } else {
                    $parts[$slot] = $delta->getContent();
                }
                if (null !== $delta->getOutputIndex()) {
                    $messageIndexes[$slot] = $delta->getOutputIndex();
                }
                $activeMessage = null;
                continue;
            }
            if ($delta instanceof TextDelta && null !== $activeMessage) {
                $text = $parts[$activeMessage];
                \assert($text instanceof Text);
                $parts[$activeMessage] = new Text($text->getText().$delta->getText());
                continue;
            }
            if ($delta instanceof ToolCallStart) {
                $toolOwners[$delta->getId()] = $listener;
            }
            if ($delta instanceof ToolCallComplete) {
                // A bridge can complete several calls after later message boundaries.
                // Feed each completion to the listener that reserved its original slot.
                foreach ($delta->getToolCalls() as $call) {
                    ($toolOwners[$call->getId()] ?? $listener)->accumulate(new ToolCallComplete([$call]));
                }
                continue;
            }
            $listener->accumulate($delta);
        }

        $content = [];
        $indexedMessages = [];
        foreach ($parts as $slot => $part) {
            if ($part instanceof AssistantMessageStreamListener) {
                array_push($content, ...$part->getAssistantMessage()->getContent());
            } elseif ('' !== $part->getText() || null !== $part->getSignature()) {
                if (isset($messageIndexes[$slot])) {
                    $indexedMessages[$messageIndexes[$slot]] = $part;
                } else {
                    $content[] = $part;
                }
            }
        }
        // Terminal-only message items can precede reasoning/tools already streamed.
        // Their native output indexes, not completion arrival time, define replay order.
        ksort($indexedMessages);
        foreach ($indexedMessages as $index => $message) {
            array_splice($content, $index, 0, [$message]);
        }

        return [] === $content ? null : new AssistantMessage(...$content);
    }
}
