<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for TickPollListener cancellation behavior.
 *
 * The cancel wedge bug (Issue 1 from PR #162 smoke test):
 * handleChoiceToolQuestion's onCancel sent 'answer' => '' (empty string),
 * which AnswerToolQuestionHandler rejected with ProtocolError without writing
 * to the store → poll loop wedged forever (null === pollAnswerText).
 *
 * Fix: 'answer' => 'cancel' (non-empty generic cancel sentinel).
 */
final class TickPollListenerTest extends TestCase
{
    /**
     * Test thesis: when the Choice overlay onCancel fires, the TUI sends
     * answer_tool_question with 'answer' => 'cancel' (non-empty), so
     * AnswerToolQuestionHandler accepts it, writes to the shared store,
     * and the blocking poll in the tool consumer breaks — no hang.
     *
     * If the answer were '' (empty string), this test would fail because
     * the handler rejects empty answers and the poll wedges forever.
     */
    public function testChoiceOnCancelSendsNonEmptyCancelAnswer(): void
    {
        $sentCommand = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-1'),
                $this->callback(function (UserCommand $cmd) use (&$sentCommand): bool {
                    $sentCommand = $cmd;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();

        // Use reflection to invoke the private static handleChoiceToolQuestion
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleChoiceToolQuestion');

        $ref->invoke(null, [
            'prompt' => 'Approve write outside CWD?',
            'request_id' => 'rq_test',
            'tool_call_id' => 'tc_test',
            'tool_name' => 'write',
        ], [
            'type' => 'string',
            'enum' => ['Proceed', 'Abort'],
        ], 'tool_rq_test', 'run-1', 'rq_test', $client, $coordinator);

        // The QuestionCoordinator should now have an active request
        $this->assertTrue($coordinator->actionRequired(), 'Coordinator must have active request after enqueue');

        // Cancel it — this fires the onCancel closure set up by handleChoiceToolQuestion
        $coordinator->cancel();

        // Assert the sent UserCommand has the non-empty cancel answer
        $this->assertNotNull($sentCommand, 'Expected UserCommand to be sent on cancel');
        $this->assertSame('answer_tool_question', $sentCommand->type);
        $this->assertSame('rq_test', $sentCommand->payload['request_id'] ?? null);
        $this->assertSame('cancel', $sentCommand->payload['answer'] ?? null);
        $this->assertNotEmpty($sentCommand->payload['answer'] ?? '', 'Cancel answer must be non-empty to prevent poll wedge');
    }
}
