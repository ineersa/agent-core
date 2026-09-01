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
 * Thesis: passive attach must not dispatch AgentCore run-control methods when reopening a session.
 *
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::attach
 */
#[CoversMethod(InProcessAgentSessionClient::class, 'attach')]
final class InProcessAttachDoesNotContinueTest extends IsolatedKernelTestCase
{
    private RecordingNoopAgentRunner $spyRunner;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::getContainer()->set(AgentRunnerInterface::class, new RecordingNoopAgentRunner());
    }

    protected function setUp(): void
    {
        parent::setUp();
        /** @var RecordingNoopAgentRunner $runner */
        $runner = self::getContainer()->get(AgentRunnerInterface::class);
        $this->spyRunner = $runner;
        $this->spyRunner->calls = [];
    }

    public function testAttachDoesNotInvokeRunnerMutators(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);

        $handle = $client->attach('session-attach-42');

        $this->assertSame('session-attach-42', $handle->runId);
        $this->assertSame('attached', $handle->status);
        $this->assertSame([], $this->spyRunner->calls, 'attach must not call AgentRunnerInterface mutators');
    }
}

/**
 * @internal
 */
final class RecordingNoopAgentRunner implements AgentRunnerInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function start(StartRunInput $input): string
    {
        $this->calls[] = 'start';

        return $input->runId ?? 'run';
    }

    public function shell(string $runId, string $rawInput): void
    {
        $this->calls[] = 'shell';
    }

    public function steer(string $runId, AgentMessage $message): void
    {
        $this->calls[] = 'steer';
    }

    public function followUp(string $runId, AgentMessage $message): void
    {
        $this->calls[] = 'followUp';
    }

    public function appendMessage(string $runId, AgentMessage $message): void
    {
        $this->calls[] = 'appendMessage';
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
        $this->calls[] = 'cancel';
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
        $this->calls[] = 'answerHuman';
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->calls[] = 'compact';
    }
}
