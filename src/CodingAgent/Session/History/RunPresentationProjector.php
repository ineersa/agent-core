<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Application\Replay\ReplayLifecycleStatus;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Reduces presentation without retaining historical event or message bodies. */
final class RunPresentationProjector
{
    private RunStatus $status = RunStatus::Queued;
    private ?string $activeStep = null;
    private PresentationMessageAccumulator $messages;
    /** @var array<string, bool> */
    private array $pending = [];
    /** @var array<string, true> */
    private array $shells = [];
    /** @var array<string, true> */
    private array $completed = [];
    /** @var list<string> */
    private array $questions = [];
    private int $eventCount = 0;
    private int $lastObservedSequence = 0;

    public function __construct(private readonly ReplayAssistantMessageFactory $assistantFactory, private readonly ToolExecutionEndPayloadCodec $toolCodec, private readonly int $limit, private readonly int $summaryChars)
    {
        $this->messages = new PresentationMessageAccumulator($this->limit, $this->summaryChars);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['status' => $this->status->value, 'activeStep' => $this->activeStep, 'messages' => $this->messages->snapshot(), 'pending' => $this->pending, 'shells' => $this->shells, 'completed' => $this->completed, 'questions' => $this->questions, 'eventCount' => $this->eventCount, 'lastObservedSequence' => $this->lastObservedSequence];
    }

    /** @param array<string, mixed> $state */
    public function restore(array $state): void
    {
        if (array_keys($state) !== array_keys($this->snapshot())) {
            throw new \UnexpectedValueException('Invalid presentation snapshot.');
        }
        $this->status = RunStatus::from($state['status']);
        $this->messages->restore($state['messages']);
        foreach (['activeStep', 'pending', 'shells', 'completed', 'questions', 'eventCount', 'lastObservedSequence'] as $key) {
            $this->{$key} = $state[$key];
        }
    }

    public function observe(RunEvent $event, bool $included = true): void
    {
        ++$this->eventCount;
        $this->lastObservedSequence = $event->seq;
        if (!$included) {
            return;
        }
        $payload = $event->payload;
        switch ($event->type) {
            case RunEventTypeEnum::RunStarted->value:
                $this->status = RunStatus::Running;
                $this->activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : null;
                $inner = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
                $this->appendRawMessages($this->messages, $inner['messages'] ?? []);
                break;
            case RunEventTypeEnum::TurnAdvanced->value:
                $this->status = RunStatus::Running;
                $this->activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $this->activeStep;
                break;
            case RunEventTypeEnum::ContextCompactionStarted->value:
                $this->status = RunStatus::Compacting;
                $this->activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $this->activeStep;
                break;
            case RunEventTypeEnum::ContextCompactionFailed->value:
                $decision = ReplayLifecycleStatus::compactionFailure($this->status, $this->activeStep, $payload);
                $this->status = $decision['status'];
                $this->activeStep = $decision['activeStep'];
                break;
            case RunEventTypeEnum::ContextCompacted->value:
                $this->status = ReplayLifecycleStatus::afterCompaction((bool) ($payload['continue_after_compaction'] ?? false));
                $this->activeStep = null;
                $this->messages = new PresentationMessageAccumulator($this->limit, $this->summaryChars);
                $this->appendRawMessages($this->messages, $payload['messages'] ?? []);
                break;
            case RunEventTypeEnum::ContextRefreshed->value:
                $context = new PresentationMessageAccumulator($this->limit, $this->summaryChars);
                $this->appendRawMessages($context, $payload['messages'] ?? []);
                $this->messages->refresh($context);
                break;
            case RunEventTypeEnum::WaitingHuman->value:
                $request = 'tool_call' === ($payload['continuation_kind'] ?? null)
                    ? PendingHumanInputRequestDTO::toolCallFromPayload($payload, \is_array($payload['continuation_ref'] ?? null) ? $payload['continuation_ref'] : [])
                    : PendingHumanInputRequestDTO::modelTurnFromInterruptPayload($payload);
                $this->questions[] = $request->questionId;
                $this->status = RunStatus::WaitingHuman;
                break;
            case RunEventTypeEnum::AgentCommandApplied->value:
                $kind = $payload['kind'] ?? null;
                if ('shell_command' === $kind && \is_string($payload['idempotency_key'] ?? null) && '' !== $payload['idempotency_key']) {
                    $this->shells['sh_'.hash('sha256', $payload['idempotency_key'])] = true;
                }
                $append = \in_array($kind, ['steer', 'follow_up', 'append_message'], true);
                if ($append) {
                    $this->status = RunStatus::Running;
                } elseif ('cancel' === $kind) {
                    $this->status = RunStatus::Cancelling;
                }
                if ($append || 'cancel' === $kind) {
                    $this->questions = [];
                }
                if ('human_response' === $kind) {
                    $question = $payload['question_id'] ?? null;
                    if (!\is_string($question) || '' === $question) {
                        throw new \InvalidArgumentException('human_response event is missing non-empty question_id.');
                    }
                    $index = array_search($question, $this->questions, true);
                    if (false !== $index && 0 !== $index) {
                        throw new \InvalidArgumentException('Human response does not match the active pending request.');
                    }
                    if (0 === $index) {
                        array_shift($this->questions);
                        $this->status = [] === $this->questions ? RunStatus::Running : RunStatus::WaitingHuman;
                        $append = true;
                    }
                }
                if ($append && \is_array($payload['message'] ?? null)) {
                    $this->appendRawMessages($this->messages, [$payload['message']]);
                }
                break;
            case RunEventTypeEnum::LlmStepFailed->value:
                $this->status = RunStatus::Failed;
                // Execution replay's by-ref tool accumulators survive
                // terminal helpers. Preserve its observable counts.
                break;
            case RunEventTypeEnum::LlmStepCompleted->value:
                $this->status = RunStatus::Running;
                $this->pending = [];
                $assistant = \is_array($payload['assistant_message'] ?? null) ? $payload['assistant_message'] : null;
                if (null !== $assistant) {
                    $message = $this->assistantFactory->create($assistant);
                    if (null !== $message) {
                        $this->messages->add($message);
                    }
                    foreach (\is_array($assistant['tool_calls'] ?? null) ? $assistant['tool_calls'] : [] as $call) {
                        if (\is_array($call) && \is_string($call['id'] ?? null)) {
                            $this->pending[$call['id']] = false;
                        }
                    }
                }
                break;
            case RunEventTypeEnum::ToolExecutionStart->value:
                $id = $payload['tool_call_id'] ?? null;
                if (\is_string($id) && !isset($this->shells[$id])) {
                    $this->pending[$id] = false;
                }
                break;
            case RunEventTypeEnum::ToolCallAcknowledged->value:
            case RunEventTypeEnum::ToolExecutionEnd->value:
                // Decode one result for validation, then retain its identity only.
                $result = $this->toolCodec->fromEventPayload($payload);
                $id = $result->toolCallId;
                if (isset($this->shells[$id])) {
                    unset($this->shells[$id]);
                } else {
                    $this->pending[$id] = true;
                    $this->completed[$id] = true;
                }
                unset($result);
                break;
            case RunEventTypeEnum::ToolBatchCommitted->value:
                $this->messages->addToolResults(\count($this->completed));
                $this->completed = [];
                $this->pending = [];
                break;
            case RunEventTypeEnum::AgentEnd->value:
                $this->status = ReplayLifecycleStatus::terminal($payload['reason'] ?? null);
                // Only ToolBatchCommitted clears both tool accumulators
                // in execution replay; terminal state fields do not.
                $this->activeStep = null;
                $this->questions = [];
                break;
        }
    }

    public function presentation(int $turnNo, int $cut): RunPresentationDTO
    {
        $excerpt = $this->messages->excerpt;
        $includeExcerpt = null !== $excerpt && '' !== $excerpt && !str_starts_with($this->messages->excerptRawPrefix, $this->status->name);
        if (null === $excerpt || '' === $excerpt) {
            $excerpt = \sprintf('%s with status %s.', $this->status->name, $this->status->value);
        }
        $firstPending = array_key_first($this->pending);

        return new RunPresentationDTO($this->status, $turnNo, $cut, $this->eventCount, $this->messages->messageCount, \count($this->pending), null === $firstPending ? null : (string) $firstPending, $excerpt, $includeExcerpt, $this->messages->hasAssistant, $this->messages->eligibleCount, $this->messages->lines);
    }

    private function appendRawMessages(PresentationMessageAccumulator $messages, mixed $rawMessages): void
    {
        if (!\is_array($rawMessages)) {
            return;
        }
        foreach ($rawMessages as $raw) {
            if (\is_array($raw)) {
                $message = AgentMessage::fromPayload($raw);
                if (null !== $message) {
                    $messages->add($message);
                }
            }
        }
    }
}
