<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Schema;

use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

final class CommandPayloadNormalizer
{
    /**
     * Converts a known command or execution DTO into a transport payload.
     *
     * @return array<string, mixed>
     */
    public function normalize(object $message): array
    {
        return match (true) {
            $message instanceof StartRun => $this->normalizeStartRun($message),
            $message instanceof ApplyCommand => $this->normalizeApplyCommand($message),
            $message instanceof ExecuteLlmStep => $this->normalizeExecuteLlmStep($message),
            $message instanceof LlmStepResult => $this->normalizeLlmStepResult($message),
            $message instanceof ExecuteToolCall => $this->normalizeExecuteToolCall($message),
            $message instanceof ToolCallResult => $this->normalizeToolCallResult($message),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported payload type "%s".', $message::class)),
        };
    }

    /**
     * Converts a StartRun command into the schema envelope used in reference payloads.
     *
     * @return array<string, mixed>
     */
    public function normalizeStartRun(StartRun $command): array
    {
        return $this->mergePayloadIntoEnvelope([
            'schema_version' => SchemaVersion::CURRENT,
            'command' => 'StartRun',
            'run_id' => $command->runId(),
            'idempotency_key' => $command->idempotencyKey(),
        ], $command->payload, ['schema_version', 'command', 'run_id', 'idempotency_key']);
    }

    /**
     * Converts ApplyCommand variants (core/extension) into their canonical envelopes.
     *
     * @return array<string, mixed>
     */
    public function normalizeApplyCommand(ApplyCommand $command): array
    {
        if (str_starts_with($command->kind, 'ext:')) {
            return [
                'schema_version' => SchemaVersion::CURRENT,
                'command' => 'ApplyExtensionCommand',
                'run_id' => $command->runId(),
                'kind' => $command->kind,
                'idempotency_key' => $command->idempotencyKey(),
                'options' => $command->options,
                'payload' => $command->payload,
            ];
        }

        if (CoreCommandKind::isCore($command->kind)) {
            $envelope = [
                'schema_version' => SchemaVersion::CURRENT,
                'command' => $this->coreApplyCommandName($command->kind),
                'run_id' => $command->runId(),
                'idempotency_key' => $command->idempotencyKey(),
            ];

            return $this->mergePayloadIntoEnvelope(
                $envelope,
                $command->payload,
                ['schema_version', 'command', 'run_id', 'kind', 'idempotency_key', 'options', 'payload'],
            );
        }

        return [
            'schema_version' => SchemaVersion::CURRENT,
            'command' => 'ApplyCommand',
            'run_id' => $command->runId(),
            'kind' => $command->kind,
            'idempotency_key' => $command->idempotencyKey(),
            'options' => $command->options,
            'payload' => $command->payload,
        ];
    }

    /**
     * Converts ExecuteLlmStep worker input into canonical serialized payload shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeExecuteLlmStep(ExecuteLlmStep $message): array
    {
        return [
            'schema_version' => SchemaVersion::CURRENT,
            'type' => 'ExecuteLlmStep',
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'attempt' => $message->attempt(),
            'context_ref' => $message->contextRef,
            'tools_ref' => $message->toolsRef,
        ];
    }

    /**
     * Converts LLM execution outcome into canonical serialized payload shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeLlmStepResult(LlmStepResult $message): array
    {
        return [
            'schema_version' => SchemaVersion::CURRENT,
            'type' => 'LlmStepResult',
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'assistant_message' => $message->assistantMessage,
            'usage' => $message->usage,
            'stop_reason' => $message->stopReason,
            'error' => $message->error,
        ];
    }

    /**
     * Converts ExecuteToolCall worker input into canonical serialized payload shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeExecuteToolCall(ExecuteToolCall $message): array
    {
        return [
            'schema_version' => SchemaVersion::CURRENT,
            'type' => 'ExecuteToolCall',
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'attempt' => $message->attempt(),
            'tool_call_id' => $message->toolCallId,
            'tool_name' => $message->toolName,
            'args' => $message->args,
            'order_index' => $message->orderIndex,
            'tool_idempotency_key' => $message->toolIdempotencyKey,
            'mode' => $message->mode,
            'timeout_seconds' => $message->timeoutSeconds,
            'max_parallelism' => $message->maxParallelism,
            'assistant_message' => $message->assistantMessage,
            'arg_schema' => $message->argSchema,
        ];
    }

    /**
     * Converts ToolCallResult worker output into canonical serialized payload shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeToolCallResult(ToolCallResult $message): array
    {
        return [
            'schema_version' => SchemaVersion::CURRENT,
            'type' => 'ToolCallResult',
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'tool_call_id' => $message->toolCallId,
            'order_index' => $message->orderIndex,
            'result' => $message->result,
            'is_error' => $message->isError,
            'error' => $message->error,
        ];
    }

    private function coreApplyCommandName(string $kind): string
    {
        return match ($kind) {
            CoreCommandKind::Steer => 'ApplySteerCommand',
            CoreCommandKind::FollowUp => 'ApplyFollowUpCommand',
            CoreCommandKind::Cancel => 'ApplyCancelCommand',
            CoreCommandKind::HumanResponse => 'ApplyHumanResponseCommand',
            CoreCommandKind::Continue => 'ApplyContinueCommand',
            default => 'ApplyCommand',
        };
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $payload
     * @param list<string>         $reservedKeys
     *
     * @return array<string, mixed>
     */
    private function mergePayloadIntoEnvelope(array $envelope, array $payload, array $reservedKeys): array
    {
        $reservedLookup = array_fill_keys($reservedKeys, true);

        foreach ($payload as $key => $value) {
            if (isset($reservedLookup[$key])) {
                continue;
            }

            $envelope[$key] = $value;
        }

        return $envelope;
    }
}
