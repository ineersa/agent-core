<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Runtime\Messenger\BashNativeCompletionStamp;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Owner-prepared Bash finishes locally and reports through ordinary ToolCallResult.
 *
 * Lost coverage relative to reply-RPC proofs: register/observe/accept awaits and
 * Started/Finished/Accepted lifecycle messages are deleted, not rebuilt.
 *
 * @covers \Ineersa\CodingAgent\Tool\BashTool
 */
final class BashNativeCompletionWithoutOwnerReplyTest extends IsolatedKernelTestCase
{
    public function testOwnerPreparedForegroundBashCompletesWithoutOwnerReply(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('bash-native-completion');
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message, [new HandledStamp(null, 'test')]);
            }
        };
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle(
            new BackgroundProcessConfig(storageDir: $temporary.'/bg'),
            new NullLogger(),
            new LockFactory(new FlockStore($temporary.'/locks')),
        );
        $manager = new BackgroundProcessManager(
            $store,
            $lifecycle,
            new BackgroundProcessConfig(storageDir: $temporary.'/bg'),
            new NullLogger(),
            $bus,
        );
        // Match production tool workers: observation stays local; owner applies finish.
        $manager->useRemoteLifecycleOwner();
        $accessor = new StackToolExecutionContextAccessor();
        $tool = new BashTool(
            manager: $manager,
            contextAccessor: $accessor,
            toolRuntime: new ToolRuntime($accessor),
            logger: new TestLogger(),
            normalizer: self::getContainer()->get(NormalizerInterface::class),
            config: new BashToolConfig(pollIntervalMicros: 1_000),
        );

        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('bash native completion');
        $call = new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: 'step-native',
            attempt: 1,
            idempotencyKey: 'bash-native',
            toolCallId: 'call_native',
            toolName: 'bash',
            args: ['command' => "printf 'native-ok\\n'"],
            orderIndex: 0,
            mode: 'parallel',
            timeoutSeconds: 30,
            maxParallelism: 1,
            argSchema: [],
            toolsRef: null,
        );
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $runId, 1, 'step-native', [$call]);
        $prepared = $manager->prepareNativeLaunch($runId, 'call_native', "printf 'native-ok\\n'", false);
        $this->assertNull($store->snapshot($prepared->id)?->pid);

        $outcome = $accessor->with(
            new ToolContext($runId, 1, 'call_native', 'bash', new NullCancellationToken(), 1, stepId: 'step-native'),
            static fn (): string|ToolResult => $tool(new BashArgumentsDTO(command: "printf 'native-ok\\n'")),
        );

        $this->assertInstanceOf(ToolResult::class, $outcome);
        $this->assertFalse($outcome->isError);
        $this->assertSame("native-ok\n", $outcome->content[0]['text'] ?? null);
        $this->assertArrayHasKey('bash_native', $outcome->details ?? []);
        $this->assertSame([], array_values(array_filter(
            $bus->messages,
            static fn (object $message): bool => !$message instanceof ToolCallResult,
        )), 'Foreground Bash must not publish lifecycle/reply commands.');

        $snapshot = $store->snapshot($prepared->id);
        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->pid, 'Worker must not write native launch facts; owner applies them on receipt.');
        $this->assertSame(BackgroundProcessStatusEnum::Running, $snapshot->status);
        $this->assertNull($manager->observeNativeByStatusPath($prepared->statusPath, $runId));

        // Ordinary ToolResult seals through the existing owner ToolCallResult path.
        $result = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $call,
            $outcome,
        );

        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered(new RunState(
            $runId,
            RunStatus::Running,
            turnNo: 1,
            activeStepId: 'step-native',
            pendingToolCalls: ['call_native' => false],
        ));
        $middleware = self::getContainer()->get(ToolPayloadMiddleware::class);
        $middleware->useRemoteResultOwner();
        $ownerBus = new MessageBus([
            $middleware,
            new HandleMessageMiddleware(new HandlersLocator([
                ToolCallResult::class => [static fn (ToolCallResult $message): ToolCallResult => $message],
                ToolPayloadMessage::class => [static fn (ToolPayloadMessage $message): ToolPayloadMessage => $message],
            ])),
        ]);
        $sealed = $ownerBus->dispatch($result);
        $this->assertInstanceOf(ToolPayloadMessage::class, $sealed->getMessage());
        $this->assertNull($sealed->getMessage()->deferredId);
        $this->assertInstanceOf(BashNativeCompletionStamp::class, $sealed->last(BashNativeCompletionStamp::class));
        $ownerBus->dispatch($sealed->withoutAll(HandledStamp::class)->with(new ReceivedStamp('run_control')));

        $owned = $store->snapshot($prepared->id);
        $this->assertNotNull($owned);
        $this->assertNotNull($owned->pid, 'Owner receipt must apply the native PID.');
        $this->assertNotNull($owned->startedAt, 'Owner receipt must apply the actual start time.');
        $this->assertSame(BackgroundProcessStatusEnum::Finished, $owned->status);
        $this->assertSame(0, $owned->exitCode);
    }
}
