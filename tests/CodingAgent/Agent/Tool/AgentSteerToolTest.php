<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Tests\Support\AgentCommunicationToolProof;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Assert;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class AgentSteerToolTest extends IsolatedKernelTestCase
{
    public function testConfiguredToolReachesRecipientThroughNativeDeferredSourceAndOwnerBoundary(): void
    {
        AgentCommunicationToolProof::exercise(self::getContainer(), new SubmissionFixture(self::getContainer()), false);
    }

    public function testSchemaAndRoleExposureRespectTheChildAllowlist(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c, ['notify_parent', 'agent_steer', 'subagent', 'fork', 'agent_resume']);
        $tools = $c->get(ToolboxInterface::class)->getTools();
        $steer = array_find($tools, static fn ($tool): bool => 'agent_steer' === $tool->getName());
        Assert::assertNotNull($steer);
        $schema = $steer->getParameters();
        Assert::assertSame(['text', 'artifact_id', 'agent_run_id'], array_keys($schema['properties']));
        Assert::assertSame(['text'], $schema['required']);
        Assert::assertArrayNotHasKey('pattern', $schema['properties']['text']);
        $resolver = $c->get(ToolSetResolverInterface::class);
        Assert::assertContains('agent_steer', $resolver->resolve('current', runId: $f->parent)->toolNames);
        Assert::assertNotContains('notify_parent', $resolver->resolve('current', runId: $f->parent)->toolNames);
        $childTools = $resolver->resolve('current', runId: $f->child)->toolNames;
        Assert::assertContains('notify_parent', $childTools);
        Assert::assertNotContains('agent_steer', $childTools);
    }

    public function testNativeValidationRejectsInvalidTextHandlesAndNestedExecution(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $toolbox = new FaultTolerantToolbox($c->get(ToolboxInterface::class));
        $context = $c->get(StackToolExecutionContextAccessor::class);
        $native = new ToolContext($f->parent, 1, 'steer', 'agent_steer', new NullCancellationToken(), null, stepId: 'step');
        foreach ([['text' => 'x'], ['text' => 'x', 'artifact_id' => $f->artifact, 'agent_run_id' => $f->child], ['text' => ' ', 'artifact_id' => $f->artifact], ['text' => str_repeat('é', 4097), 'artifact_id' => $f->artifact], ['text' => "\xff", 'artifact_id' => $f->artifact]] as $args) {
            $result = $context->with($native, static fn () => $toolbox->execute(new ToolCall('steer', 'agent_steer', $args)));
            Assert::assertInstanceOf(ConstraintViolationListInterface::class, $result->getResult());
            Assert::assertGreaterThan(0, $result->getResult()->count());
        }
        Assert::assertNull($c->get(ToolRegistryInterface::class)->toolDefinition('messages'));
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not supported inside code_mode');
        $context->with($native, static fn () => $context->with($native, static fn () => $toolbox->execute(new ToolCall('steer', 'agent_steer', ['text' => 'x', 'artifact_id' => $f->artifact]))));
    }

    public function testParentAllowlistCannotBeOverriddenByCommunication(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $registry = $c->get(ToolRegistryInterface::class);
        $registry->setAllowedToolNames(['read']);
        try {
            $request = new ExecuteToolCall($f->parent, 1, 'step', 1, 'denied-steer', 'steer', 'agent_steer', ['text' => 'Important guidance', 'artifact_id' => $f->artifact], 0, toolsRef: 'current');
            $result = ($c->get(ExecuteToolCallWorker::class))($request);
            Assert::assertNotNull($result);
            Assert::assertTrue($result->isError);
            Assert::assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->statusForRunAndToolCall($f->parent, 'steer'));
        } finally {
            $registry->setAllowedToolNames([]);
        }
    }
}
