<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Thesis: passive attach must not dispatch AgentCore Continue when reopening a session.
 *
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::attach
 */
#[CoversMethod(InProcessAgentSessionClient::class, 'attach')]
final class InProcessAttachDoesNotContinueTest extends IsolatedKernelTestCase
{
    private ContinueCountingAgentRunner $spyRunner;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::getContainer()->set(AgentRunnerInterface::class, new ContinueCountingAgentRunner());
    }

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ContinueCountingAgentRunner $runner */
        $runner = self::getContainer()->get(AgentRunnerInterface::class);
        $this->spyRunner = $runner;
        $this->spyRunner->continueCount = 0;
    }

    public function testAttachDoesNotInvokeRunnerContinue(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);

        $handle = $client->attach('session-attach-42');

        $this->assertSame('session-attach-42', $handle->runId);
        $this->assertSame('attached', $handle->status);
        $this->assertSame(0, $this->spyRunner->continueCount, 'attach must not call AgentRunnerInterface::continue');
    }
}

/**
 * @internal
 */
final class ContinueCountingAgentRunner implements AgentRunnerInterface
{
    public int $continueCount = 0;

    public function start(StartRunInput $input): string
    {
        return $input->runId ?? 'run';
    }

    public function continue(string $runId): void
    {
        ++$this->continueCount;
    }

    public function shell(string $runId, string $rawInput): void
    {
    }

    public function steer(string $runId, AgentMessage $message): void
    {
    }

    public function followUp(string $runId, AgentMessage $message): void
    {
    }

    public function appendMessage(string $runId, AgentMessage $message): void
    {
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }

    public function changeModel(string $runId, string $model): void
    {
    }
}
