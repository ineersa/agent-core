<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;

/** Invocation-local native message boundaries around the framework's reasoning/tool reassembly. */
final class AssistantStreamMessageBuilder
{
    private AssistantMessageStreamListener $listener;

    /** @var list<AssistantMessageStreamListener|Text> */
    private array $parts;

    /** @var array<string, AssistantMessageStreamListener> */
    private array $toolOwners = [];

    /** @var array<int|string, int> */
    private array $messageSlots = [];

    /** @var array<int, int> */
    private array $messageIndexes = [];

    private ?int $activeMessage = null;

    public function __construct()
    {
        $this->listener = new AssistantMessageStreamListener();
        $this->parts = [$this->listener];
    }

    public function accumulate(DeltaInterface $delta): void
    {
        if ($delta instanceof MessageStart) {
            $key = $delta->getItem()['id'] ?? $delta->getOutputIndex();
            $this->parts[] = new Text('');
            $this->activeMessage = array_key_last($this->parts);
            $this->messageSlots[$key] = $this->activeMessage;
            $this->listener = new AssistantMessageStreamListener();
            $this->parts[] = $this->listener;

            return;
        }
        if ($delta instanceof MessageComplete) {
            $item = MessageItem::fromText($delta->getContent());
            $key = $item['id'] ?? $delta->getOutputIndex();
            $slot = $this->messageSlots[$key] ?? null;
            if (null === $slot) {
                $this->parts[] = $delta->getContent();
                $slot = array_key_last($this->parts);
                $this->listener = new AssistantMessageStreamListener();
                $this->parts[] = $this->listener;
            } else {
                $this->parts[$slot] = $delta->getContent();
            }
            if (null !== $delta->getOutputIndex()) {
                $this->messageIndexes[$slot] = $delta->getOutputIndex();
            }
            $this->activeMessage = null;

            return;
        }
        if ($delta instanceof TextDelta && null !== $this->activeMessage) {
            $text = $this->parts[$this->activeMessage];
            \assert($text instanceof Text);
            $this->parts[$this->activeMessage] = new Text($text->getText().$delta->getText());

            return;
        }
        if ($delta instanceof ToolCallStart) {
            $this->toolOwners[$delta->getId()] = $this->listener;
        }
        if ($delta instanceof ToolCallComplete) {
            // A bridge can complete several calls after later message boundaries.
            // Feed each completion to the listener that reserved its original slot.
            foreach ($delta->getToolCalls() as $call) {
                ($this->toolOwners[$call->getId()] ?? $this->listener)->accumulate(new ToolCallComplete([$call]));
            }

            return;
        }
        $this->listener->accumulate($delta);
    }

    /** Returns accumulated content only; unlike StreamResult, this never drains provider IO. */
    public function getAssistantMessage(): ?AssistantMessage
    {
        $content = [];
        $indexedMessages = [];
        foreach ($this->parts as $slot => $part) {
            if ($part instanceof AssistantMessageStreamListener) {
                array_push($content, ...$part->getAssistantMessage()->getContent());
            } elseif ('' !== $part->getText() || null !== $part->getSignature()) {
                if (isset($this->messageIndexes[$slot])) {
                    $indexedMessages[$this->messageIndexes[$slot]] = $part;
                } else {
                    $content[] = $part;
                }
            }
        }
        // Terminal-only message items can precede reasoning/tools already streamed.
        // Their native output indexes, not completion arrival time, define replay order.
        // A reasoning item may span several summary parts. Its final signature closes
        // one native item, so message indexes address groups, never framework offsets.
        $groups = [];
        $thinking = [];
        foreach ($content as $part) {
            if ($part instanceof Thinking) {
                $thinking[] = $part;
                if (null !== $part->getSignature()) {
                    $groups[] = $thinking;
                    $thinking = [];
                }
                continue;
            }
            if ([] !== $thinking) {
                $groups[] = $thinking;
                $thinking = [];
            }
            $groups[] = [$part];
        }
        if ([] !== $thinking) {
            $groups[] = $thinking;
        }
        ksort($indexedMessages);
        foreach ($indexedMessages as $index => $message) {
            array_splice($groups, $index, 0, [[$message]]);
        }
        /** @var list<ContentInterface> $content */
        $content = array_merge([], ...$groups);

        return [] === $content ? null : new AssistantMessage(...$content);
    }
}
