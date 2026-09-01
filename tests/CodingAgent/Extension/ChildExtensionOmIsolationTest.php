<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobWorker;
use Ineersa\CodingAgent\Extension\ExtensionAfterTurnCommitHookSubscriber;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionRegistrationContext;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test thesis: when OM is registered process-wide but absent from a child's
 * durable allowlist, after-turn hooks and extension-agent jobs do not run for
 * that child (no OM child side effects).
 */
final class ChildExtensionOmIsolationTest extends TestCase
{
    public function testAfterTurnHookSkippedForChildWithoutOm(): void
    {
        $registry = new ExtensionHookRegistry();
        $ran = false;

        ExtensionRegistrationContext::withOwner(ObservationalMemoryExtension::class, static function () use ($registry, &$ran): void {
            $registry->addAfterTurnCommitHook(new class($ran) implements AfterTurnCommitHookInterface {
                public function __construct(private bool &$ran)
                {
                }

                public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
                {
                    $this->ran = true;
                }
            });
        });

        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('firstFor')->willReturn(new RunEvent(
            runId: 'child-om-1',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 's',
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_om',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => []],
                        // SafeGuard only — OM absent.
                        'extensions' => [
                            'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                        ],
                    ],
                ],
            ],
        ));

        $subscriber = new ExtensionAfterTurnCommitHookSubscriber(
            $registry,
            new NullLogger(),
            new RunStartedMetadataReader($eventStore, AttributeSerializerValidatorTestFactory::denormalizer()),
        );

        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'child-om-1',
            turnNo: 1,
            status: 'completed',
            events: [],
            effectsCount: 0,
            runState: new RunState('child-om-1', RunStatus::Completed, turnNo: 1),
        ));

        $this->assertFalse($ran, 'OM after-turn hook must not run for child without OM selection');
    }

    public function testExtensionAgentJobSkippedForChildWithoutOm(): void
    {
        $jobs = new ExtensionAgentJobRegistry();
        $handled = false;

        ExtensionRegistrationContext::withOwner(ObservationalMemoryExtension::class, static function () use ($jobs, &$handled): void {
            $jobs->register('om.job', new class($handled) implements ExtensionAgentJobHandlerInterface {
                public function __construct(private bool &$handled)
                {
                }

                public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
                {
                    $this->handled = true;
                }
            });
        });

        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('firstFor')->willReturn(new RunEvent(
            runId: 'child-om-2',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 's',
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_om',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => []],
                        'extensions' => [
                            'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                        ],
                    ],
                ],
            ],
        ));

        $worker = new ExtensionAgentJobWorker(
            $jobs,
            $this->createStub(ExtensionApiInterface::class),
            new NullLogger(),
            new RunStartedMetadataReader($eventStore, AttributeSerializerValidatorTestFactory::denormalizer()),
        );

        $worker(new ExtensionAgentJobMessage(
            handlerId: 'om.job',
            payload: ['run_id' => 'child-om-2'],
            jobId: 'job-1',
            correlationId: 'child-om-2',
        ));

        $this->assertFalse($handled, 'OM extension-agent job must not run for child without OM selection');
    }
}
