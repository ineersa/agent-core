<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Origin of a question request.
 *
 * - Tui: local UI question (e.g. "Move to background?"), resolved by a
 *   local callback. Never persisted to transcript or runtime events.
 * - AgentCore: human-in-the-loop question originating from the agent
 *   (e.g. ask_human tool). Persisted to transcript; answer dispatched
 *   via answer_human runtime command.
 */
enum QuestionSource: string
{
    case Tui = 'tui';
    case AgentCore = 'agent_core';
}
