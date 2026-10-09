<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

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
        $key = $event->payload['idempotency_key'] ?? null;
        if (\in_array($event->type, ['agent_command_applied', 'agent_command_rejected'], true) && \is_string($key)) {
            $this->removeMessage($key);
        }
        if (('agent_command_applied' === $event->type && 'cancel' === ($event->payload['kind'] ?? null))
            || ('history_position_set' === $event->type && 'history_select' === ($event->payload['reason'] ?? null))
            || 'history_tail_discarded' === $event->type) {
            $this->clearMessages();
        }
        if (\in_array($event->type, ['run_started', 'llm_step_completed'], true)) {
            $this->data['has_conversation'] = true;
        }
        if ('tool_execution_start' === $event->type && 'bash' === ($event->payload['tool_name'] ?? null)) {
            $this->data['has_shell'] = true;
        }
        if ('agent_end' === $event->type) {
            $this->data['is_shell_run'] = true === $this->data['has_shell'] && false === $this->data['has_conversation']
                && 'completed' === ($event->payload['reason'] ?? 'completed');
        }
        if (null === $runtime) {
            return;
        }
        if ('turn.started' === $runtime->type) {
            $this->data['usage']['turnOutputTokens'] = 0;
        }
        if ('assistant.message_completed' === $runtime->type && \is_array($runtime->payload['usage'] ?? null)) {
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
        if ('user.message_queued' === $runtime->type && $pending && \is_string($key) && '' !== $key) {
            $text = (string) ($runtime->payload['text'] ?? '');
            $previous = isset($this->data['queued_messages'][$key]) ? $this->messageBytes($key, $this->data['queued_messages'][$key]) + 1 : 0;
            $bytes = $this->messageBytes($key, $text) + 1;
            if ($this->queuedBytes - $previous + $bytes > self::MAX_BYTES
                || (0 === $previous && \count($this->data['queued_messages']) >= self::MAX_MESSAGES)) {
                throw new \LengthException('Resume pending messages exceed the bootstrap view budget.');
            }
            $this->data['queued_messages'][$key] = $text;
            $this->queuedBytes += $bytes - $previous;
        } elseif ('user.message_submitted' === $runtime->type && \is_string($key)) {
            $this->removeMessage($key);
        } elseif (\in_array($runtime->type, ['run.cancelled', 'turn.cancelled', 'run.failed', 'turn.failed', 'run.history_position_changed'], true)) {
            $this->clearMessages();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $this->assertBudget();

        return $this->data;
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
