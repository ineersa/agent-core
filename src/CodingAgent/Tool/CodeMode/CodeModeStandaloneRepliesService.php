<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Amp\DeferredFuture;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeStandaloneReplyDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\SqliteQueue\Messenger\Transport;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Worker;

final class CodeModeStandaloneRepliesService
{
    public const string RECEIVER = 'code_mode_standalone_reply';
    private readonly string $incarnation;
    private ?Transport $receiver = null;
    private ?Worker $scopedWorker = null;
    private bool $stopping = false;
    /** @var array<string, DeferredFuture<ToolResult>> */
    private array $pending = [];

    public function __construct(
        private readonly CodeModeReplyTransportFactory $transports,
        private readonly ToolPayloadStoreInterface $payloads,
        #[Autowire(service: 'messenger.routable_message_bus')] private readonly RoutableMessageBus $bus,
        private readonly LlmFiberConsumeWaitSubscriber $wait,
    ) {
        $this->incarnation = bin2hex(random_bytes(16));
    }

    #[AsMessageHandler(bus: 'agent.execution.bus', fromTransport: self::RECEIVER)]
    public function __invoke(CodeModeStandaloneReplyDTO $message): void
    {
        $reference = $message->payload;
        $identity = CodeModeTerminalIdentity::forInvocation($reference->runId, $message->invocationId);
        if ('result' !== $reference->kind || $identity->stepId !== $reference->stepId
            || $reference->toolCallId !== $reference->idempotencyKey || 1 !== preg_match('/^code-mode-[a-f0-9]{64}$/D', $reference->toolCallId)) {
            return;
        }
        $waiter = $this->pending[self::key($reference->runId, $message->invocationId)] ?? null;
        try {
            if ($message->incarnation !== $this->incarnation || null === $waiter || $waiter->isComplete()) {
                return;
            }
            $payload = $this->payloads->read($reference);
            if (!$payload instanceof ToolCallResult || !\is_array($payload->result)) {
                throw new ToolCallException('Invalid standalone code-mode reply.', retryable: false);
            }
            $waiter->complete(new ToolResult($message->invocationId, CodeModeTool::NAME, $payload->result['content'], $payload->result['details'], $payload->isError));
        } catch (\Throwable $error) {
            $waiter->error($error);
        } finally {
            $this->payloads->deleteCall($reference->runId, $reference->toolCallId);
        }
    }

    public function open(string $slot): Transport
    {
        return $this->receiver = $this->transports->create('worker-'.$slot);
    }

    public function stopWaiting(): void
    {
        $this->stopping = true;
        $this->failPending();
    }

    #[AsEventListener(event: ConsoleEvents::SIGNAL)]
    public function onConsoleSignal(ConsoleSignalEvent $event): void
    {
        if ('extension_agent' !== getenv('HATFIELD_CODE_MODE_REPLY_SLOT') || !\in_array($event->getHandlingSignal(), [\SIGINT, \SIGTERM, \SIGQUIT], true)) {
            return;
        }
        $this->stopWaiting();
        if (null !== $this->scopedWorker) {
            $this->scopedWorker->stop();
            $this->wait->release($this->scopedWorker);
        }
    }

    public function close(): void
    {
        $this->failPending();
        $this->pending = [];
        $this->receiver?->close();
        $this->receiver = null;
    }

    /** @return array{queue: string, incarnation: string} */
    public function register(string $runId, string $id): array
    {
        if (!$this->stopping && null === $this->receiver && 'extension_agent' === getenv('HATFIELD_CODE_MODE_REPLY_SLOT')) {
            $this->open('extension_agent');
        }
        $key = self::key($runId, $id);
        if ($this->stopping || null === $this->receiver || isset($this->pending[$key])) {
            throw new ToolCallException('Standalone code mode requires an available worker reply receiver.', retryable: false);
        }
        $this->pending[$key] = new DeferredFuture();

        return ['queue' => substr($this->receiver->queueName(), \strlen('code_mode_reply.')), 'incarnation' => $this->incarnation];
    }

    public function await(string $runId, string $id): ToolResult
    {
        $future = $this->pending[self::key($runId, $id)]->getFuture();
        if ('extension_agent' === getenv('HATFIELD_CODE_MODE_REPLY_SLOT') && !$future->isComplete()) {
            $receiver = $this->receiver ?? throw new \LogicException('Missing scoped reply receiver.');
            $events = new EventDispatcher();
            $worker = new Worker([self::RECEIVER => $receiver], $this->bus, $events);
            $events->addListener(WorkerRunningEvent::class, $this->wait->onWorkerRunning(...));
            $events->addListener(WorkerMessageHandledEvent::class, static function () use ($worker, $future): void {
                if ($future->isComplete()) {
                    $worker->stop();
                }
            });
            $this->scopedWorker = $worker;
            $this->wait->bind($worker, $receiver, [$receiver->queueName()]);
            try {
                $worker->run(['sleep' => 0]);
            } finally {
                $this->scopedWorker = null;
                $this->wait->release($worker);
            }
        }

        return $future->await();
    }

    public function unregister(string $runId, string $id): void
    {
        unset($this->pending[self::key($runId, $id)]);
        if ('extension_agent' === getenv('HATFIELD_CODE_MODE_REPLY_SLOT')) {
            $this->receiver?->close();
            $this->receiver = null;
        }
    }

    private function failPending(): void
    {
        foreach ($this->pending as $waiter) {
            if (!$waiter->isComplete()) {
                $waiter->error(new ToolCallException('Code-mode reply receiver stopped.', retryable: false));
            }
        }
    }

    private static function key(string $runId, string $id): string
    {
        return json_encode([$runId, $id], \JSON_THROW_ON_ERROR);
    }
}
