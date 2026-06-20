<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiProviderConfigTest extends TestCase
{
    public function testFromArrayParseAuthKey(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
            'auth_key' => 'openai-codex-work',
        ], 'test-codex');

        $this->assertSame('openai-codex-work', $config->authKey);
    }

    public function testFromArrayAuthKeyDefaultsToNull(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
        ], 'test-codex');

        $this->assertNull($config->authKey);
    }

    public function testFromArrayEmptyAuthKeyParsesAsEmptyString(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
            'auth_key' => '',
        ], 'test-codex');

        // fromArray casts to string, so empty string is set
        $this->assertSame('', $config->authKey);
    }
}
