<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use PHPUnit\Framework\TestCase;

final class AiProviderConfigTest extends TestCase
{
    public function testFromArrayParseAuthKey(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
            'auth_key' => 'openai-codex-work',
        ], 'test-codex');

        self::assertSame('openai-codex-work', $config->authKey);
    }

    public function testFromArrayAuthKeyDefaultsToNull(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
        ], 'test-codex');

        self::assertNull($config->authKey);
    }

    public function testFromArrayEmptyAuthKeyParsesAsEmptyString(): void
    {
        $config = AiProviderConfig::fromArray([
            'type' => 'codex',
            'auth_key' => '',
        ], 'test-codex');

        // fromArray casts to string, so empty string is set
        self::assertSame('', $config->authKey);
    }
}
