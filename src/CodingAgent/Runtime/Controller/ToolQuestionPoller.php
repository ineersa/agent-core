<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Polls the tool_question DB table for un-emitted pending questions
 * and emits RuntimeEvents so the TUI can show them.
 *
 * The tool worker writes a pending question to the DB. This poller
 * picks it up, emits a tool_question.requested runtime event (which
 * the TUI question coordinator renders), and marks the request emitted
 * to prevent duplicate events.
 *
 * This avoids coupling tool workers to the controller's stdout pipe.
 * In-process mode may also use this same store to surface tool questions
 * to the TUI overlay.
 */
final class ToolQuestionPoller
{
    private const float POLL_INTERVAL = 0.5;

    public function __construct(
        private readonly ToolQuestionStoreInterface $store,
        private readonly RuntimeEventEmitter $emitter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register the tool question poll loop.
     */
    public function startPollLoop(float $interval = self::POLL_INTERVAL): void
    {
        EventLoop::repeat($interval, function (): void {
            if ($this->emitter->isShuttingDown()) {
                return;
            }

            $this->poll();
        });
    }

    private function poll(): void
    {
        try {
            $qb = $this->store->createQueryBuilder();
            $qb->where('tq.status = :status')
                ->andWhere('tq.emittedAt IS NULL')
                ->setParameter('status', ToolQuestionStatusEnum::Pending)
                ->orderBy('tq.createdAt', 'ASC');

            /** @var list<\Ineersa\CodingAgent\Entity\ToolQuestion> $questions */
            $questions = $qb->getQuery()->getResult();
        } catch (\Throwable $e) {
            $this->logger->warning('tool_question.poller_query_failed', [
                'component' => 'tool_question.poller',
                'event_type' => 'tool_question.poller_query_failed',
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($questions as $question) {
            $event = new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
                runId: $question->runId,
                seq: 0,
                payload: [
                    'request_id' => $question->requestId,
                    'run_id' => $question->runId,
                    'tool_call_id' => $question->toolCallId,
                    'tool_name' => $question->toolName,
                    'pid' => $question->pid,
                    'log_path' => $question->logPath,
                    'command_preview' => $question->commandPreview,
                    'prompt' => $question->prompt,
                    'kind' => $question->kind,
                    'transcript' => false,
                ],
            );

            $this->emitter->emit($event);
            $this->store->markEmitted($question->requestId);
        }
    }
}
