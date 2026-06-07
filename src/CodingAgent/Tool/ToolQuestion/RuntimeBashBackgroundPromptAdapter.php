<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Tool\BashBackgroundPromptAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Production bash background prompt adapter that routes the question
 * through the TUI question overlay via the cross-process tool question store.
 *
 * When shouldBackground() is called (bash command has been running past
 * threshold), this adapter:
 * 1. Captures context from StackToolExecutionContextAccessor (runId, toolCallId).
 * 2. Creates a pending ToolQuestion in the store.
 * 3. Emits a RuntimeEvent via the store (controller poller picks it up).
 * 4. Polls the store for an answer at a small interval.
 * 5. Respects ToolContext cancellation and remaining timeout.
 *
 * Falls back to false (decline) when no ToolContext is available, matching
 * the existing non-interactive decline behavior.
 *
 * Note: The full TUI surfacing path (event emission -> question overlay ->
 * answer callback) is currently process-mode / controller-driven via
 * ToolQuestionPoller. In-process mode can still write to and poll the store
 * directly, but has no running controller poller loop today. The adapter
 * therefore safely returns false (decline) after timeout if no answer is
 * surfaced, matching the non-interactive decline fallback.
 */
final readonly class RuntimeBashBackgroundPromptAdapter implements BashBackgroundPromptAdapterInterface
{
    /**
     * Poll interval in microseconds (100ms).
     * Matches BashTool's default poll interval for consistency.
     *
     * The polling loop is bounded by ToolContext timeout/cancellation.
     * A simple 100ms interval is chosen over exponential backoff because
     * the tool worker is intentionally blocked waiting for a user decision
     * via the TUI overlay — prompt responsiveness is more important than
     * DB query reduction in this single-controller SQLite workflow.
     *
     * @see ToolContext::timeoutSeconds() timeout governs pollDeadline
     * @see CancellationTokenInterface::isCancellationRequested()
     */
    private const int POLL_INTERVAL_MICROS = 100_000;

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolQuestionStoreInterface $store,
        private readonly LoggerInterface $logger,
        private readonly BackgroundProcessStatusCheckerInterface $processStatusChecker,
    ) {
    }

    public function shouldBackground(string $command, int $pid, string $logPath, float $elapsedSeconds): bool
    {
        $context = $this->contextAccessor->current();

        if (null === $context) {
            $this->logger->info('bash_tool.background_no_context', [
                'component' => 'tool.bash.background_prompt',
                'event_type' => 'bash_tool.background_no_context',
                'pid' => $pid,
            ]);

            return false;
        }

        $runId = $context->runId();
        $toolCallId = $context->toolCallId();
        $cancelToken = $context->cancellationToken();

        // Build a deterministic request ID based on the prompt context.
        // Sanitize to avoid DB issues with special characters.
        $requestId = \sprintf('bash_bg_%s_%s_%d', $runId, $toolCallId, $pid);

        // Cap and normalize the command preview using the entity-defined max length.
        // The entity factory validates the cap, so this adapter truncates before creation.
        $commandPreview = $this->capPreview($command);

        $prompt = \sprintf(
            'Command is still running after %.0f seconds. Move it to the background?',
            $elapsedSeconds,
        );

        // Create the pending tool question in the store.
        $question = ToolQuestion::create(
            requestId: $requestId,
            runId: $runId,
            toolCallId: $toolCallId,
            toolName: 'bash',
            pid: $pid,
            logPath: $logPath,
            commandPreview: $commandPreview,
            prompt: $prompt,
        );

        $this->store->create($question);

        // Compute remaining timeout budget for polling.
        // If the context has a timeout, we should not poll indefinitely.
        $timeoutSeconds = $context->timeoutSeconds();
        $remainingSeconds = max(0, $timeoutSeconds - $elapsedSeconds);
        $pollDeadline = $remainingSeconds > 0
            ? hrtime(true) + (int) ($remainingSeconds * 1_000_000_000)
            : null;

        $this->logger->info('bash_tool.background_prompt_created', [
            'component' => 'tool.bash.background_prompt',
            'event_type' => 'bash_tool.background_prompt_created',
            'request_id' => $requestId,
            'run_id' => $runId,
            'pid' => $pid,
            'remaining_seconds' => $remainingSeconds,
        ]);

        // Poll for answer, respecting cancellation and timeout.
        while (true) {
            // Check for cancellation from the tool context.
            if ($cancelToken->isCancellationRequested()) {
                $this->store->cancel($requestId);

                $this->logger->info('bash_tool.background_cancelled', [
                    'component' => 'tool.bash.background_prompt',
                    'event_type' => 'bash_tool.background_cancelled',
                    'request_id' => $requestId,
                ]);

                return false;
            }

            // Check if the bash process has finished while waiting for a
            // user decision. When the process completes, cancel the pending
            // question and return false so BashTool's foreground supervision
            // loop can return the completed output instead of a timeout or
            // backgrounding notice.
            if ($this->processStatusChecker->isFinished($pid, $runId)) {
                $this->store->cancel($requestId);

                $this->logger->info('bash_tool.background_process_finished', [
                    'component' => 'tool.bash.background_prompt',
                    'event_type' => 'bash_tool.background_process_finished',
                    'request_id' => $requestId,
                    'run_id' => $runId,
                    'pid' => $pid,
                ]);

                return false;
            }

            // Check for timeout before poll deadline.
            if (null !== $pollDeadline && hrtime(true) > $pollDeadline) {
                $this->store->cancel($requestId);

                $this->logger->info('bash_tool.background_timed_out', [
                    'component' => 'tool.bash.background_prompt',
                    'event_type' => 'bash_tool.background_timed_out',
                    'request_id' => $requestId,
                ]);

                return false;
            }

            // Poll the store for an answer (fresh DB read each iteration).
            $answer = $this->store->pollAnswer($requestId);

            if (null !== $answer) {
                $this->logger->info('bash_tool.background_answer_received', [
                    'component' => 'tool.bash.background_prompt',
                    'event_type' => 'bash_tool.background_answer_received',
                    'request_id' => $requestId,
                    'answer' => $answer ? 'yes' : 'no',
                ]);

                return $answer;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    /**
     * Cap and normalize a command string for preview storage.
     * Truncates to ToolQuestion::COMMAND_PREVIEW_MAX_LENGTH and normalizes whitespace.
     *
     * The entity factory validates this same limit, ensuring consistency.
     */
    private function capPreview(string $command): string
    {
        // Normalize whitespace
        $preview = preg_replace('/\s+/', ' ', trim($command)) ?? '';

        $max = ToolQuestion::COMMAND_PREVIEW_MAX_LENGTH;
        if (mb_strlen($preview) > $max) {
            return mb_substr($preview, 0, $max - 3).'...';
        }

        return $preview;
    }
}
