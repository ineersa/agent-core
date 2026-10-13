<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use PHPUnit\Framework\TestCase;

final class AiProviderConfigTest extends TestCase
{
    public function testChatGPTTypePreservesOpaqueProviderIdWithoutTransportSettings(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'chatgpt',
            'transport' => 'sse',
        ], 'openai-codex');

        $this->assertSame('chatgpt', $config->type);
        $this->assertSame('openai-codex', $config->id);
        $this->assertFalse(property_exists($config, 'transport'));
        $this->assertFalse(property_exists($config, 'websocketCacheIdleTtlSeconds'));
    }
}
