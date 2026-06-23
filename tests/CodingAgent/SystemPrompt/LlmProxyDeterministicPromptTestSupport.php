<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\SystemPrompt\LlmProxyDeterministicPromptMode;

final class LlmProxyDeterministicPromptTestSupport
{
    public static function disabledMode(): LlmProxyDeterministicPromptMode
    {
        return new LlmProxyDeterministicPromptMode();
    }
}
