<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashLifecycleFailureSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\DecideBashProcessHandler;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RegisterBashProcessHandler;
use Ineersa\CodingAgent\Application\Message\DTO\ReadyCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchFailureDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RegisterDeferredInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\ReserveChildInvocationDTO;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleHandler;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeLifecycleHandler;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeLifecycleService;
use Ineersa\CodingAgent\Application\Pipeline\RegisterDeferredInvocationHandler;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReader;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlDoctrineFailureResetSubscriber;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\DTO\AgentResumeArgumentsDTO;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Amp\async;
use function Amp\delay;

final class BashRegistrationClientTest extends IsolatedKernelTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function childCallerModes(): iterable
    {
        yield 'resume with lost success reply' => [true, false];
        yield 'launch with lost success reply' => [false, false];
        yield 'interruption while awaiting reservation' => [false, true];
        yield 'resume interruption while awaiting reservation' => [true, true];
    }

    #[DataProvider('childCallerModes')]
    public function testChildCallerDoesNotRepeatExternalActionWhileMetadataReplyIsPending(bool $resume, bool $interrupt): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner) use ($resume, $interrupt): void {
            $repository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
            $registry = self::getContainer()->get(AgentArtifactRegistry::class);
            $child = 'resume-lost-reply-child';
            $artifact = 'agent_resume_lost_reply';
            $registry->create($owner, $artifact, $child, 'scout', AgentArtifactKindEnum::Subagent);
            $registry->update($owner, $artifact, status: AgentArtifactStatusEnum::Completed, summary: 'done');
            $followUpCalled = new DeferredFuture();
            $runner = $this->createMock(AgentRunnerInterface::class);
            $runner->expects($resume ? ($interrupt ? $this->atMost(1) : $this->once()) : $this->never())->method('followUp')->with($child, $this->anything())->willReturnCallback(static fn () => $followUpCalled->complete());
            $runner->expects(!$resume && !$interrupt ? $this->once() : $this->never())->method('start');
            $status = $this->createStub(RunOperationalStatusReaderInterface::class);
            $status->method('findOperationalStatus')->willReturn(new RunOperationalStatusDTO(RunStatus::Completed, 1));
            $events = $this->createStub(EventStoreInterface::class);
            $events->method('latestSequenceFor')->willReturn(1);
            $serializer = self::getContainer()->get(SerializerInterface::class);
            $dropSuccess = true;
            $failing = $this->createStub(SerializerInterface::class);
            $failing->method('serialize')->willReturnCallback(static function (mixed $data, string $format, array $context = []) use ($serializer, &$dropSuccess, $interrupt, $repository): string {
                if ($interrupt && $data instanceof BashRegistrationReplyDTO && null !== $data->childBatch) {
                    $batch = $data->childBatch;
                    // The reservation is committed, but its caller still awaits
                    // this reply. Commit owner interruption before releasing it.
                    $repository->persistInterruptionIntent($batch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled, new \DateTimeImmutable(), $batch->projectionVersion);
                }
                if ($data instanceof BashRegistrationReplyDTO && null === $data->childBatch && $dropSuccess) {
                    $dropSuccess = false;
                    throw new \RuntimeException('Lost committed child success reply');
                }

                return $serializer->serialize($data, $format, $context);
            });
            $publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $owner, $failing);
            $handler = new ChildInvocationLifecycleHandler(new ChildInvocationLifecycleService($repository), $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([ReserveChildInvocationDTO::class => ['run_control'], RecordChildLaunchSuccessDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator))]), $paths->endpointPath, $owner, $serializer, self::getContainer()->get(ValidatorInterface::class));
            $remote = new ChildInvocationLifecycleService($repository);
            $remote->useOwner($client);
            $context = new StackToolExecutionContextAccessor();
            $service = new AgentResumeExecutionService($registry, $repository, self::getContainer()->get(DeferredSubagentChildRepository::class), new DeferredSubagentBatchIdentityFactory(), $runner, $status, $events, self::getContainer()->get(RunLockManager::class), StubRunRelationshipReader::topLevel($owner), new AgentDepthGuard(), $context, new AgentsConfig(), new TestLogger(), $remote, self::getContainer()->get(ToolBatchStoreInterface::class), self::getContainer()->get(SerializerInterface::class));
            $artifactLifecycle = self::getContainer()->get(ChildRunArtifactLifecycleService::class);
            $runtime = new DeferredSubagentBatchRuntimeStartService($runner, $artifactLifecycle, new ChildRunBatchLaunchService($runner, $artifactLifecycle, self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class), new TestLogger()), self::getContainer()->get(ChildRunBatchLifecyclePolicyDTO::class), new TestLogger());
            $definitionPolicy = new SubagentLaunchDefinitionPolicyService(new AgentDefinitionCatalog([new AgentDefinitionDTO(name: 'scout', description: 'Test child', tools: ['read'], instructions: 'Test.')]), new AgentDepthGuard(), self::getContainer()->get(AgentToolPolicyResolver::class), self::getContainer()->get(RunRelationshipReader::class));
            $inputFactory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
            $forkBuilder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
            $preparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $inputFactory, $forkBuilder, self::getContainer()->get(ForkToolPolicyResolver::class));
            $batchPreparation = new DeferredSubagentBatchPreparationService($preparation, new DeferredSubagentBatchIdentityFactory(), $artifactLifecycle, $inputFactory, $forkBuilder);
            $launch = new DeferredSubagentBatchLaunchService($batchPreparation, $repository, $runtime, $context, new AgentsConfig(), new TestLogger(), $remote);
            self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState($owner, RunStatus::Running));
            $lost = new DeferredFuture();
            $ownerTask = async(function () use ($requests, $handler, $lost, $interrupt): void {
                $expected = $interrupt ? 1 : 2;
                for ($handled = 0; $handled < $expected;) {
                    foreach ($requests->get() as $delivery) {
                        try {
                            $handler($delivery->getMessage());
                        } catch (\RuntimeException $error) {
                            $this->assertSame('Lost committed child success reply', $error->getMessage());
                            $lost->complete();
                            // Leave the committed success request leased. The
                            // broker, not another caller invocation, redelivers it.
                            continue;
                        }
                        $requests->ack($delivery);
                        ++$handled;
                    }
                    if ($handled < $expected) {
                        $requests->wait(3000, new TimeoutCancellation(8));
                    }
                }
            });
            if ($resume) {
                $this->registerAgentResumeBatch($owner, 1, 'resume-lost-step', [[
                    'toolCallId' => 'resume-lost',
                    'orderIndex' => 0,
                    'args' => ['artifact_id' => $artifact, 'task' => 'continue'],
                ]]);
            }
            $caller = async(static fn () => $context->with(new ToolContext(runId: $owner, turnNo: 1, toolCallId: 'resume-lost', toolName: $resume ? 'agent_resume' : 'subagent', cancellationToken: new NullCancellationToken(), timeoutSeconds: 30, stepId: $resume ? 'resume-lost-step' : null, parentModel: 'test/model', launchContext: new ToolLaunchContextDTO(kind: ToolLaunchContextDTO::KIND_SUBAGENT, producingRunId: $owner, producingTurnNo: 1, producingModel: 'test/model', agentsContext: 'AGENTS')), static fn () => $resume ? $service->resume($owner, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue')) : $launch->launch($owner, 'scout', 'continue')));
            try {
                if ($interrupt) {
                    \Amp\Future\awaitFirst([$caller, $followUpCalled->getFuture()], new TimeoutCancellation(8));
                    $this->assertFalse($followUpCalled->isComplete(), 'Interrupted invocation must not dispatch followUp.');
                }
                if (!$interrupt) {
                    \Amp\Future\awaitFirst([$caller, $lost->getFuture()], new TimeoutCancellation(8));
                    $this->assertFalse($caller->isComplete());
                }
                $outcome = $caller->await(new TimeoutCancellation(8));
                $ownerTask->await(new TimeoutCancellation(8));
                $batch = $repository->findByLifecycleId($outcome->deferredId);
                $this->assertSame($interrupt ? DeferredSubagentBatchLaunchStatusEnum::Reserved : DeferredSubagentBatchLaunchStatusEnum::Launched, $batch?->launchStatus);
                $this->assertSame($interrupt, $dropSuccess);
                $this->assertSame($interrupt ? DeferredSubagentInterruptionKindEnum::ParentCancelled : null, $batch?->interruptionKind);
                if ($resume && $interrupt) {
                    $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($owner, $artifact)?->status);
                }
            } finally {
                $caller->ignore();
                $ownerTask->ignore();
                $publisher->reset();
                $requests->close();
                $sender->close();
            }
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function childLaunchOutcomes(): iterable
    {
        yield 'success' => [true];
        yield 'failure' => [false];
    }

    #[DataProvider('childLaunchOutcomes')]
    public function testChildMutationRedeliveryReplaysOnlyCommittedMetadata(bool $success): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $bash, BashRegistrationReplyPublisher $publisher) use ($success): void {
            $repository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
            $handler = new ChildInvocationLifecycleHandler(new ChildInvocationLifecycleService($repository), $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $replies = (new TransportFactory())->createTransport('sqlite-queue://bash_registration_reply_tool?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
            try {
                $tool = $success ? 'success' : 'failure';
                $factory = new DeferredSubagentBatchIdentityFactory();
                $id = $factory->batchLifecycleId($owner, $tool);
                $child = $factory->childIdentity($owner, $tool, 1);
                $intent = new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'task', 'model', 'medium');
                $commands = [new ReserveChildInvocationDTO($id, $owner, 1, $tool, 0, new \DateTimeImmutable('+1 minute'), $intent, 'model', str_repeat('b', 32), str_repeat('c', 32), BashRequesterRoleEnum::Tool)];
                $commands[] = $success
                    ? new RecordChildLaunchSuccessDTO($owner, $tool, $id, new \DateTimeImmutable(), str_repeat('b', 32), str_repeat('d', 32), BashRequesterRoleEnum::Tool)
                    : new RecordChildLaunchFailureDTO($owner, $tool, $id, str_repeat('b', 32), str_repeat('d', 32), BashRequesterRoleEnum::Tool);
                foreach ($commands as $command) {
                    $requests->send(new Envelope($command));
                    $delivery = iterator_to_array($requests->get())[0];
                    $handler($delivery->getMessage());
                    $first = iterator_to_array($replies->get())[0];
                    $replies->ack($first);
                    $committed = $repository->findByLifecycleId($id);
                    // Losing the reply leaves the same request leased. Native
                    // redelivery repeats metadata, never a launch callback.
                    $requests->wait(3000, new TimeoutCancellation(8));
                    $redelivery = iterator_to_array($requests->get())[0];
                    $handler($redelivery->getMessage());
                    $second = iterator_to_array($replies->get())[0];
                    $this->assertSame($id, $second->getMessage()->deferredId);
                    $this->assertEquals($committed, $repository->findByLifecycleId($id));
                    $replies->ack($second);
                    $requests->ack($redelivery);
                }
            } finally {
                $requests->close();
                $replies->close();
            }
        });
    }

    public function testGenericDeferredRegistrationRedeliveryKeepsTheCommittedChildIdentity(): void
    {
        // Native lease redelivery is the external contract; WAIT observes broker
        // eligibility and the test future deadline is only a failure cap.
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $bash, BashRegistrationReplyPublisher $publisher): void {
            $invocation = new DeferredToolCompletionCorrelation('canonical-child', $owner, 1, 'step', 1, 'key', 'call', 'subagent', [], 0);
            TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $invocation);
            $repository = self::getContainer()->get(DeferredToolCompletionRepository::class);
            $handler = new RegisterDeferredInvocationHandler($repository, $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $replies = (new TransportFactory())->createTransport('sqlite-queue://bash_registration_reply_tool?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
            try {
                foreach ([new RegisterDeferredInvocationDTO($invocation, str_repeat('b', 32), str_repeat('c', 32), BashRequesterRoleEnum::Tool)] as $command) {
                    $requests->send(new Envelope($command));
                    $delivery = iterator_to_array($requests->get())[0];
                    $handler($delivery->getMessage());
                    $first = iterator_to_array($replies->get())[0];
                    $this->assertSame($invocation->deferredId, $first->getMessage()->deferredId);
                    $replies->ack($first);
                    $ready = $repository->findMetadataByDeferredId($invocation->deferredId)?->readyAt;
                    $requests->wait(3000, new TimeoutCancellation(8));
                    $redelivery = iterator_to_array($requests->get())[0];
                    $handler($redelivery->getMessage());
                    $second = iterator_to_array($replies->get())[0];
                    $this->assertSame($invocation->deferredId, $second->getMessage()->deferredId);
                    $this->assertEquals($ready, $repository->findMetadataByDeferredId($invocation->deferredId)?->readyAt);
                    $replies->ack($second);
                    $requests->ack($redelivery);
                }
            } finally {
                $requests->close();
                $replies->close();
            }
        });
    }

    public function testSixteenChildReservationsAndLaunchSuccessRepliesUseTheToolPump(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $bash, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sent = 0;
            $saturated = new DeferredFuture();
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function () use (&$sent, $saturated): void {
                if (16 === ++$sent) {
                    $saturated->complete();
                }
            });
            $locator = new SendersLocator([ReserveChildInvocationDTO::class => ['run_control'], RecordChildLaunchSuccessDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]), $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
            $lifecycle = new ChildInvocationLifecycleService(self::getContainer()->get(DeferredSubagentBatchRepository::class));
            $handler = new ChildInvocationLifecycleHandler($lifecycle, $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $ownerTask = async(static function () use ($saturated, $requests, $handler): void {
                $saturated->getFuture()->await(new TimeoutCancellation(8));
                for ($handled = 0; $handled < 288;) {
                    foreach ($requests->get() as $delivery) {
                        $handler($delivery->getMessage());
                        $requests->ack($delivery);
                        ++$handled;
                    }
                    if ($handled < 288) {
                        $requests->wait(3000, new TimeoutCancellation(8));
                    }
                }
            });
            try {
                $pending = new \ReflectionProperty($client, 'pending');
                for ($round = 0; $round < 9; ++$round) {
                    $tasks = [];
                    for ($i = 0; $i < 16; ++$i) {
                        $tasks[] = async(static function () use ($client, $owner, $i): string {
                            $factory = new DeferredSubagentBatchIdentityFactory();
                            $tool = 'child-call-'.$i;
                            $id = $factory->batchLifecycleId($owner, $tool);
                            $child = $factory->childIdentity($owner, $tool, 1);
                            $intent = new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'task', 'model', 'medium');
                            $batch = $client->reserveChild($id, $owner, 1, $tool, 0, new \DateTimeImmutable('+1 minute'), $intent, 'model');
                            $client->recordChildLaunchSuccess($owner, $tool, $id, new \DateTimeImmutable());

                            return $batch->lifecycleId;
                        });
                    }
                    foreach ($tasks as $i => $task) {
                        $id = $task->await(new TimeoutCancellation(8));
                        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($id);
                        $this->assertNotNull($batch);
                        $this->assertSame('child-call-'.$i, $batch->parentToolCallId);
                        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
                    }
                    $this->assertSame([], $pending->getValue($client), 'Completed metadata replies must release their waiters.');
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame(288, $sent);
            } finally {
                $requests->close();
                $sender->close();
            }
        });
    }

    public function testCodeModeReadinessSharesTheIndependentToolReplyPump(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $bash, BashRegistrationReplyPublisher $publisher): void {
            $invocation = new DeferredToolCompletionCorrelation('canonical-code-mode', $owner, 1, 'step', 1, 'key', 'call', 'code_mode', [], 0);
            TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $invocation);
            self::getContainer()->get(DeferredToolCompletionRepository::class)->registerPending($invocation);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sent = 0;
            $saturated = new DeferredFuture();
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function () use (&$sent, $saturated): void {
                if (16 === ++$sent) {
                    $saturated->complete();
                }
            });
            $locator = new SendersLocator([ReadyCodeModeInvocationDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]), $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
            $lifecycle = new CodeModeLifecycleService(self::getContainer()->get(DeferredToolCompletionRepository::class));
            $handler = new CodeModeLifecycleHandler($lifecycle, $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $ownerTask = async(static function () use ($saturated, $requests, $handler): void {
                $saturated->getFuture()->await(new TimeoutCancellation(8));
                for ($handled = 0; $handled < 16;) {
                    foreach ($requests->get() as $delivery) {
                        $handler($delivery->getMessage());
                        $requests->ack($delivery);
                        ++$handled;
                    }
                    if ($handled < 16) {
                        $requests->wait(3000, new TimeoutCancellation(8));
                    }
                }
            });
            try {
                $tasks = [];
                for ($i = 0; $i < 16; ++$i) {
                    $tasks[] = async(static function () use ($client, $invocation): void {
                        $client->readyCodeMode($invocation->runId, $invocation->deferredId);
                    });
                }
                foreach ($tasks as $task) {
                    $task->await(new TimeoutCancellation(8));
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame(16, $sent);
                $this->assertNotNull(self::getContainer()->get(DeferredToolCompletionRepository::class)->findMetadataByDeferredId($invocation->deferredId)?->readyAt);
            } finally {
                $requests->close();
                $sender->close();
            }
        });
    }

    public function testCodeModeLostReadinessRepliesRedeliverOnlyMetadataAndKeepCanonicalIdentity(): void
    {
        // Native lease redelivery is the external contract; WAIT observes broker
        // eligibility and the test future deadline is only a failure cap.
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $bash, BashRegistrationReplyPublisher $publisher): void {
            $invocation = new DeferredToolCompletionCorrelation('canonical-code-mode', $owner, 1, 'step', 1, 'key', 'call', 'code_mode', [], 0);
            TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $invocation);
            $repository = self::getContainer()->get(DeferredToolCompletionRepository::class);
            $repository->registerPending($invocation);
            $handler = new CodeModeLifecycleHandler(new CodeModeLifecycleService($repository), $publisher, self::getContainer()->get(ValidatorInterface::class), $owner);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $replies = (new TransportFactory())->createTransport('sqlite-queue://bash_registration_reply_tool?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
            try {
                $command = new ReadyCodeModeInvocationDTO($owner, $invocation->deferredId, str_repeat('b', 32), str_repeat('d', 32), BashRequesterRoleEnum::Tool);
                $requests->send(new Envelope($command));
                $delivery = iterator_to_array($requests->get())[0];
                $handler($delivery->getMessage());
                $first = iterator_to_array($replies->get())[0];
                $this->assertSame($invocation->deferredId, $first->getMessage()->deferredId);
                $replies->ack($first);
                $ready = $repository->findMetadataByDeferredId($invocation->deferredId)?->readyAt;
                $requests->wait(3000, new TimeoutCancellation(8));
                $redelivery = iterator_to_array($requests->get())[0];
                $handler($redelivery->getMessage());
                $second = iterator_to_array($replies->get())[0];
                $this->assertSame($invocation->deferredId, $second->getMessage()->deferredId);
                $this->assertEquals($ready, $repository->findMetadataByDeferredId($invocation->deferredId)?->readyAt);
                $replies->ack($second);
                $requests->ack($redelivery);
            } finally {
                $requests->close();
                $replies->close();
            }
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function stopRequesterModes(): iterable
    {
        yield 'live waiter with lost reply' => [false];
        yield 'orphaned request without requester continuation' => [true];
    }

    #[DataProvider('stopRequesterModes')]
    public function testControllerAdoptsCommittedStopWhileFirstReplyIsLost(bool $orphaned): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner) use ($orphaned): void {
            $directory = TestDirectoryIsolation::createProjectTempDir('bash-stop-adoption');
            $config = new BackgroundProcessConfig(storageDir: $directory, stopGraceSeconds: 5);
            $logger = new TestLogger();
            $store = self::getContainer()->get(ProcessStore::class);
            $lifecycle = new ProcessLifecycle($config, $logger, new LockFactory(new FlockStore()));
            $manager = new BackgroundProcessManager($store, $lifecycle, $config, $logger);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator))]);
            $serializer = self::getContainer()->get(SerializerInterface::class);
            $validator = self::getContainer()->get(ValidatorInterface::class);
            $lost = new DeferredFuture();
            $allowReplay = new DeferredFuture();
            $dropFirst = true;
            $failingSerializer = $this->createStub(SerializerInterface::class);
            $failingSerializer->method('serialize')->willReturnCallback(static function (mixed $data, string $format, array $context = []) use ($serializer, &$dropFirst): string {
                if ($data instanceof BashRegistrationReplyDTO && BashRequesterRoleEnum::Tool === $data->role && $dropFirst) {
                    $dropFirst = false;
                    throw new \RuntimeException('injected first stop reply loss');
                }

                return $serializer->serialize($data, $format, $context);
            });
            $failingSerializer->method('deserialize')->willReturnCallback($serializer->deserialize(...));
            $publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $owner, $failingSerializer);
            $handler = new DecideBashProcessHandler($store, $lifecycle, self::getContainer()->get(RunLockManager::class), $publisher, $validator, $owner);
            $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator);
            $adopter = new BackgroundProcessManager($store, $lifecycle, $config, $logger);
            $adopter->useProcessOwner(new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator, BashRequesterRoleEnum::Controller));
            $pipes = [];
            foreach (['work', 'term-release', 'peer'] as $name) {
                $this->assertTrue(posix_mkfifo($directory.'/'.$name, 0600));
                $pipes[$name] = fopen($directory.'/'.$name, 'r+b');
                $this->assertIsResource($pipes[$name]);
            }
            try {
                // Group TERM and the wrapper's forwarded TERM can both reach
                // the command. Once cleanup starts, one release must suffice.
                $trap = 'trap "" TERM; : > '.escapeshellarg($directory.'/term').'; IFS= read -r release < '.escapeshellarg($directory.'/term-release').'; exit 0';
                $target = $manager->start('trap '.escapeshellarg($trap).' TERM; : > '.escapeshellarg($directory.'/ready').'; IFS= read -r work < '.escapeshellarg($directory.'/work'), $owner);
                $manager->markBackgroundedForRecord($target->id, $owner);
                $statusPath = $store->decide($target->id, $owner, BashProcessActionEnum::Observe, $lifecycle)->statusPath;
                $peer = $manager->start(': > '.escapeshellarg($directory.'/peer-ready').'; IFS= read -r peer < '.escapeshellarg($directory.'/peer'), $owner);
                $manager->markBackgroundedForRecord($peer->id, $owner);
                $deadline = hrtime(true) + 3_000_000_000;
                while ((!is_file($directory.'/ready') || !is_file($directory.'/peer-ready')) && hrtime(true) < $deadline) {
                    $this->assertTrue($lifecycle->isAlive($target->pid));
                    $this->assertTrue($lifecycle->isAlive($peer->pid));
                    delay(0.01);
                }
                $this->assertFileExists($directory.'/ready');
                $this->assertFileExists($directory.'/peer-ready');
                $ownerTask = async(static function () use ($requests, $handler, $lost, $allowReplay, $logger): void {
                    $firstCorrelation = null;
                    while (true) {
                        $requests->wait(1000, new TimeoutCancellation(8));
                        foreach ($requests->get() as $delivery) {
                            $command = $delivery->getMessage();
                            if ($command->correlation === $firstCorrelation && !$allowReplay->isComplete()) {
                                continue; // Keep the request retryable while adoption runs.
                            }
                            try {
                                $handler($command);
                            } catch (\RuntimeException $error) {
                                if ('injected first stop reply loss' !== $error->getMessage()) {
                                    throw $error;
                                }
                                $logger->info('test.intentional_first_reply_loss');
                                $firstCorrelation = $command->correlation;
                                $lost->complete();
                                continue;
                            }
                            $requests->ack($delivery);
                            if ($command->correlation === $firstCorrelation) {
                                return;
                            }
                        }
                    }
                });
                $continuations = 0;
                $requester = null;
                if ($orphaned) {
                    // Model the durable boundary after a requester has vanished:
                    // there is deliberately no waiter or native continuation.
                    $bus->dispatch(new BashProcessCommandDTO($target->id, $owner, BashProcessActionEnum::Stop, str_repeat('a', 32), str_repeat('b', 32), BashRequesterRoleEnum::Tool));
                } else {
                    $requester = async(static function () use ($client, $target, $owner, &$continuations): mixed {
                        $result = $client->decide($target->id, $owner, BashProcessActionEnum::Stop);
                        ++$continuations;

                        return $result;
                    });
                }
                $lost->getFuture()->await(new TimeoutCancellation(8));
                $this->assertFalse($requester?->isComplete() ?? false);
                $this->assertFileDoesNotExist($directory.'/term', 'Metadata alone never signals.');
                $adopter->refreshRecords($store->backgroundSnapshots($owner));
                $deadline = hrtime(true) + 3_000_000_000;
                while (!is_file($directory.'/term') && hrtime(true) < $deadline) {
                    $this->assertTrue($lifecycle->isAlive($target->pid));
                    delay(0.01);
                }
                $this->assertFileExists($directory.'/term', 'Adoption issues TERM before starting its own grace.');
                $this->assertTrue($lifecycle->isAlive($target->pid), 'The trap holds the target inside normal graceful cleanup.');
                $this->assertTrue($lifecycle->isAlive($peer->pid), 'The unrelated sibling remains held.');
                $this->assertFalse($requester?->isComplete() ?? false, 'Adoption cannot depend on first-reply redelivery or requester continuation.');
                fwrite($pipes['term-release'], "exit\n");
                fflush($pipes['term-release']);
                $deadline = hrtime(true) + 3_000_000_000;
                while (null === $lifecycle->readStatusFile($statusPath) && hrtime(true) < $deadline) {
                    delay(0.01);
                }
                $this->assertSame(0, $lifecycle->readStatusFile($statusPath), 'The target exits through TERM cleanup, not KILL.');
                $allowReplay->complete();
                if (null !== $requester) {
                    $this->assertTrue($requester->await(new TimeoutCancellation(8))->finished);
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame($orphaned ? 0 : 1, $continuations);
                $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$target->id]);
                $this->assertSame(1, (int) $row['stopped_by_user']);
                $this->assertNotNull($row['completion_notified_at']);
                $this->assertTrue($lifecycle->isAlive($peer->pid));
            } finally {
                if (isset($ownerTask)) {
                    $ownerTask->ignore();
                }
                if (isset($requester)) {
                    $requester->ignore();
                }
                foreach ($pipes as $pipe) {
                    fwrite($pipe, "cleanup\n");
                    fclose($pipe);
                }
                $manager->shutdownCleanup();
                $requests->close();
                $sender->close();
                $publisher->reset();
                TestDirectoryIsolation::removeDirectory($directory);
            }
        });
    }

    public function testSimultaneousRolesReceiveOnlyTheirRepliesAndOwnerProcessDecisions(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $bothSent = new DeferredFuture();
            $sent = 0;
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function (MessageSentToTransportsEvent $event) use (&$sent, $bothSent, $publisher, $owner): void {
                $command = $event->getEnvelope()->getMessage();
                if ($command instanceof RegisterBashProcessCommandDTO) {
                    // A stale incarnation is discarded only within its own role.
                    $publisher->publish(new BashRegistrationReplyDTO($owner, str_repeat('f', 32), $command->correlation, new BashRegistrationResultDTO(BashRegistrationStatusEnum::SessionMissing), role: $command->role));
                    if (3 === ++$sent) {
                        $bothSent->complete();
                    }
                }
            });
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control'], BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]);
            $serializer = self::getContainer()->get(SerializerInterface::class);
            $validator = self::getContainer()->get(ValidatorInterface::class);
            $decisions = new DecideBashProcessHandler(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(ProcessLifecycle::class), self::getContainer()->get(RunLockManager::class), $publisher, $validator, $owner);
            $ownerEvents = new EventDispatcher();
            $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler], BashProcessCommandDTO::class => [$decisions]]))]), $ownerEvents);
            $handled = 0;
            $ownerEvents->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled, $worker): void {
                if (9 === ++$handled) {
                    $worker->stop();
                }
            });
            $ownerTask = async(static function () use ($bothSent, $worker): void {
                $bothSent->getFuture()->await(new TimeoutCancellation(8));
                $worker->run(['sleep' => 0]);
            });
            $tasks = [];
            try {
                foreach (BashRequesterRoleEnum::cases() as $i => $role) {
                    $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator, $role);
                    $tasks[] = async(function () use ($client, $owner, $i, $role, $requests, $bus): int {
                        $registered = $client->register($this->request($owner, $i + 1));
                        $this->assertSame(BashRegistrationStatusEnum::Registered, $registered->status);
                        if (BashRequesterRoleEnum::Scheduler === $role) {
                            $manager = new BackgroundProcessManager(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(ProcessLifecycle::class), new BackgroundProcessConfig(), new TestLogger());
                            $subscriber = new BashWorkerLifecycleSubscriber(new ServiceLocator([
                                BackgroundProcessManager::class => static fn () => $manager,
                                'coding_agent.bash_scheduler_client' => static fn () => $client,
                            ]));
                            $subscriber->onStarted(new WorkerStartedEvent(new Worker(['scheduler_default' => $requests], $bus)));
                            $snapshot = $manager->findByRecordId($registered->recordId, $owner);
                            $this->assertNotNull($snapshot->finishedAt, 'Scheduler observations must reach the owner without native signalling.');
                        } else {
                            $snapshot = $client->decide($registered->recordId, $owner, BashProcessActionEnum::Stop);
                            $this->assertTrue($snapshot->finished, 'The fixture registers no real process.');
                            $this->assertFalse($snapshot->signalRequired);
                        }
                        $this->assertNotNull($client->decide($registered->recordId, $owner, BashProcessActionEnum::DeleteFinished));

                        return $snapshot->id;
                    });
                }
                $ids = array_map(static fn ($task): int => $task->await(new TimeoutCancellation(8)), $tasks);
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertNotSame($ids[0], $ids[1]);
                $this->assertCount(3, array_unique($ids));
                $this->assertSame(9, $handled);
                $this->assertSame([], iterator_to_array($requests->get()));
            } finally {
                $worker->stop();
                $requests->close();
                $sender->close();
            }
        });
    }

    public function testSixteenOccupiedToolSlotsReceiveCommittedRepliesWithoutAdmissionSlots(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sent = 0;
            $saturated = new DeferredFuture();
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function (MessageSentToTransportsEvent $event) use (&$sent, $saturated, $publisher, $owner): void {
                if (1 === ++$sent) {
                    $command = $event->getEnvelope()->getMessage();
                    self::assertInstanceOf(RegisterBashProcessCommandDTO::class, $command);
                    // A predecessor reply with a live correlation must not
                    // resolve this worker's waiter. It is ACKed as stale.
                    $publisher->publish(new BashRegistrationReplyDTO($owner, str_repeat('f', 32), $command->correlation, new BashRegistrationResultDTO(BashRegistrationStatusEnum::SessionMissing)));
                }
                if (16 === $sent) {
                    $saturated->complete();
                }
            });
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $requests]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]);
            $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
            $results = [];
            $toolBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [function (\stdClass $message) use ($client, $owner, &$results): void {
                $results[] = $client->register($this->request($owner, $message->id));
            }]]))]);
            $strategy = new FiberMessageExecutionStrategy($toolBus, 16);
            $handled = 0;
            $ownerEvents = new EventDispatcher();
            $ownerEvents->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled): void { ++$handled; });
            $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler]]))]), $ownerEvents);
            $ownerTask = async(static function () use ($saturated, $strategy, $worker): void {
                $saturated->getFuture()->await(new TimeoutCancellation(8));
                self::assertTrue($strategy->shouldPauseConsumption(), 'Every tool slot must be occupied before owner replies begin.');
                $worker->run(['sleep' => 0, 'stop_when_empty' => true]);
            });
            try {
                for ($id = 1; $id <= 16; ++$id) {
                    $strategy->execute(new Envelope((object) ['id' => $id]), 'tool', static function (): void {});
                }
                $settled = 0;
                $onHandled = static function (Envelope $envelope, string $transport, bool $acked, ?\Throwable $error) use (&$settled): void {
                    if (null !== $error) {
                        throw $error;
                    }
                    ++$settled;
                };
                while ($strategy->wait($onHandled)) {
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame(16, $sent);
                $this->assertSame(16, $settled);
                $this->assertSame(16, $handled);
                $this->assertCount(16, $results);
                $this->assertCount(16, array_unique(array_map(static fn ($result) => $result->recordId, $results)));
                foreach ($results as $result) {
                    $this->assertSame(BashRegistrationStatusEnum::Registered, $result->status);
                }
                $this->assertSame([], iterator_to_array($requests->get()), 'Native Worker ACKs all committed requests.');
            } finally {
                $worker->stop();
                $requests->close();
                $publisher->reset();
            }
        });
    }

    public function testLostCommittedReplyRedeliversOriginalOutcomeAfterProcessCleanup(): void
    {
        // Native broker lease expiry is the unique external contract here. WAIT
        // blocks on eligibility; the 8s cancellation is only a failure cap.
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $factory = new TransportFactory();
            $requests = $factory->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $replies = $factory->createTransport('sqlite-queue://bash_registration_reply_tool?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
            $cancel = new TimeoutCancellation(8);
            try {
                $request = new RegisterBashProcessCommandDTO($this->request($owner, 1), str_repeat('b', 32), str_repeat('c', 32));
                $requests->send(new Envelope($request));
                $delivery = iterator_to_array($requests->get())[0];
                $handler($delivery->getMessage());
                $first = iterator_to_array($replies->get())[0];
                $outcome = $first->getMessage();
                $this->assertInstanceOf(BashRegistrationReplyDTO::class, $outcome);
                // Lose the committed response and leave the original request
                // unacknowledged, as if its worker died before request settlement.
                $replies->ack($first);
                BackgroundProcessFixture::delete(self::getContainer(), $outcome->result->recordId);
                $requests->wait(3000, $cancel);
                $redelivery = iterator_to_array($requests->get())[0];
                $handler($redelivery->getMessage());
                $second = iterator_to_array($replies->get())[0];
                $replayed = $second->getMessage();
                $this->assertInstanceOf(BashRegistrationReplyDTO::class, $replayed);
                $this->assertSame($outcome->result->recordId, $replayed->result->recordId);
                $this->assertFalse($replayed->result->processExists);
                $this->assertNull(BackgroundProcessFixture::record(self::getContainer(), $outcome->result->recordId));
                $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
                $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt'));
                $replies->ack($second);
                $requests->ack($redelivery);
            } finally {
                $requests->close();
                $replies->close();
                $publisher->reset();
            }
        });
    }

    public function testOwnerRecoversAfterActualFlushFailureBeforeNextRegistration(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $connection = $manager->getConnection();
            $connection->executeStatement("CREATE TEMP TRIGGER reject_bash_registration BEFORE INSERT ON background_process WHEN NEW.pid = 2147483601 BEGIN SELECT RAISE(ABORT, 'registration flush failure'); END");
            $failed = $handled = 0;
            $events = new EventDispatcher();
            $events->addSubscriber(self::getContainer()->get(RunControlDoctrineFailureResetSubscriber::class));
            $events->addListener(WorkerMessageFailedEvent::class, static function () use (&$failed): void { ++$failed; });
            $events->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled): void { ++$handled; });
            try {
                for ($id = 1; $id <= 2; ++$id) {
                    $requests->send(new Envelope(new RegisterBashProcessCommandDTO($this->request($owner, $id), str_repeat('b', 32), \sprintf('%032x', $id))));
                }
                $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler]]))]), $events);
                $worker->run(['sleep' => 0, 'stop_when_empty' => true]);
                $this->assertSame(1, $failed);
                $this->assertSame(1, $handled);
                $this->assertTrue($manager->isOpen());
                $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt'));
                $this->assertSame(2147483602, (int) $connection->fetchOne('SELECT pid FROM background_process'));
            } finally {
                $connection->executeStatement('DROP TRIGGER reject_bash_registration');
                $requests->close();
            }
        });
    }

    /** @return iterable<string, array{BashRequesterRoleEnum, bool}> */
    public static function requesterRoles(): iterable
    {
        yield 'tool registration' => [BashRequesterRoleEnum::Tool, false];
        yield 'controller registration' => [BashRequesterRoleEnum::Controller, false];
        yield 'tool stop' => [BashRequesterRoleEnum::Tool, true];
        yield 'controller stop' => [BashRequesterRoleEnum::Controller, true];
        yield 'scheduler observation' => [BashRequesterRoleEnum::Scheduler, true];
    }

    #[DataProvider('requesterRoles')]
    public function testExhaustedOwnerRequestReportsUnknownOutcomeInsteadOfLeavingWaiterPending(BashRequesterRoleEnum $role, bool $decision): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher) use ($role, $decision): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control'], BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware($locator)]), $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class), $role);
            $subscriber = new BashLifecycleFailureSubscriber(new ServiceLocator([BashRegistrationReplyPublisher::class => static fn () => $publisher]), $owner);
            $ownerTask = async(static function () use ($requests, $subscriber): void {
                $requests->wait(3000, new TimeoutCancellation(8));
                foreach ($requests->get() as $delivery) {
                    $retry = new WorkerMessageFailedEvent($delivery, 'run_control', new \RuntimeException('owner write failed'));
                    $retry->setForRetry();
                    $subscriber->onFailed($retry);
                    $subscriber->onFailed(new WorkerMessageFailedEvent($delivery, 'run_control', new \RuntimeException('owner retries exhausted')));
                    $requests->reject($delivery);
                }
            });
            try {
                try {
                    if ($decision) {
                        $client->decide(1, $owner, BashProcessActionEnum::Stop);
                    } else {
                        $client->register($this->request($owner, 1));
                    }
                    $this->fail('A terminal owner error must not report a successful lifecycle decision.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('committed outcome is unknown', $error->getMessage());
                }
                $ownerTask->await(new TimeoutCancellation(8));
            } finally {
                $requests->close();
                $sender->close();
            }
        });
    }

    /**
     * @param list<array{toolCallId: string, orderIndex: int, args: array<string, mixed>}> $calls
     */
    private function registerAgentResumeBatch(string $parentRunId, int $turnNo, string $stepId, array $calls): void
    {
        $toolCalls = [];
        foreach ($calls as $call) {
            $toolCalls[] = new ExecuteToolCall(
                runId: $parentRunId,
                turnNo: $turnNo,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', $parentRunId.'|'.$stepId.'|'.$call['toolCallId']),
                toolCallId: $call['toolCallId'],
                toolName: 'agent_resume',
                args: $call['args'],
                orderIndex: $call['orderIndex'],
                parentModel: 'test/model',
            );
        }
        TestToolBatchRegistration::register(
            new ToolBatchCollector(self::getContainer()->get(ToolBatchStoreInterface::class)),
            self::getContainer()->get(ToolBatchStoreInterface::class),
            $parentRunId,
            $turnNo,
            $stepId,
            $toolCalls,
        );
    }

    private function withBroker(callable $proof): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-registration');
        $console = \dirname(__DIR__, 4).'/bin/console';
        $executable = $this->createStub(AppExecutableLocator::class);
        $executable->method('path')->willReturn($console);
        $executable->method('command')->willReturn([\PHP_BINARY, \dirname(__DIR__, 2).'/Runtime/Messenger/Fixtures/short-lease-broker.php', $console]);
        $broker = new SessionSqliteQueueBrokerSupervisor(new TestLogger(), new RuntimeProcessConfig($executable, $directory));
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $paths = SessionSqliteQueuePaths::forSession($directory.'/.hatfield/sessions', $owner);
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $locks = self::getContainer()->get(RunLockManager::class);
        $registration = new BashRegistrationService(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(ProcessStore::class), $locks, $serializer, $validator);
        $publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $owner, $serializer);
        $handler = new RegisterBashProcessHandler($registration, $publisher, $locks, $validator, $owner);
        try {
            $broker->start($paths);
            // Bound the proof at its test-owned future, not through a client API.
            // On failure, broker teardown below releases transport waiters.
            $proofTask = async($proof, $paths, $owner, $handler, $publisher);
            try {
                $proofTask->await(new TimeoutCancellation(8));
            } finally {
                // The await already reports failure; a late transport error
                // during broker teardown must not become an unhandled future.
                $proofTask->ignore();
            }
        } finally {
            $publisher->reset();
            $broker->shutdownUntil(microtime(true) + 5.0);
            if (isset($proofTask) && !$proofTask->isComplete()) {
                try {
                    // Broker disconnect releases blocked client waiters. Join
                    // their finally blocks before PHPUnit destroys the kernel.
                    $proofTask->await(new TimeoutCancellation(1));
                } catch (\Throwable $error) {
                    // The original proof deadline already failed the test.
                    fwrite(\STDERR, 'Expected interrupted proof cleanup: '.$error->getMessage()."\n");
                }
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function request(string $owner, int $id): RegisterBashProcessDTO
    {
        return new RegisterBashProcessDTO(\sprintf('%032x', $id), $owner, $owner, 2147483600 + $id, 2147483600 + $id, 'not launched', null, null, false, '/not-launched/'.$id.'.log', '/not-launched/'.$id.'.status', new \DateTimeImmutable('2026-10-27T10:00:00+00:00'));
    }
}
