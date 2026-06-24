<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Routes built-in {@see ExecuteToolCall} for tool name "subagent" to the dedicated
 * agent transport so foreground subagent orchestration does not occupy generic
 * tool workers (which child agents need for read/write/shell/etc.).
 *
 * Runs on agent.execution.bus before send_message. Skips envelopes that already
 * carry ReceivedStamp or an explicit TransportNamesStamp (e.g. MCP middleware).
 */
final readonly class SubagentExecuteToolCallRoutingMiddleware implements MiddlewareInterface
{
    public const string SUBAGENT_TOOL_NAME = 'subagent';

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof ExecuteToolCall) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (null !== $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (self::SUBAGENT_TOOL_NAME !== $message->toolName) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $stack->next()->handle(
            $envelope->with(new TransportNamesStamp(['agent'])),
            $stack,
        );
    }
}
