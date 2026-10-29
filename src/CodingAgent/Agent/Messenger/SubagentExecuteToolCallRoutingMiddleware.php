<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Routes built-in {@see ExecuteToolCall} for tool names "subagent" and "agent_resume"
 * to the agent transport. One shared Fiber consumer reads both tool and agent
 * queues, so retained agent messages still execute after controller restart.
 *
 * Runs on agent.execution.bus before send_message. Skips envelopes that already
 * carry ReceivedStamp or an explicit TransportNamesStamp (e.g. MCP middleware).
 */
final readonly class SubagentExecuteToolCallRoutingMiddleware implements MiddlewareInterface
{
    public const string SUBAGENT_TOOL_NAME = 'subagent';
    public const string AGENT_RESUME_TOOL_NAME = 'agent_resume';

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        $toolName = $message instanceof ExecuteToolCall ? $message->toolName : ($message instanceof ToolPayloadMessage && 'call' === $message->reference->kind ? $message->reference->toolName : null);
        if (null === $toolName) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (null !== $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!\in_array($toolName, [self::SUBAGENT_TOOL_NAME, self::AGENT_RESUME_TOOL_NAME], true)) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $stack->next()->handle(
            $envelope->with(new TransportNamesStamp(['agent'])),
            $stack,
        );
    }
}
