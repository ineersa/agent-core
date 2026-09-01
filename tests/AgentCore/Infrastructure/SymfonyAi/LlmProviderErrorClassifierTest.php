<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Amp\CancelledException;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

final class LlmProviderErrorClassifierTest extends TestCase
{
    private LlmProviderErrorClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new LlmProviderErrorClassifier();
    }

    #[DataProvider('permanentExceptionProvider')]
    public function testKnownPermanentExceptionsAreNotRetryable(string $type, string $category): void
    {
        $result = $this->classifier->classify(['type' => $type, 'message' => 'arbitrary provider prose']);

        $this->assertFalse($result['retryable']);
        $this->assertSame($category, $result['error_category']);
        $this->assertStringNotContainsString('arbitrary provider prose', $result['user_message']);
    }

    public static function permanentExceptionProvider(): array
    {
        return [
            [AuthenticationException::class, LlmProviderErrorClassifier::CATEGORY_AUTH],
            [BadRequestException::class, LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST],
            [ContentFilterException::class, LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST],
            [ExceedContextSizeException::class, LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST],
            [CancelledException::class, LlmProviderErrorClassifier::CATEGORY_UNKNOWN],
            [\TypeError::class, LlmProviderErrorClassifier::CATEGORY_UNKNOWN],
            [TimeoutException::class, LlmProviderErrorClassifier::CATEGORY_TIMEOUT],
            [TransportException::class, LlmProviderErrorClassifier::CATEGORY_NETWORK],
        ];
    }

    #[DataProvider('httpStatusProvider')]
    public function testHttpFailuresAreTerminalAfterSymfonyRetries(int $status, string $category): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => 'server_error overloaded quota context length',
            'http_status_code' => $status,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame($category, $result['error_category']);
    }

    public static function httpStatusProvider(): array
    {
        return [
            [400, LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST],
            [401, LlmProviderErrorClassifier::CATEGORY_AUTH],
            [403, LlmProviderErrorClassifier::CATEGORY_AUTH],
            [408, LlmProviderErrorClassifier::CATEGORY_TIMEOUT],
            [429, LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT],
            [500, LlmProviderErrorClassifier::CATEGORY_SERVER],
            [503, LlmProviderErrorClassifier::CATEGORY_SERVER],
        ];
    }

    #[DataProvider('transientStreamExceptionProvider')]
    public function testTypedMidStreamFailuresAreRetryableByMessengerTransport(string $type, string $category): void
    {
        $result = $this->classifier->classify(['type' => $type, 'message' => 'provider prose is not inspected']);

        $this->assertTrue($result['retryable']);
        $this->assertSame($category, $result['error_category']);
    }

    public static function transientStreamExceptionProvider(): array
    {
        return [
            [ServerException::class, LlmProviderErrorClassifier::CATEGORY_SERVER],
            [RateLimitExceededException::class, LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT],
        ];
    }

    public function testPermanentStatusOverridesTransientExceptionType(): void
    {
        $result = $this->classifier->classify([
            'type' => ServerException::class,
            'http_status_code' => 403,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
    }

    public function testUnknownProviderOperationFailureIsRetryableWithoutMessageMatching(): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => 'Codex WebSocket idle timeout.',
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
        $this->assertSame('LLM provider request failed.', $result['user_message']);
        $this->assertSame('Codex WebSocket idle timeout.', $result['message']);
    }

    public function testUnknownFailureDoesNotInspectMessageTextForClassification(): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => '[server_error/server_error] overloaded timeout please try again',
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
        $this->assertSame('LLM provider request failed.', $result['user_message']);
    }

    public function testClassifierPreservesStructuredDiagnosticsAndStripsFreeTextHelpers(): void
    {
        $result = $this->classifier->classify([
            'type' => ServerException::class,
            'response_error_code' => 'server_error',
            'response_error_type' => 'server_error',
            'response_error_message' => 'provider prose',
            'response_body_preview' => 'raw body',
            'previous_exception_message' => 'transport prose',
        ]);

        $this->assertSame('server_error', $result['response_error_code']);
        $this->assertSame('server_error', $result['response_error_type']);
        $this->assertArrayNotHasKey('response_error_message', $result);
        $this->assertArrayNotHasKey('response_body_preview', $result);
        $this->assertArrayNotHasKey('previous_exception_message', $result);
    }
}
