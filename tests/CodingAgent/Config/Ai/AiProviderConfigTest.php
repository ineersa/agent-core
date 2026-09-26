<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use PHPUnit\Framework\TestCase;

final class AiProviderConfigTest extends TestCase
{
    public function testTransportFromArray(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
            'transport' => 'sse',
        ], 'openai-codex');

        $this->assertSame('sse', $config->transport);
    }
}
