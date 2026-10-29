<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class SubmissionFactoryTest extends IsolatedKernelTestCase
{
    public function testDeterministicIdentityBindsTextAndCurrentResumeActivity(): void
    {
        $f = new SubmissionFixture(self::getContainer());
        $first = $f->prepare();
        $outcome = self::getContainer()->get(SubmissionFactory::class)->defer(new ToolContext($f->parent, 1, 'steer', 'agent_steer', new NullCancellationToken(), null, stepId: 'step'), ['text' => 'Important guidance', 'artifact_id' => $f->artifact]);
        Assert::assertSame($first->identity, $outcome->deferredId);
        Assert::assertEquals($first, $f->prepare());
        Assert::assertNotSame($first->identity, $f->prepare(text: 'Different guidance')->identity);
        $f->completeActiveChildClaim();
        self::getContainer()->get(DeferredSubagentBatchRepository::class)->reserveBatch('new-resume', $f->parent, 1, 'resume-call', 0, new \DateTimeImmutable('+1 hour'), new DeferredSubagentBatchChildIntentDTO(1, $f->child, $f->artifact, 'scout', 'new task', 'model', 'medium'));
        $f->append($f->parent, 4, 1, 'llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'resume-call'], ['id' => 'steer']]]]);
        // Rebinding uses native lifecycle metadata, not an invented run generation.
        Assert::assertNotSame($first->identity, $f->prepare()->identity);
    }

    public function testForeignHandleAndForgedNoticeDestinationAreRejected(): void
    {
        $f = new SubmissionFixture(self::getContainer());
        $factory = self::getContainer()->get(SubmissionFactory::class);
        try {
            $factory->prepare($f->parent, 1, 'step', 'steer', 'agent_steer', ['text' => 'x', 'agent_run_id' => 'foreign-child']);
            Assert::fail('Foreign handle accepted.');
        } catch (ToolCallException $e) {
            Assert::assertStringContainsString('Unknown direct child', $e->getMessage());
        }
        $this->expectException(ToolCallException::class);
        $factory->prepare($f->child, 1, 'step', 'notice', 'notify_parent', ['text' => 'x', 'agent_run_id' => 'foreign-parent']);
    }

    public function testCancelledChildAndPreviousParentLifetimeAreRejected(): void
    {
        $f = new SubmissionFixture(self::getContainer());
        $f->status($f->child, RunStatus::Cancelled);
        try {
            $f->prepare();
            Assert::fail('Cancelled child accepted.');
        } catch (ToolCallException $e) {
            Assert::assertStringContainsString('active child', $e->getMessage());
        }
        $f->status($f->child, RunStatus::Running);
        self::getContainer()->get(AgentArtifactRegistry::class)->beginParentLifetime($f->parent);
        $this->expectException(ToolCallException::class);
        $f->prepare();
    }

    public function testForegroundNoticeCannotWakeCompletedParentButBackgroundNoticeCan(): void
    {
        $f = new SubmissionFixture(self::getContainer());
        Assert::assertSame($f->parent, $f->prepare(notice: true)->parentRunId);
        $f->append($f->parent, 4, 1, 'tool_batch_committed');
        $f->append($f->parent, 5, 1, 'agent_end', ['status' => 'completed']);
        $f->status($f->parent, RunStatus::Completed);
        try {
            $f->prepare(notice: true);
            Assert::fail('Foreground child revived completed parent.');
        } catch (ToolCallException $e) {
            Assert::assertStringContainsString('Parent activity', $e->getMessage());
        }
        self::getContainer()->get(DeferredSubagentBatchRepository::class)->markBackgrounded($f->lifecycle, new \DateTimeImmutable());
        Assert::assertNotNull(self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($f->lifecycle)->backgroundedAt);
        Assert::assertNotNull(self::getContainer()->get(CanonicalToolInvocationReader::class)->find($f->parent, 'spawn', requireCurrentStep: false));
        Assert::assertTrue($f->prepare(notice: true)->backgrounded);
        Assert::assertNull(self::getContainer()->get(CanonicalToolInvocationReader::class)->find($f->parent, 'spawn'));
        $f->append($f->parent, 6, 0, 'history_position_set', ['position_turn_no' => 0, 'reason' => 'history_select']);
        $this->expectException(ToolCallException::class);
        $f->prepare(notice: true);
    }

    public function testUtf8ByteLimitAndBlankTextAreValidated(): void
    {
        $f = new SubmissionFixture(self::getContainer());
        Assert::assertSame(8192, \strlen($f->prepare(text: str_repeat('é', 4096))->text));
        foreach ([str_repeat('é', 4097), '   ', "\xff"] as $text) {
            try {
                $f->prepare(text: $text);
                Assert::fail('Invalid text accepted.');
            } catch (ValidationFailedException $e) {
                Assert::assertGreaterThan(0, $e->getViolations()->count());
            }
        }
    }
}
