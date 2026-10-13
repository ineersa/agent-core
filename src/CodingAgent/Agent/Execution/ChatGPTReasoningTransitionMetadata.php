<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/** Host-only request markers; never provider wire options. */
final class ChatGPTReasoningTransitionMetadata
{
    public const string KEY = 'hatfield.reasoning_transition';
    public const string MESSAGE_KEY = 'hatfield.reasoning_anchor';
    public const string ENABLED = 'hatfield_reasoning_transitions';
    public const string UPDATE = 'hatfield_reasoning_update';
}
