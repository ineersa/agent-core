<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Continuation semantics after a human answers a pending request.
 *
 * ModelTurn: append the answer to transcript and AdvanceRun (ask_human).
 * ToolCall: reserved for exact original tool-call resume (future slice).
 */
enum HumanInputContinuationKindEnum: string
{
    case ModelTurn = 'model_turn';
    case ToolCall = 'tool_call';
}
