<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;

final class CodexWebSocketUrlResolverTest extends TestCase
{
    public function testResolvesHttpsToWssWithResponsesPath(): void
    {
        $resolver = new CodexWebSocketUrlResolver();

        $this->assertSame(
            'wss://chatgpt.com/backend-api/codex/responses',
            $resolver->resolve('https://chatgpt.com/backend-api', '/codex/responses'),
        );
    }
}
