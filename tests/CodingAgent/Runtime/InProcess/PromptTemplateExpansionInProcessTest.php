<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient
 *
 * Tests prompt-template expansion at the in-process runtime boundary.
 * Uses the test kernel with a fake AgentRunnerInterface spy injected
 * via container override so we can assert on expanded prompt text.
 *
 * Uses {@see PerMethodIsolatedKernelTestCase} (per-method kernel boot)
 * because the InProcessAgentSessionClient (shared service) caches template
 * directory contents.  With per-class kernel boot, templates written by
 * later test methods are invisible to the already-booted client.
 */
final class PromptTemplateExpansionInProcessTest extends PerMethodIsolatedKernelTestCase
{
    /** @var FakeCapturingAgentRunner */
    private FakeCapturingAgentRunner $spyRunner;

    /** @var FakeCapturingToolExecutor */
    private FakeCapturingToolExecutor $spyToolExecutor;

    protected function afterKernelBoot(): void
    {
        // Install spies before any test code resolves the real services.
        $this->spyRunner = new FakeCapturingAgentRunner();
        $this->spyToolExecutor = new FakeCapturingToolExecutor();

        self::getContainer()->set(AgentRunnerInterface::class, $this->spyRunner);
        self::getContainer()->set(ToolExecutorInterface::class, $this->spyToolExecutor);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function writeTemplate(string $name, string $content): void
    {
        // Write to the isolated project dir's .hatfield/prompts/
        $dir = $this->isolatedCwd().'/.hatfield/prompts';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($dir.'/'.$name.'.md', $content);
    }

    private function client(): InProcessAgentSessionClient
    {
        /** @var InProcessAgentSessionClient */
        return self::getContainer()->get(InProcessAgentSessionClient::class);
    }

    private function userMessageTexts(StartRunInput $input): array
    {
        $texts = [];
        foreach ($input->messages as $msg) {
            if ('user' === $msg->role) {
                foreach ($msg->content as $block) {
                    if (\is_array($block) && 'text' === ($block['type'] ?? '')) {
                        $texts[] = (string) ($block['text'] ?? '');
                    }
                }
            }
        }

        return $texts;
    }

    private function msgText(AgentMessage $msg): string
    {
        foreach ($msg->content as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? '')) {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    // ── start() expansion ──────────────────────────────────────────

    public function testStartExpandsPromptTemplate(): void
    {
        $this->writeTemplate('review', "Review changes focusing on:\n\$ARGUMENTS");

        $this->client()->start(new StartRunRequest(prompt: '/review security performance'));

        self::assertNotNull($this->spyRunner->lastStartInput);
        $texts = $this->userMessageTexts($this->spyRunner->lastStartInput);
        self::assertCount(1, $texts);
        self::assertSame("Review changes focusing on:\nsecurity performance", $texts[0]);
    }

    public function testStartPassthroughForNonTemplateSlash(): void
    {
        $this->client()->start(new StartRunRequest(prompt: '/nonexistent arg'));

        self::assertNotNull($this->spyRunner->lastStartInput);
        $texts = $this->userMessageTexts($this->spyRunner->lastStartInput);
        self::assertSame(['/nonexistent arg'], $texts);
    }

    public function testStartPassthroughForNonSlash(): void
    {
        $this->client()->start(new StartRunRequest(prompt: 'hello world'));

        self::assertNotNull($this->spyRunner->lastStartInput);
        $texts = $this->userMessageTexts($this->spyRunner->lastStartInput);
        self::assertSame(['hello world'], $texts);
    }

    public function testStartSinglePassNoRecursiveExpansion(): void
    {
        $this->writeTemplate('first', '/second arg');
        $this->writeTemplate('second', 'expanded: $@');

        $this->client()->start(new StartRunRequest(prompt: '/first'));

        self::assertNotNull($this->spyRunner->lastStartInput);
        $texts = $this->userMessageTexts($this->spyRunner->lastStartInput);
        self::assertCount(1, $texts);
        self::assertSame('/second arg', $texts[0]);
    }

    // ── send() expansion ───────────────────────────────────────────

    public function testSendExpandsMessageType(): void
    {
        $this->writeTemplate('review', 'Review: $@');

        $this->client()->send('run-1', new UserCommand(type: 'message', text: '/review foo bar'));

        self::assertCount(1, $this->spyRunner->steerMessages);
        self::assertSame('Review: foo bar', $this->msgText($this->spyRunner->steerMessages[0]));
    }

    public function testSendExpandsSteerType(): void
    {
        $this->writeTemplate('review', 'Review: $@');

        $this->client()->send('run-1', new UserCommand(type: 'steer', text: '/review baz'));

        self::assertCount(1, $this->spyRunner->steerMessages);
        self::assertSame('Review: baz', $this->msgText($this->spyRunner->steerMessages[0]));
    }

    public function testSendExpandsFollowUpType(): void
    {
        $this->writeTemplate('review', 'Follow-up: $@');

        $this->client()->send('run-1', new UserCommand(type: 'follow_up', text: '/review qux'));

        self::assertCount(1, $this->spyRunner->followUpMessages);
        self::assertSame('Follow-up: qux', $this->msgText($this->spyRunner->followUpMessages[0]));
    }

    public function testAnswerHumanIsNotExpanded(): void
    {
        $this->writeTemplate('review', 'expanded:$@');

        $this->client()->send('run-1', new UserCommand(
            type: 'answer_human',
            text: '/review yes',
            payload: ['question_id' => 'q1', 'answer' => true],
        ));

        self::assertCount(1, $this->spyRunner->answerHumanCalls);
        self::assertSame('q1', $this->spyRunner->answerHumanCalls[0]['questionId']);
        self::assertTrue($this->spyRunner->answerHumanCalls[0]['answer']);
    }

    public function testAnswerToolQuestionIsNotExpanded(): void
    {
        $this->writeTemplate('review', 'expanded:$@');

        // No ToolQuestionStore is injected into the container, so the
        // answer_tool_question path will throw. But we can still verify
        // the branch is reached BEFORE any expansion would happen by
        // catching the exception and checking no steer/followUp were made.
        try {
            $this->client()->send('run-1', new UserCommand(
                type: 'answer_tool_question',
                text: '/review yes',
                payload: ['request_id' => 'req-1', 'answer' => true],
            ));
        } catch (\RuntimeException $e) {
            // Expected: ToolQuestionStore not configured
            self::assertStringContainsString('ToolQuestionStore', $e->getMessage());
        }

        // No expansion should have leaked into steer/followUp.
        self::assertCount(0, $this->spyRunner->steerMessages);
        self::assertCount(0, $this->spyRunner->followUpMessages);
    }

    // ── send() passthrough ────────────────────────────────────────

    public function testSendPassthroughForNonMatchingSlash(): void
    {
        $this->writeTemplate('review', 'expanded:$@');

        // Non-matching slash command passes through unchanged.
        $this->client()->send('run-1', new UserCommand(type: 'message', text: '/nonexistent foo'));

        self::assertCount(1, $this->spyRunner->steerMessages);
        self::assertSame('/nonexistent foo', $this->msgText($this->spyRunner->steerMessages[0]));
    }

    // ── shell_command non-expansion ────────────────────────────────

    public function testShellCommandIsNotExpanded(): void
    {
        $this->writeTemplate('review', 'expanded:$@');

        // shell_command text must NOT be expanded — the raw command
        // text is passed to the tool executor unchanged.
        $this->client()->send('run-1', new UserCommand(
            type: 'shell_command',
            text: '/review rm -rf',
        ));

        self::assertNotNull($this->spyToolExecutor->lastToolCall);
        self::assertSame('/review rm -rf', $this->spyToolExecutor->lastToolCall->arguments['command']);
    }
}

/**
 * @internal
 */
final class FakeCapturingAgentRunner implements AgentRunnerInterface
{
    public ?StartRunInput $lastStartInput = null;

    /** @var list<AgentMessage> */
    public array $steerMessages = [];

    /** @var list<AgentMessage> */
    public array $followUpMessages = [];
    /** @var list<AgentMessage> */
    public array $appendMessages = [];

    /** @var list<array{questionId: string, answer: mixed}> */
    public array $answerHumanCalls = [];

    /** Clear captured state between test methods. */
    public function reset(): void
    {
        $this->lastStartInput = null;
        $this->steerMessages = [];
        $this->followUpMessages = [];
        $this->answerHumanCalls = [];
    }

    public function start(StartRunInput $input): string
    {
        $this->lastStartInput = $input;

        return 'test-run-id';
    }

    public function continue(string $runId): void
    {
    }

    public function steer(string $runId, AgentMessage $message): void
    {
        $this->steerMessages[] = $message;
    }

    public function followUp(string $runId, AgentMessage $message): void
    {
        $this->followUpMessages[] = $message;
    }

    public function appendMessage(string $runId, AgentMessage $message): void
    {
        $this->appendMessages[] = $message;
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
        $this->answerHumanCalls[] = ['questionId' => $questionId, 'answer' => $answer];
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}

/**
 * @internal
 */
final class FakeCapturingToolExecutor implements ToolExecutorInterface
{
    public ?ToolCall $lastToolCall = null;

    /** Clear captured state between test methods. */
    public function reset(): void
    {
        $this->lastToolCall = null;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->lastToolCall = $toolCall;

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [['type' => 'text', 'text' => 'ok']],
            isError: false,
        );
    }
}
