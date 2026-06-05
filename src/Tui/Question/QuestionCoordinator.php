<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * In-memory coordinator for question request lifecycle.
 *
 * Holds one active question at a time and maintains a FIFO queue for
 * subsequent requests. Answer callbacks are invoked for all sources
 * (Tui and AgentCore) — upper layers register callbacks that dispatch
 * answer_human commands to the runtime or handle local side effects.
 * Cancel callbacks (AgentCore) send a fail-safe Deny answer when the
 * user dismisses the overlay without responding.
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

    /** @var array<string, \Closure(): void> */
    private array $cancelCallbacks = [];

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
     * The optional $onAnswer closure is invoked when the user provides
     * an answer. It receives the answer value as its sole argument.
     *
     * The optional $onCancel closure is invoked when the user cancels
     * the question (via ESC) before providing an answer. AgentCore
     * callers typically send a fail-safe "Deny" answer_human command.
     */
    public function enqueue(QuestionRequest $request, ?\Closure $onAnswer = null, ?\Closure $onCancel = null): void
    {
        if (isset($this->requestIds[$request->requestId])) {
            throw new \InvalidArgumentException(\sprintf('A request with ID "%s" is already enqueued or active.', $request->requestId));
        }

        $this->requestIds[$request->requestId] = true;

        if (null !== $onAnswer) {
            $this->callbacks[$request->requestId] = $onAnswer;
        }

        if (null !== $onCancel) {
            $this->cancelCallbacks[$request->requestId] = $onCancel;
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
     * Check whether a request with the given ID has already been
     * enqueued (active or queued).
     *
     * Used to guard against duplicate enqueue from event replays.
     */
    public function hasRequest(string $requestId): bool
    {
        return isset($this->requestIds[$requestId]);
    }

    /**
     * Resolve the active question with a user-provided answer.
     *
     * The registered callback (if any, for any source) is invoked with
     * the answer value. For AgentCore-source questions the callback
     * typically sends an answer_human command back to the runtime;
     * for Tui-source questions it handles local side effects.
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

        $callback = $this->callbacks[$this->active->requestId] ?? null;
        if (null !== $callback) {
            try {
                $callback($value);
            } finally {
                $this->advance();
            }

            return;
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
     * Invokes the registered cancel callback (if any) before advancing
     * the queue. For AgentCore-source questions this typically sends a
     * fail-safe "Deny" answer_human command to the runtime so the run
     * is not left stuck in WaitingHuman.
     *
     * The cancel callback runs inside a try/finally so queue advancement
     * always happens, even if the callback throws.
     */
    public function cancel(): void
    {
        if (null === $this->active) {
            return;
        }

        $this->activeStatus = QuestionStatus::Cancelled;

        $cancelCallback = $this->cancelCallbacks[$this->active->requestId] ?? null;
        if (null !== $cancelCallback) {
            try {
                $cancelCallback();
            } finally {
                $this->advance();
            }

            return;
        }

        $this->advance();
    }

    /**
     * Advance to the next queued request, or clear the active slot.
     */
    private function advance(): void
    {
        $activeRequestId = $this->active->requestId;
        unset($this->callbacks[$activeRequestId], $this->cancelCallbacks[$activeRequestId], $this->requestIds[$activeRequestId]);

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
