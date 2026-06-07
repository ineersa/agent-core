<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

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
 *
 * Startup behavior: on startPollLoop(), the poller cancels any stale
 * pending questions (created before startup) to handle controller
 * crash/restart scenarios. A crashed controller leaves no blocked
 * tool worker to receive a late answer, so stale questions must be
 * declined rather than re-shown.
 */
final class ToolQuestionPoller
{
    private const float POLL_INTERVAL = 0.5;

    /**
     * Cutoff window for startup cleanup. Questions created within this
     * timeframe of startup are preserved (they may be from this very
     * process or a just-restarted worker), while older stale ones are
     * cancelled.
     */
    private const string STARTUP_CLEANUP_CUTOFF = '-10 seconds';

    public function __construct(
        private readonly ToolQuestionStoreInterface $store,
        private readonly RuntimeEventEmitter $emitter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register the tool question poll loop.
     *
     * Before starting the loop, cancels any stale pending questions from
     * a previous controller crash/restart. This ensures a stale question
     * (whose blocked tool worker has already timed out or been killed) is
     * not re-emitted and surfaced to the user.
     */
    public function startPollLoop(float $interval = self::POLL_INTERVAL): void
    {
        $this->cancelStalePendingOnStartup();

        EventLoop::repeat($interval, function (): void {
            if ($this->emitter->isShuttingDown()) {
                return;
            }

            $this->poll();
        });
    }

    /**
     * Cancel stale pending questions created before startup.
     *
     * After a controller crash or restart, any tool workers that created
     * pending questions are gone or have already timed out, so there is
     * no blocked process to receive a late answer. Cancelling these on
     * startup prevents re-emitting stale questions to the user.
     */
    private function cancelStalePendingOnStartup(): void
    {
        try {
            $cutoff = new \DateTimeImmutable(self::STARTUP_CLEANUP_CUTOFF);
            $count = $this->store->cancelPendingQuestionsCreatedBefore($cutoff);

            if ($count > 0) {
                $this->logger->info('tool_question.poller_startup_cleanup', [
                    'component' => 'tool_question.poller',
                    'event_type' => 'tool_question.poller_startup_cleanup',
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('tool_question.poller_startup_cleanup_failed', [
                'component' => 'tool_question.poller',
                'event_type' => 'tool_question.poller_startup_cleanup_failed',
                'exception' => $e->getMessage(),
            ]);
            // Fail closed: do not block controller startup for cleanup.
        }
    }

    private function poll(): void
    {
        try {
            /** @var list<\Ineersa\CodingAgent\Entity\ToolQuestion> $questions */
            $questions = $this->store->findUnemittedPendingQuestions();
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
