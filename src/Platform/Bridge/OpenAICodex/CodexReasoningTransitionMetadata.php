<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * History-bound Astra reasoning transition marker.
 *
 * Stored on the MessageBag entry that starts the request segment that should
 * see the new effort. CodexMessageBagNormalizer emits a configuration_update
 * immediately before that message's provider items.
 */
final class CodexReasoningTransitionMetadata
{
    public const string KEY = 'codex_reasoning_effort';

    private function __construct()
    {
    }
}
