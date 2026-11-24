<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\AgentCommandKindEnum;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Validation\ForegroundBaselineValidator;

/** Scalar resume display state collected during the owner's existing body pass. */
final class SessionResumeMetadataProjection
{
    private const int MAX_BYTES = 4 * 1024 * 1024;
    private const int MAX_MESSAGES = 2000;
    private int $queuedBytes = 2;
    /** @var array<string, mixed> */
    private array $data = ['is_shell_run' => false, 'has_conversation' => false, 'has_shell' => false,
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0, 'totalCost' => 0.0, 'turnOutputTokens' => 0,
            'latestInputTokens' => 0, 'cacheReadTokens' => 0, 'cacheCreationTokens' => 0, 'hasCacheTelemetry' => false],
        'queued_messages' => []];

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [])
    {
        if ([] !== $data) {
            $this->data = $data;
        }
        $responseSeq = $this->data['current_foreground_response_seq'] ?? null;
        if (null !== $responseSeq && (!\is_int($responseSeq) || $responseSeq <= 0)) {
            throw new \InvalidArgumentException('Resume foreground response requires a positive canonical sequence.');
        }
        $this->data['current_foreground_response_seq'] = $responseSeq;
        $this->data['pending_foreground_requests'] ??= [];
        if (\array_key_exists('selected_prompt_turn_no', $this->data)
            && (!\is_int($this->data['selected_prompt_turn_no']) || $this->data['selected_prompt_turn_no'] < 0)) {
            throw new \InvalidArgumentException('Resume history selection requires a non-negative prompt turn.');
        }
        $queued = $this->data['queued_messages'] ?? null;
        if (!\is_array($queued) || \count($queued) > self::MAX_MESSAGES) {
            throw new \LengthException('Resume pending messages exceed the bootstrap view budget.');
        }
        foreach ($queued as $key => $text) {
            if (!\is_string($text)) {
                throw new \InvalidArgumentException('Resume pending messages require text.');
            }
            $this->queuedBytes += $this->messageBytes((string) $key, $text) + 1;
        }
        $this->assertBudget();
    }

    public function observe(RunEvent $event, ?RuntimeEvent $runtime, bool $pending = false): void
    {
        // History fences can be dropped by the runtime translator, so fold them canonically.
        if (\in_array($event->type, [RunEventTypeEnum::HistoryPositionSet->value, RunEventTypeEnum::HistoryTailDiscarded->value], true)) {
            $this->data['current_foreground_response_seq'] = null;
            $this->data['pending_foreground_requests'] = [];
        }
        $kind = AgentCommandKindEnum::fromPayload($event->payload['kind'] ?? null);
        if (RunEventTypeEnum::WaitingHuman->value === $event->type) {
            $this->addForegroundRequest($event->payload['question_id'] ?? null, isset($event->payload['approval_context']) ? 'approval' : 'question');
        } elseif (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
            if (AgentCommandKindEnum::Cancel === $kind || true === $kind?->appendsMessage()) {
                $this->data['pending_foreground_requests'] = [];
            } elseif ('human_response' === ($event->payload['kind'] ?? null)) {
                $id = $event->payload['question_id'] ?? null;
                if (\is_string($id)) {
                    $digest = hash('sha256', $id);
                    foreach ($this->data['pending_foreground_requests'] as $index => $request) {
                        if ($request['request_id'] === $id || ($request['request_digest'] ?? null) === $digest) {
                            // Canonical human responses settle the first matching occurrence only.
                            array_splice($this->data['pending_foreground_requests'], $index, 1);
                            break;
                        }
                    }
                }
            }
        }
        if (RunEventTypeEnum::HistoryPositionSet->value === $event->type && 'history_select' === ($event->payload['reason'] ?? null)) {
            $prompt = $event->payload['selected_prompt_turn_no'] ?? null;
            if (!\is_int($prompt) || $prompt < 0) {
                throw new \InvalidArgumentException('Resume history selection requires a non-negative prompt turn.');
            }
            // Retain only the canonical selection identity. Its full prompt can
            // be outside the replay prefix and is sought once when sealing.
            $this->data['selected_prompt_turn_no'] = $prompt;
        } elseif (\in_array($event->type, [RunEventTypeEnum::HistoryPositionSet->value, RunEventTypeEnum::HistoryTailDiscarded->value, RunEventTypeEnum::TurnAdvanced->value, RunEventTypeEnum::ContextCompacted->value], true)
            || (RunEventTypeEnum::AgentCommandApplied->value === $event->type && (AgentCommandKindEnum::Compact === $kind || true === $kind?->appendsMessage()))) {
            unset($this->data['selected_prompt_turn_no']);
        }
        $key = $event->payload['idempotency_key'] ?? null;
        if (\in_array($event->type, [RunEventTypeEnum::AgentCommandApplied->value, RunEventTypeEnum::AgentCommandRejected->value], true) && \is_string($key)) {
            $this->removeMessage($key);
        }
        if ((RunEventTypeEnum::AgentCommandApplied->value === $event->type && AgentCommandKindEnum::Cancel === $kind)
            || (RunEventTypeEnum::HistoryPositionSet->value === $event->type && 'history_select' === ($event->payload['reason'] ?? null))
            || RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
            $this->clearMessages();
        }
        if (\in_array($event->type, [RunEventTypeEnum::RunStarted->value, RunEventTypeEnum::LlmStepCompleted->value], true)) {
            $this->data['has_conversation'] = true;
        }
        if (RunEventTypeEnum::ToolExecutionStart->value === $event->type && 'bash' === ($event->payload['tool_name'] ?? null)) {
            $this->data['has_shell'] = true;
        }
        if (RunEventTypeEnum::AgentEnd->value === $event->type) {
            $this->data['is_shell_run'] = true === $this->data['has_shell'] && false === $this->data['has_conversation']
                && 'completed' === ($event->payload['reason'] ?? 'completed');
        }
        if (null === $runtime) {
            return;
        }
        if ($runtime->seq > 0) {
            // Internal model/tool steps retain the opening of the logical response.
            if (RuntimeEventTypeEnum::TurnStarted->value === $runtime->type) {
                $this->data['current_foreground_response_seq'] ??= $runtime->seq;
            } elseif (\in_array($runtime->type, [RuntimeEventTypeEnum::RunCompleted->value, RuntimeEventTypeEnum::RunFailed->value, RuntimeEventTypeEnum::TurnFailed->value, RuntimeEventTypeEnum::RunCancelled->value, RuntimeEventTypeEnum::TurnCancelled->value], true)) {
                $this->data['current_foreground_response_seq'] = null;
                $this->data['pending_foreground_requests'] = [];
            }
        }
        if (RuntimeEventTypeEnum::TurnStarted->value === $runtime->type) {
            $this->data['usage']['turnOutputTokens'] = 0;
        }
        if (RuntimeEventTypeEnum::AssistantMessageCompleted->value === $runtime->type && \is_array($runtime->payload['usage'] ?? null)) {
            $usage = $runtime->payload['usage'];
            $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
            $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
            $this->data['usage']['latestInputTokens'] = $input;
            $this->data['usage']['inputTokens'] += $input;
            $this->data['usage']['outputTokens'] += $output;
            $this->data['usage']['turnOutputTokens'] += $output;
            $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
            if (\is_int($cost) || \is_float($cost)) {
                $this->data['usage']['totalCost'] += (float) $cost;
            }
            $cached = $usage['cache_read_tokens'] ?? $usage['cached_tokens'] ?? null;
            if (null !== $cached) {
                $this->data['usage']['hasCacheTelemetry'] = true;
                $this->data['usage']['cacheReadTokens'] += (int) $cached;
            }
            $this->data['usage']['cacheCreationTokens'] += (int) ($usage['cache_creation_tokens'] ?? 0);
        }
        $key = $runtime->payload['idempotency_key'] ?? null;
        if (RuntimeEventTypeEnum::UserMessageQueued->value === $runtime->type && $pending && \is_string($key) && '' !== $key) {
            $text = (string) ($runtime->payload['text'] ?? '');
            $previous = isset($this->data['queued_messages'][$key]) ? $this->messageBytes($key, $this->data['queued_messages'][$key]) + 1 : 0;
            $bytes = $this->messageBytes($key, $text) + 1;
            if ($this->queuedBytes - $previous + $bytes > self::MAX_BYTES
                || (0 === $previous && \count($this->data['queued_messages']) >= self::MAX_MESSAGES)) {
                throw new \LengthException('Resume pending messages exceed the bootstrap view budget.');
            }
            $this->data['queued_messages'][$key] = $text;
            $this->queuedBytes += $bytes - $previous;
        } elseif (RuntimeEventTypeEnum::UserMessageSubmitted->value === $runtime->type && \is_string($key)) {
            $this->removeMessage($key);
        } elseif (\in_array($runtime->type, [RuntimeEventTypeEnum::RunCancelled->value, RuntimeEventTypeEnum::TurnCancelled->value, RuntimeEventTypeEnum::RunFailed->value, RuntimeEventTypeEnum::TurnFailed->value, RuntimeEventTypeEnum::RunHistoryPositionChanged->value], true)) {
            $this->clearMessages();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $this->assertBudget();

        return $this->data;
    }

    /** Seal attention from the authoritative owner, never from transcript text. */
    public function seedForegroundRequests(RunState $state): void
    {
        $this->data['pending_foreground_requests'] = [];
        foreach ($state->pendingHumanInputRequests as $request) {
            $this->data['pending_foreground_requests'][] = ['request_id' => $request->questionId,
                'category' => isset($request->payload['approval_context']) ? 'approval' : 'question'];
        }
        $this->foreground($state->lastSeq);
    }

    /** @return array{current_foreground_response_seq: ?int, pending_foreground_requests: list<array{request_id: string, category: string}>} */
    public function foreground(int $canonicalSequence = \PHP_INT_MAX): array
    {
        return ForegroundBaselineValidator::validate(['current_foreground_response_seq' => $this->data['current_foreground_response_seq'],
            'pending_foreground_requests' => $this->data['pending_foreground_requests']], $canonicalSequence);
    }

    private function addForegroundRequest(mixed $id, string $category): void
    {
        if (!\is_string($id) || '' === $id) {
            throw new \InvalidArgumentException('Resume foreground request requires an identity.');
        }
        // Historical identities need correlation, not wire admission. A distinct
        // nonexportable marker survives restore and refuses if still current.
        $this->data['pending_foreground_requests'][] = \strlen($id) > 1024
            ? ['request_id' => null, 'category' => $category, 'request_digest' => hash('sha256', $id)]
            : ['request_id' => $id, 'category' => $category];
        $this->assertBudget();
    }

    private function messageBytes(string $key, string $text): int
    {
        if (\strlen($key) + \strlen($text) > self::MAX_BYTES) {
            throw new \LengthException('Resume pending message exceeds the bootstrap view budget.');
        }

        return \strlen(json_encode([$key => $text], \JSON_THROW_ON_ERROR)) - 2;
    }

    private function removeMessage(string $key): void
    {
        if (isset($this->data['queued_messages'][$key])) {
            $this->queuedBytes -= $this->messageBytes($key, $this->data['queued_messages'][$key]) + 1;
            unset($this->data['queued_messages'][$key]);
        }
    }

    private function clearMessages(): void
    {
        $this->data['queued_messages'] = [];
        $this->queuedBytes = 2;
    }

    private function assertBudget(): void
    {
        if ($this->queuedBytes > self::MAX_BYTES || \strlen(json_encode($this->data, \JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \LengthException('Resume metadata exceeds the bootstrap view budget.');
        }
    }
}
