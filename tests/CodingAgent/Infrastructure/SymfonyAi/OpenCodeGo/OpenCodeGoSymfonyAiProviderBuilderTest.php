<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\OpenCodeGo;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\OpenCodeGo\OpenCodeGoSymfonyAiProviderBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;

final class OpenCodeGoSymfonyAiProviderBuilderTest extends TestCase
{
    #[DataProvider('missingKeys')]
    public function testMissingEnvironmentKeyRetainsDiagnostic(?string $environment, string $model): void
    {
        $previous = getenv('HATFIELD_TEST_GO_MISSING_KEY');
        putenv(null === $environment ? 'HATFIELD_TEST_GO_MISSING_KEY' : 'HATFIELD_TEST_GO_MISSING_KEY='.$environment);
        $http = new MockHttpClient();
        try {
            $provider = (new OpenCodeGoSymfonyAiProviderBuilder(new EventDispatcher(), new NullLogger()))->build(new AiProviderConfig(
                id: 'opencode-go', type: 'opencode-go', baseUrl: 'https://example.test', apiKey: 'env:HATFIELD_TEST_GO_MISSING_KEY',
                models: [$model => new AiModelDefinition(id: $model)],
            ), $http);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('OpenCode Go API key environment variable HATFIELD_TEST_GO_MISSING_KEY is not set.');
            $provider->invoke($model, new MessageBag(Message::ofUser('Hello')))->getResult();
        } finally {
            putenv(false === $previous ? 'HATFIELD_TEST_GO_MISSING_KEY' : 'HATFIELD_TEST_GO_MISSING_KEY='.$previous);
            $this->assertSame(0, $http->getRequestsCount());
        }
    }

    public static function missingKeys(): iterable
    {
        yield 'completions unset' => [null, 'model'];
        yield 'completions empty' => ['', 'model'];
        yield 'responses unset' => [null, 'muse-spark-1.3-contributor'];
        yield 'responses empty' => ['', 'muse-spark-1.3-contributor'];
    }
}
