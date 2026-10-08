<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Codex;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex\CodexRunCancellationListener;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function Amp\async;

final class CodexRunCancellationListenerTest extends IsolatedKernelTestCase
{
    public function testUnscopedInvocationHasNoRunCancellation(): void
    {
        $event = new InvocationEvent(new CodexModel('gpt-5.6-luna'), []);
        (new CodexRunCancellationListener())($event);
        $this->assertSame([], $event->getOptions());
    }

    #[DataProvider('pendingCases')]
    public function testPendingReceiveFinishesWorkerAcknowledgesDeliveryAndCancelsRun(bool $partial): void
    {
        $message = new ExecuteLlmStep('run-ws-cancel', 1, 'step-ws-cancel', 1, 'key-ws-cancel', 'tools', [
            new AgentMessage('user', [['type' => 'text', 'text' => 'Hello']]),
        ]);
        $state = new RunState(
            runId: $message->runId(), status: RunStatus::Running, turnNo: 1,
            activeStepId: $message->stepId(), currentOperation: new CurrentOperationDTO(1, $message->stepId(), 1, $message->idempotencyKey()),
            messages: $message->messages, model: 'gpt-5.6-luna',
        );
        $reader = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $reader->replace($state);
        $resolver = $this->createStub(ModelResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel('gpt-5.6-luna', providerOptions: ['prompt_cache_key' => '0194dddd-bbbb-7ccc-8ddd-dddddddddddd']));
        $ready = new DeferredFuture();
        $release = new DeferredFuture();
        $finished = false;
        $receives = 0;
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('sendText');
        $connection->expects($this->once())->method('close');
        $connection->expects($this->exactly($partial ? 2 : 1))->method('receive')->willReturnCallback(
            static function (?Cancellation $cancellation) use ($partial, &$receives, $ready, $release, &$finished): ?WebsocketMessage {
                if ($partial && 0 === $receives++) {
                    return WebsocketMessage::fromText('{"type":"response.output_text.delta","delta":"partial"}');
                }
                $ready->complete();
                try {
                    $release->getFuture()->await($cancellation);
                } finally {
                    $finished = true;
                }

                return null;
            },
        );
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->willReturn($connection);
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $catalog->method('getModel')->willReturn(new CodexModel('gpt-5.6-luna'));
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $cache = new CodexWebSocketConnectionCache();
        $provider = Factory::createProvider(
            accessToken: 'test-access', accountId: 'test-account', modelCatalog: $catalog,
            eventDispatcher: $events, transport: CodexTransportEnum::WebsocketCached,
            websocketConnector: $connector, websocketConnectionCache: $cache,
        );
        $logger = new TestLogger();
        $serializer = self::getContainer()->get('serializer');
        $this->assertInstanceOf(DenormalizerInterface::class, $serializer);
        $adapter = new LlmPlatformAdapter(
            statusReader: $reader, messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(), platform: new Platform([$provider]),
            transformContextHooks: [], convertToLlmHooks: [], streamObserver: null, costCalculator: null,
            logger: $logger, denormalizer: $serializer, modelResolver: $resolver,
        );
        $results = new TestMessageBus();
        $handler = new ExecuteLlmStepWorker($adapter, $results, logger: $logger);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope($message));
        $dispatcher = new EventDispatcher();
        $worker = new Worker(['llm' => $transport], new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([ExecuteLlmStep::class => [$handler]])),
        ]), $dispatcher);
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function () use ($worker): void {
            $worker->stop();
        });
        // Keep Amp's unreferenced cancellation watchers alive with an owned cap.
        // If cancellation regresses, release the wait and stop the worker so proof fails.
        $safety = EventLoop::delay(2.0, static function () use ($release, $worker): void {
            $release->complete();
            $worker->stop();
        });
        $future = async(static fn () => $worker->run());
        try {
            $ready->getFuture()->await(new TimeoutCancellation(2.0));
            $this->assertSame([], $results->messages);
            $state = $state->with(['status' => RunStatus::Cancelling]);
            // Bypass the identity map to model a cancellation written by run-control.
            self::getContainer()->get(\Doctrine\DBAL\Connection::class)->executeStatement(
                'UPDATE run_operational_state SET status = ? WHERE run_id = ?', ['cancelling', $state->runId],
            );
            $future->await(new TimeoutCancellation(2.0));
            $this->assertTrue($finished);
            $this->assertCount(1, $transport->getAcknowledged());
            $this->assertSame([], $transport->getRejected());
            $this->assertSame([], $transport->get());
            $this->assertCount(1, $results->messages);
            $result = $results->messages[0];
            $this->assertInstanceOf(LlmStepResult::class, $result);
            $this->assertSame('aborted', $result->stopReason);
            $this->assertNull($result->error);
            $this->assertSame($partial ? 'partial' : null, $result->assistantMessage?->asText());
            $terminal = $this->resultHandler()->handle($result, $state);
            $this->assertNotNull($terminal->nextState);
            $this->assertSame(RunStatus::Cancelled, $terminal->nextState->status);
            $this->assertNull($terminal->nextState->activeStepId);
            $this->assertNull($terminal->nextState->currentOperation);
            $this->assertSame($message->messages, $terminal->nextState->messages);
            $this->assertSame(['llm_step_aborted', 'agent_end'], array_map(static fn ($event) => $event->type, $terminal->events));
            $this->assertNull(LlmInvocationCancelScope::current());
        } finally {
            EventLoop::cancel($safety);
            $worker->stop();
            if (!$release->isComplete()) {
                $release->complete();
            }
            \Amp\Future\awaitAll([$future]);
            $cache->closeAll();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function pendingCases(): iterable
    {
        yield 'before first delta' => [false];
        yield 'between deltas' => [true];
    }

    private function resultHandler(): LlmStepResultHandler
    {
        $serializer = self::getContainer()->get('serializer');
        $this->assertInstanceOf(NormalizerInterface::class, $serializer);

        return new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(new InMemoryCommandStore(), new CommandRouter([])),
            eventFactory: new EventFactory(), toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(), stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            normalizer: $serializer, commandBus: new TestMessageBus(),
        );
    }
}
