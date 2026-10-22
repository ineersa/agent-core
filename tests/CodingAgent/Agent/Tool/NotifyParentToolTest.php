<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Tests\Support\AgentCommunicationToolProof;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Assert;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

final class NotifyParentToolTest extends IsolatedKernelTestCase
{
    public function testConfiguredNoticeReachesParentWithoutSettlingTheChildWait(): void
    {
        AgentCommunicationToolProof::exercise(self::getContainer(), new SubmissionFixture(self::getContainer()), true);
    }

    public function testSchemaDoesNotExposeDestinationOrControlOptions(): void
    {
        $tools = self::getContainer()->get(ToolboxInterface::class)->getTools();
        $tool = array_find($tools, static fn ($tool): bool => 'notify_parent' === $tool->getName());
        Assert::assertNotNull($tool);
        Assert::assertSame(['text'], array_keys($tool->getParameters()['properties']));
        Assert::assertSame(['text'], $tool->getParameters()['required']);
        Assert::assertArrayNotHasKey('pattern', $tool->getParameters()['properties']['text']);
    }

    public function testExplicitChildAllowlistDeniesNoticeBeforeNativeSubmission(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c, ['read']);
        Assert::assertNotContains('notify_parent', $c->get(ToolSetResolverInterface::class)->resolve('current', runId: $f->child)->toolNames);
        $call = new ExecuteToolCall($f->child, 1, 'step', 1, 'denied', 'notice', 'notify_parent', ['text' => 'Important guidance'], 0, toolsRef: 'current');
        $result = ($c->get(ExecuteToolCallWorker::class))($call);
        Assert::assertNotNull($result);
        Assert::assertTrue($result->isError);
        Assert::assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->statusForRunAndToolCall($f->child, 'notice'));
    }

    public function testInheritAllAndExplicitPolicyCannotOverrideExclusionsOrEnableNestedLaunches(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $registry = $c->get(ToolRegistryInterface::class);
        $registry->setExcludedToolNames(['notify_parent']);
        try {
            $resolver = $c->get(AgentToolPolicyResolver::class);
            foreach ([null, ['notify_parent', 'agent_steer', 'subagent', 'fork', 'agent_resume', 'read']] as $tools) {
                $policy = $resolver->resolve(new AgentDefinitionDTO('probe', 'probe', $tools), $f->parent);
                // Explicit policy retains authored names; actual registry visibility
                // and per-run intersection must still win over that request.
                $effective = array_intersect($policy['tools'], $registry->activeToolNames());
                foreach (['notify_parent', 'agent_steer', 'subagent', 'fork', 'agent_resume'] as $forbidden) {
                    Assert::assertNotContains($forbidden, $effective);
                }
            }
            Assert::assertNotContains('notify_parent', $c->get(ToolSetResolverInterface::class)->resolve('current', runId: $f->child)->toolNames);
        } finally {
            $registry->setExcludedToolNames([]);
        }
    }
}
