<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * In-memory coordinator for question request lifecycle.
 *
 * Holds one active question at a time and maintains a FIFO queue for
 * subsequent requests. Answers to Tui-source questions invoke the
 * registered local callback; AgentCore-source questions only record
 * status (runtime dispatch is handled by upper layers).
 *
 * This coordinator is stateful and should be scoped per-session.
 */
final class QuestionCoordinator
{
    private ?QuestionRequest $active = null;
    private ?QuestionStatus $activeStatus = null;

    /** @var \SplQueue<QuestionRequest> */
    private \SplQueue $queue;

    /** @var array<string, \Closure(mixed): void> */
    private array $callbacks = [];

    /** @var array<string, true> */
    private array $requestIds = [];

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    /**
     * Enqueue a question request.
     *
     * If no question is currently active the request becomes active
     * immediately. Otherwise it is queued and will be shown after the
     * current active request is answered, rejected, or cancelled.
     *
     * The optional $onAnswer closure is invoked only when source is
     * QuestionSource::Tui and the user provides an answer. It receives
     * the answer value as its sole argument.
     */
    public function enqueue(QuestionRequest $request, ?\Closure $onAnswer = null): void
    {
        if (isset($this->requestIds[$request->requestId])) {
            throw new \InvalidArgumentException(\sprintf('A request with ID "%s" is already enqueued or active.', $request->requestId));
        }

        $this->requestIds[$request->requestId] = true;

        if (null !== $onAnswer) {
            $this->callbacks[$request->requestId] = $onAnswer;
        }

        if (null === $this->active) {
            $this->activate($request);
        } else {
            $this->queue->enqueue($request);
        }
    }

    /**
     * Return the currently active question request, or null if none.
     */
    public function activeRequest(): ?QuestionRequest
    {
        return $this->active;
    }

    /**
     * Return the status of the currently active request, or null.
     */
    public function activeStatus(): ?QuestionStatus
    {
        return $this->activeStatus;
    }

    /**
     * True when a question request is active and awaiting resolution.
     */
    public function actionRequired(): bool
    {
        return null !== $this->active;
    }

    /**
     * Resolve the active question with a user-provided answer.
     *
     * For Tui-source questions, the registered callback (if any) is
     * invoked with the answer value. For AgentCore-source questions
     * only the status is updated — runtime dispatch is the caller's
     * responsibility.
     *
     * After recording the answer, the coordinator advances to the
     * next queued request, if any.
     */
    public function answer(mixed $value): void
    {
        if (null === $this->active) {
            return;
        }

        $this->activeStatus = QuestionStatus::Answered;

        if (QuestionSource::Tui === $this->active->source) {
            $callback = $this->callbacks[$this->active->requestId] ?? null;
            if (null !== $callback) {
                try {
                    $callback($value);
                } finally {
                    $this->advance();
                }

                return;
            }
        }

        $this->advance();
    }

    /**
     * Reject the active question without providing an answer.
     *
     * Advances to the next queued request, if any.
     */
    public function reject(): void
    {
        if (null === $this->active) {
            return;
        }

        $this->activeStatus = QuestionStatus::Rejected;
        $this->advance();
    }

    /**
     * Cancel the active question.
     *
     * Advances to the next queued request, if any.
     */
    public function cancel(): void
    {
        if (null === $this->active) {
            return;
        }

        $this->activeStatus = QuestionStatus::Cancelled;
        $this->advance();
    }

    /**
     * Advance to the next queued request, or clear the active slot.
     */
    private function advance(): void
    {
        unset($this->callbacks[$this->active->requestId], $this->requestIds[$this->active->requestId]);

        if ($this->queue->isEmpty()) {
            $this->active = null;
            $this->activeStatus = null;

            return;
        }

        $this->activate($this->queue->dequeue());
    }

    /**
     * Set a request as the active one with Pending status.
     */
    private function activate(QuestionRequest $request): void
    {
        $this->active = $request;
        $this->activeStatus = QuestionStatus::Pending;
    }
}
