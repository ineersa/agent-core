<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Resolves the effective context token count for the next provider prompt
 * using the latest provider-measured input/prompt tokens as a baseline
 * plus an estimated delta for messages appended after that measurement.
 *
 * Provider input_tokens/prompt_tokens measure the prompt BEFORE the
 * assistant output.  Messages added after the measured prompt (assistant
 * text, tool results, new user messages) must be accounted for before
 * deciding whether the next prompt exceeds compact_after_tokens.
 *
 * Walk backward through llm_step_completed / llm_step_aborted events to
 * find the latest provider measurement, then estimate the delta from the
 * point where the measured prompt ends to the end of the current message
 * list.
 *
 * Used by auto-compaction trigger policy:
 *  - no provider measurement = no auto-compaction
 *  - effective tokens = latest input_tokens + CompactionTokenEstimator
 *    delta for unmeasured messages
 *  - estimator is used ONLY for the post-measurement delta, never as the
 *    whole trigger baseline
 */
final class ProviderContextUsageResolver
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly CompactionTokenEstimator $tokenEstimator,
    ) {
    }

    /**
     * Returns the effective context token count for the next LLM prompt,
     * or null when no provider measurement exists yet.
     *
     * Baseline = latest provider input_tokens/prompt_tokens.
     * Delta = estimator output for messages appended after the measured
     *          prompt (assistant output, tool results, user messages).
     *
     * @param list<AgentMessage> $currentMessages the current RunState->messages
     */
    public function getEffectiveContextTokens(string $runId, array $currentMessages): ?int
    {
        $events = $this->eventStore->allFor($runId);

        for ($i = \count($events) - 1; $i >= 0; --$i) {
            $event = $events[$i];

            if (
                RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type
            ) {
                continue;
            }

            $usage = $event->payload['usage'] ?? [];

            $inputTokens = $usage['input_tokens']
                ?? $usage['prompt_tokens']
                ?? null;

            if (!\is_int($inputTokens) || $inputTokens <= 0) {
                continue;
            }

            // For llm_step_aborted: the aborted assistant message is NOT
            // appended to RunState->messages (LlmStepResultHandler aborted
            // path skips the append).  Since there is no assistant message
            // to anchor on for delta estimation, return provider input only.
            if (RunEventTypeEnum::LlmStepAborted->value === $event->type) {
                return $inputTokens;
            }

            // llm_step_completed: find the assistant message that was
            // appended by this handler in the current message list, then
            // estimate tokens for messages from that point forward.
            $assistantPayload = $event->payload['assistant_message'] ?? null;
            if (!\is_array($assistantPayload)) {
                // No assistant_message payload — fall back to provider input only.
                return $inputTokens;
            }

            $matchIndex = $this->findAssistantMessageIndex($currentMessages, $assistantPayload);

            if (null === $matchIndex) {
                // Matching failed — do NOT estimate the whole conversation.
                // Fall back to provider input only.
                return $inputTokens;
            }

            // Delta = estimator tokens for messages from the matched
            // assistant message through the end.  The assistant message
            // itself is in the delta because provider input_tokens
            // measured the prompt BEFORE it.
            $delta = 0;
            for ($j = $matchIndex; $j < \count($currentMessages); ++$j) {
                $delta += $this->tokenEstimator->estimateMessageTokens($currentMessages[$j]);
            }

            return $inputTokens + $delta;
        }

        return null;
    }

    /**
     * Walk backward through current messages to find the message that
     * corresponds to the assistant_message payload from the event.
     *
     * Matches on role='assistant' and equivalent text content (the
     * assistant_message payload is produced by AgentMessageNormalizer
     * from the same Symfony AI AssistantMessage that produced the
     * state message, so text content parts are identical).
     *
     * @param list<AgentMessage>   $messages
     * @param array<string, mixed> $assistantPayload
     */
    private function findAssistantMessageIndex(array $messages, array $assistantPayload): ?int
    {
        $payloadText = $this->extractFirstText($assistantPayload['content'] ?? null);

        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $msg = $messages[$i];

            if ('assistant' !== $msg->role) {
                continue;
            }

            $msgText = $this->extractFirstText($msg->content);

            // Text comparison: both null (tool-only assistant) or
            // identical text strings.
            if ($payloadText !== $msgText) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Extract the text from the first text-type content part.
     *
     * Accepts both full parts with explicit `type: text` (event payloads,
     * normalised messages) and minimal parts with only `text` (test helpers,
     * fromPayload consumers).  This is intentionally lenient so the delta
     * matching is not overly sensitive to content-part shape.
     *
     * @param mixed $content either null or an array of content parts
     */
    private function extractFirstText(mixed $content): ?string
    {
        if (!\is_array($content)) {
            return null;
        }

        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }

            // Explicit text-type part (event payloads, normalised messages).
            if (($part['type'] ?? null) === 'text') {
                $text = $part['text'] ?? null;

                return \is_string($text) ? $text : null;
            }

            // Fallback: part without explicit type but with a text key.
            // AgentMessage::fromPayload() consumers (test helpers) may
            // produce content parts like ['text' => '...'].
            if (!isset($part['type']) && isset($part['text'])) {
                return \is_string($part['text']) ? $part['text'] : null;
            }
        }

        return null;
    }
}
