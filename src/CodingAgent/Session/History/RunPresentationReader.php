<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Application\Replay\ReplayLifecycleStatus;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Explicit historical presentation read, not execution-state acquisition. */
final readonly class RunPresentationReader
{
    public function __construct(
        private EventStoreInterface $events,
        private RunLockManager $locks,
        private ReplayAssistantMessageFactory $assistantFactory,
        private ToolExecutionEndPayloadCodec $toolCodec,
    ) {
    }

    public function read(string $runId, int $limit, int $summaryChars): ?RunPresentationDTO
    {
        if ($limit < 0 || $summaryChars < 1) {
            throw new \InvalidArgumentException('Invalid presentation bounds.');
        }

        return $this->locks->synchronized($runId, function () use ($runId, $limit, $summaryChars): ?RunPresentationDTO {
            $cut = $this->events->latestSequenceFor($runId);
            if (null === $cut) {
                return null;
            }
            $status = RunStatus::Queued;
            $activeStep = null;
            $plan = HistoryReplayPlan::build($this->events->rangeFor($runId, 1, $cut));
            $messages = new PresentationMessageAccumulator($limit, $summaryChars);
            $pending = [];
            $shells = [];
            $completed = [];
            $questions = [];
            $eventCount = 0;
            foreach ($this->events->rangeFor($runId, 1, $cut) as $event) {
                ++$eventCount;
                if (!$plan->includes($event)) {
                    continue;
                }
                $payload = $event->payload;
                switch ($event->type) {
                    case RunEventTypeEnum::RunStarted->value:
                        $status = RunStatus::Running;
                        $activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : null;
                        $inner = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
                        $this->appendRawMessages($messages, $inner['messages'] ?? []);
                        break;
                    case RunEventTypeEnum::TurnAdvanced->value:
                        $status = RunStatus::Running;
                        $activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $activeStep;
                        break;
                    case RunEventTypeEnum::ContextCompactionStarted->value:
                        $status = RunStatus::Compacting;
                        $activeStep = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $activeStep;
                        break;
                    case RunEventTypeEnum::ContextCompactionFailed->value:
                        $decision = ReplayLifecycleStatus::compactionFailure($status, $activeStep, $payload);
                        $status = $decision['status'];
                        $activeStep = $decision['activeStep'];
                        break;
                    case RunEventTypeEnum::ContextCompacted->value:
                        $status = ReplayLifecycleStatus::afterCompaction((bool) ($payload['continue_after_compaction'] ?? false));
                        $activeStep = null;
                        $messages = new PresentationMessageAccumulator($limit, $summaryChars);
                        $this->appendRawMessages($messages, $payload['messages'] ?? []);
                        break;
                    case RunEventTypeEnum::ContextRefreshed->value:
                        $context = new PresentationMessageAccumulator($limit, $summaryChars);
                        $this->appendRawMessages($context, $payload['messages'] ?? []);
                        $messages->refresh($context);
                        break;
                    case RunEventTypeEnum::WaitingHuman->value:
                        $request = 'tool_call' === ($payload['continuation_kind'] ?? null)
                            ? PendingHumanInputRequestDTO::toolCallFromPayload($payload, \is_array($payload['continuation_ref'] ?? null) ? $payload['continuation_ref'] : [])
                            : PendingHumanInputRequestDTO::modelTurnFromInterruptPayload($payload);
                        $questions[] = $request->questionId;
                        $status = RunStatus::WaitingHuman;
                        break;
                    case RunEventTypeEnum::AgentCommandApplied->value:
                        $kind = $payload['kind'] ?? null;
                        if ('shell_command' === $kind && \is_string($payload['idempotency_key'] ?? null) && '' !== $payload['idempotency_key']) {
                            $shells['sh_'.hash('sha256', $payload['idempotency_key'])] = true;
                        }
                        $append = \in_array($kind, ['steer', 'follow_up', 'append_message'], true);
                        if ($append) {
                            $status = RunStatus::Running;
                        } elseif ('cancel' === $kind) {
                            $status = RunStatus::Cancelling;
                        }
                        if ($append || 'cancel' === $kind) {
                            $questions = [];
                        }
                        if ('human_response' === $kind) {
                            $question = $payload['question_id'] ?? null;
                            if (!\is_string($question) || '' === $question) {
                                throw new \InvalidArgumentException('human_response event is missing non-empty question_id.');
                            }
                            $index = array_search($question, $questions, true);
                            if (false !== $index && 0 !== $index) {
                                throw new \InvalidArgumentException('Human response does not match the active pending request.');
                            }
                            if (0 === $index) {
                                array_shift($questions);
                                $status = [] === $questions ? RunStatus::Running : RunStatus::WaitingHuman;
                                $append = true;
                            }
                        }
                        if ($append && \is_array($payload['message'] ?? null)) {
                            $this->appendRawMessages($messages, [$payload['message']]);
                        }
                        break;
                    case RunEventTypeEnum::LlmStepFailed->value:
                        $status = RunStatus::Failed;
                        // Execution replay's by-ref tool accumulators survive
                        // terminal helpers. Preserve its observable counts.
                        break;
                    case RunEventTypeEnum::LlmStepCompleted->value:
                        $status = RunStatus::Running;
                        $pending = [];
                        $assistant = \is_array($payload['assistant_message'] ?? null) ? $payload['assistant_message'] : null;
                        if (null !== $assistant) {
                            $message = $this->assistantFactory->create($assistant);
                            if (null !== $message) {
                                $messages->add($message);
                            }
                            foreach (\is_array($assistant['tool_calls'] ?? null) ? $assistant['tool_calls'] : [] as $call) {
                                if (\is_array($call) && \is_string($call['id'] ?? null)) {
                                    $pending[$call['id']] = false;
                                }
                            }
                        }
                        break;
                    case RunEventTypeEnum::ToolExecutionStart->value:
                        $id = $payload['tool_call_id'] ?? null;
                        if (\is_string($id) && !isset($shells[$id])) {
                            $pending[$id] = false;
                        }
                        break;
                    case RunEventTypeEnum::ToolExecutionEnd->value:
                        // Decode one result for validation, then retain its identity only.
                        $result = $this->toolCodec->fromEventPayload($payload);
                        $id = $result->toolCallId;
                        if (isset($shells[$id])) {
                            unset($shells[$id]);
                        } else {
                            $pending[$id] = true;
                            $completed[$id] = true;
                        }
                        unset($result);
                        break;
                    case RunEventTypeEnum::ToolBatchCommitted->value:
                        $messages->addToolResults(\count($completed));
                        $completed = [];
                        $pending = [];
                        break;
                    case RunEventTypeEnum::AgentEnd->value:
                        $status = ReplayLifecycleStatus::terminal($payload['reason'] ?? null);
                        // Only ToolBatchCommitted clears both tool accumulators
                        // in execution replay; terminal state fields do not.
                        $activeStep = null;
                        $questions = [];
                        break;
                }
                unset($message, $request, $payload, $inner, $assistant, $call);
            }
            $excerpt = $messages->excerpt;
            $includeExcerpt = null !== $excerpt && '' !== $excerpt && !str_starts_with($messages->excerptRawPrefix, $status->name);
            if (null === $excerpt || '' === $excerpt) {
                $excerpt = \sprintf('%s with status %s.', $status->name, $status->value);
            }

            $firstPending = array_key_first($pending);

            return new RunPresentationDTO($status, $plan->positionTurnNo, $cut, $eventCount, $messages->messageCount, \count($pending), null === $firstPending ? null : (string) $firstPending, $excerpt, $includeExcerpt, $messages->hasAssistant, $messages->eligibleCount, $messages->lines);
        });
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
