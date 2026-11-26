<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RuntimeException;

final class ProviderCassetteHttpClientFactoryTest extends TestCase
{
    public function testDefaultTransportFailsClosedWithoutCassette(): void
    {
        $this->expectException(RuntimeException::class);
        ProviderCassetteHttpClientFactory::offline()->request('POST', 'https://model.invalid/v1/chat/completions');
    }

    public function testMismatchedRequestDoesNotReceiveRecordedResponse(): void
    {
        $this->expectException(RuntimeException::class);
        ProviderCassetteHttpClientFactory::offline('extension-agent-tool-drain')->request(
            'POST',
            'https://llama-cassette.invalid/v1/chat/completions',
            ['json' => ['model' => 'flash', 'messages' => [['role' => 'user', 'content' => 'Unexpected prompt']]]],
        );
    }
}
