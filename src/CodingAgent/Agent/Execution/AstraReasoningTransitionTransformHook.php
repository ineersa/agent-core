<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;

/**
 * Re-applies durable Astra reasoning-transition markers onto surviving history.
 *
 * New transitions are recorded by {@see AstraReasoningTransitionRequestHook}
 * after SessionAwareModelResolver decides an update is required. Compaction and
 * resume clear the session baseline so discarded switches are not kept.
 *
 * Runs before {@see \Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook} so
 * durable MESSAGE_KEY values are derived from original history text and survive
 * later LLM-facing rewrites (including OutputCap saved-path filenames).
 */
final readonly class AstraReasoningTransitionTransformHook implements TransformContextHookInterface
{
    public function __construct(
        private ModelSelectionService $selectionService,
        private HatfieldModelCatalog $catalog,
        private HatfieldSessionStore $sessionMetadataStore,
        private ?RunOperationalStatusReaderInterface $statusReader = null,
        private ?RunStartedMetadataReader $childMetadataReader = null,
    ) {
    }

    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null, ?string $runId = null): array
    {
        if (null === $runId || '' === $runId || [] === $messages) {
            return $messages;
        }

        // Compaction summarization uses the same runId with explicit model /
        // thinking overrides. Do not stamp chat transitions onto that path.
        $status = $this->statusReader?->findOperationalStatus($runId)?->status;
        if (RunStatus::Compacting === $status) {
            return $this->stripAllTransitions($messages);
        }

        $modelRef = $this->resolveChatModel($runId);
        if (null === $modelRef) {
            return $this->stripAllTransitions($messages);
        }

        $byKey = [];
        foreach ($this->sessionMetadataStore->listReasoningTransitions($runId, $modelRef->toString()) as $transition) {
            $byKey[$transition['message_key']] = $transition['effort'];
        }

        $out = [];
        foreach ($messages as $index => $message) {
            $key = self::messageKeyInHistory($messages, $index);
            $effort = null !== $key ? ($byKey[$key] ?? null) : null;
            $metadata = $message->metadata;
            unset($metadata[CodexReasoningTransitionMetadata::KEY], $metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY]);

            if (null !== $key) {
                $metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] = $key;
            }

            if (\is_string($effort) && '' !== $effort) {
                $metadata[CodexReasoningTransitionMetadata::KEY] = $effort;
            }

            if ($metadata === $message->metadata) {
                $out[] = $message;

                continue;
            }

            $out[] = new AgentMessage(
                role: $message->role,
                content: $message->content,
                timestamp: $message->timestamp,
                name: $message->name,
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                details: $message->details,
                isError: $message->isError,
                metadata: $metadata,
            );
        }

        return $out;
    }

    /**
     * @param non-empty-string $role
     */
    public static function messageKeyFor(string $role, ?string $toolCallId, string $text, int $occurrence): ?string
    {
        if (!\in_array($role, ['user', 'tool'], true)) {
            return null;
        }

        if ($occurrence < 0) {
            return null;
        }

        return hash('sha256', $role.'|'.($toolCallId ?? '').'|'.$text.'|'.$occurrence);
    }

    /**
     * Stable key for a message among siblings that share role/tool/text.
     *
     * Uses the same text extraction as AgentMessageConverter / OutputCap so
     * request-hook lookups against converted MessageBag entries stay aligned.
     *
     * @param list<AgentMessage> $messages
     */
    public static function messageKeyInHistory(array $messages, int $index): ?string
    {
        if (!isset($messages[$index])) {
            return null;
        }

        $message = $messages[$index];
        $existing = $message->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null;
        if (\is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $role = self::anchorRole($message);
        if (null === $role) {
            return null;
        }

        $text = self::canonicalText($message);
        $occurrence = 0;
        for ($i = 0; $i < $index; ++$i) {
            $prior = $messages[$i];
            if (self::anchorRole($prior) !== $role || ($prior->toolCallId ?? null) !== ($message->toolCallId ?? null)) {
                continue;
            }
            if (self::canonicalText($prior) === $text) {
                ++$occurrence;
            }
        }

        return self::messageKeyFor($role, $message->toolCallId, $text, $occurrence);
    }

    public static function canonicalText(AgentMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }

            $text = $part['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        $combined = implode("\n", $parts);
        if ('' !== $combined) {
            return $message->isCustomRole()
                ? \sprintf('[%s] %s', $message->role, $combined)
                : $combined;
        }

        if ('tool' === $message->role && null !== $message->details) {
            return self::stringify($message->details);
        }

        return '';
    }

    private static function anchorRole(AgentMessage $message): ?string
    {
        if ('tool' === $message->role) {
            return 'tool';
        }

        if ('user' === $message->role || $message->isCustomRole()) {
            return 'user';
        }

        return null;
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function stripAllTransitions(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!\array_key_exists(CodexReasoningTransitionMetadata::KEY, $message->metadata)
                && !\array_key_exists(CodexReasoningTransitionMetadata::MESSAGE_KEY, $message->metadata)) {
                $out[] = $message;

                continue;
            }

            $metadata = $message->metadata;
            unset($metadata[CodexReasoningTransitionMetadata::KEY], $metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY]);
            $out[] = new AgentMessage(
                role: $message->role,
                content: $message->content,
                timestamp: $message->timestamp,
                name: $message->name,
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                details: $message->details,
                isError: $message->isError,
                metadata: $metadata,
            );
        }

        return $out;
    }

    private function resolveChatModel(string $sessionId): ?AiModelReference
    {
        // Child agent runs keep their fixed definition model and must not
        // participate in the parent chat baseline/transition ledger.
        if (!ctype_digit($sessionId) && null !== $this->childMetadataReader) {
            $childMetadata = $this->childMetadataReader->readRunStartedMetadata($sessionId);
            if (null !== $childMetadata && $childMetadata->isAgentChild()) {
                return null;
            }
        }

        $modelRef = $this->selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $sessionId,
        );
        if (null === $modelRef) {
            return null;
        }

        if ('codex' !== $this->catalog->getProvider($modelRef->providerId)?->type
            || true !== $this->catalog->getModel($modelRef)?->compatibility?->supportsReasoningConfigurationUpdates) {
            return null;
        }

        return $modelRef;
    }

    private static function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }
}
