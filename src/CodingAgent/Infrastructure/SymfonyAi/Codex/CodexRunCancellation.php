<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Poll persisted run status only while an Amp operation is subscribed. */
#[Exclude]
final class CodexRunCancellation implements Cancellation
{
    private readonly DeferredCancellation $source;

    /** @var array<string, true> */
    private array $subscriptions = [];

    private ?string $watcher = null;

    public function __construct(private readonly CancellationTokenInterface $token)
    {
        $this->source = new DeferredCancellation();
    }

    public function __destruct()
    {
        $this->stopPolling();
    }

    public function subscribe(\Closure $callback): string
    {
        $this->check();
        $id = $this->source->getCancellation()->subscribe($callback);
        $this->subscriptions[$id] = true;
        if (null === $this->watcher && !$this->source->isCancelled()) {
            // A watcher must not retain the request after its consumers release it.
            $weak = \WeakReference::create($this);
            $this->watcher = EventLoop::repeat(0.1, static function () use ($weak): void {
                $weak->get()?->check();
            });
            EventLoop::unreference($this->watcher);
        }

        return $id;
    }

    public function unsubscribe(string $id): void
    {
        $this->source->getCancellation()->unsubscribe($id);
        unset($this->subscriptions[$id]);
        if ([] === $this->subscriptions) {
            $this->stopPolling();
        }
    }

    public function isRequested(): bool
    {
        $this->check();

        return $this->source->isCancelled();
    }

    public function throwIfRequested(): void
    {
        $this->check();
        $this->source->getCancellation()->throwIfRequested();
    }

    private function check(): void
    {
        if (!$this->source->isCancelled() && $this->token->isCancellationRequested()) {
            $this->source->cancel(new LlmStreamCancelledException());
            $this->stopPolling();
        }
    }

    private function stopPolling(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
    }
}
