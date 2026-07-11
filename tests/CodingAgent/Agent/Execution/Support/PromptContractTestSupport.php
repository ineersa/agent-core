<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Shared assertions for GF-05 prompt/message contract RED specifications.
 *
 * @internal
 */
final class PromptContractTestSupport
{
    public static function messageText(AgentMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $block) {
            if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<array{role: string, source: ?string, text: string}>
     */
    public static function summarizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = [
                'role' => $message->role,
                'source' => $message->metadata['source'] ?? null,
                'text' => self::messageText($message),
            ];
        }

        return $out;
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<array{role: string, text: string}>
     */
    public static function providerVisibleSummaries(array $messages): array
    {
        $bag = (new AgentMessageConverter())->toMessageBag($messages);
        $out = [];
        foreach ($bag->getMessages() as $message) {
            $text = method_exists($message, 'asText') ? $message->asText() : '';
            if ('' === $text && method_exists($message, 'getContent')) {
                $content = $message->getContent();
                if (is_iterable($content)) {
                    foreach ($content as $part) {
                        if (is_object($part) && method_exists($part, 'asText')) {
                            $text .= $part->asText();
                        }
                    }
                }
            }
            $out[] = [
                'role' => $message->getRole(),
                'text' => $text,
            ];
        }

        return $out;
    }

    /**
     * @param list<AgentMessage> $canonical
     * @param list<AgentMessage> $fromRunStartedPayload
     */
    public static function assertCanonicalMatchesRunStartedMessages(array $canonical, array $fromRunStartedPayload): void
    {
        self::assertMessageListsEquivalent($canonical, $fromRunStartedPayload);
    }

    /**
     * @param list<AgentMessage> $canonical
     * @param list<AgentMessage> $fromStartRunInput
     */
    public static function assertCanonicalMatchesStartRunInput(array $canonical, array $fromStartRunInput): void
    {
        self::assertMessageListsEquivalent($canonical, $fromStartRunInput);
    }

    /**
     * @param list<AgentMessage> $left
     * @param list<AgentMessage> $right
     */
    public static function assertMessageListsEquivalent(array $left, array $right): void
    {
        if (\count($left) !== \count($right)) {
            throw new \PHPUnit\Framework\AssertionFailedError(\sprintf(
                'Message count mismatch: %d vs %d',
                \count($left),
                \count($right),
            ));
        }

        foreach ($left as $index => $message) {
            $other = $right[$index];
            if ($message->role !== $other->role) {
                throw new \PHPUnit\Framework\AssertionFailedError(\sprintf(
                    'Message[%d] role mismatch: %s vs %s',
                    $index,
                    $message->role,
                    $other->role,
                ));
            }

            $leftSource = $message->metadata['source'] ?? null;
            $rightSource = $other->metadata['source'] ?? null;
            if ($leftSource !== $rightSource) {
                throw new \PHPUnit\Framework\AssertionFailedError(\sprintf(
                    'Message[%d] metadata.source mismatch: %s vs %s',
                    $index,
                    (string) $leftSource,
                    (string) $rightSource,
                ));
            }

            if (self::messageText($message) !== self::messageText($other)) {
                throw new \PHPUnit\Framework\AssertionFailedError(\sprintf(
                    'Message[%d] text mismatch for role=%s source=%s',
                    $index,
                    $message->role,
                    (string) $leftSource,
                ));
            }
        }
    }

    /**
     * @param list<array{role: string, source: ?string, text: string}> $summaries
     *
     * @return list<string>
     */
    public static function roleSourceKeys(array $summaries): array
    {
        return array_map(
            static fn (array $row): string => $row['role'].':'.($row['source'] ?? ''),
            $summaries,
        );
    }

    public static function findRunStartedEvent(EventStoreInterface $eventStore, string $runId): ?RunEvent
    {
        foreach ($eventStore->allFor($runId) as $event) {
            if ('run_started' === $event->type) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<AgentMessage>
     */
    public static function messagesFromRunStartedPayload(array $payload): array
    {
        $nested = $payload['payload'] ?? [];
        $raw = $nested['messages'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $messages = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $messages[] = new AgentMessage(
                role: (string) ($item['role'] ?? ''),
                content: \is_array($item['content'] ?? null) ? $item['content'] : [],
                metadata: \is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
            );
        }

        return $messages;
    }

    public static function systemPromptFromRunStartedPayload(array $payload): string
    {
        $nested = $payload['payload'] ?? [];

        return (string) ($nested['system_prompt'] ?? $nested['systemPrompt'] ?? '');
    }

    public static function providerBag(array $messages): MessageBag
    {
        return (new AgentMessageConverter())->toMessageBag($messages);
    }
}
