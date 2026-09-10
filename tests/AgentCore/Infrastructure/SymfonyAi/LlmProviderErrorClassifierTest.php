<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Amp\CancelledException;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
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
    public function testLocalAndCancelledFailuresRemainNonRetryable(string $type, string $category): void
    {
        $result = $this->classifier->classify(['type' => $type, 'message' => 'arbitrary provider prose']);

        $this->assertFalse($result['retryable']);
        $this->assertSame($category, $result['error_category']);
        $this->assertStringNotContainsString('arbitrary provider prose', $result['user_message']);
    }

    public static function permanentExceptionProvider(): array
    {
        return [
            [CancelledException::class, LlmProviderErrorClassifier::CATEGORY_UNKNOWN],
            [\TypeError::class, LlmProviderErrorClassifier::CATEGORY_UNKNOWN],
        ];
    }

    #[DataProvider('httpStatusProvider')]
    public function testHttpStatusClassification(int $status, string $category, bool $retryable): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => 'server_error overloaded quota context length',
            'http_status_code' => $status,
        ]);

        $this->assertSame($retryable, $result['retryable']);
        $this->assertSame($category, $result['error_category']);
    }

    public static function httpStatusProvider(): array
    {
        return [
            [400, LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, true],
            [401, LlmProviderErrorClassifier::CATEGORY_AUTH, true],
            [403, LlmProviderErrorClassifier::CATEGORY_AUTH, true],
            [408, LlmProviderErrorClassifier::CATEGORY_TIMEOUT, true],
            [429, LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, true],
            [500, LlmProviderErrorClassifier::CATEGORY_SERVER, true],
            [503, LlmProviderErrorClassifier::CATEGORY_SERVER, true],
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

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
    }

    public function testHttp400AndAuthExceptionsAreRetryable(): void
    {
        $bad = $this->classifier->classify([
            'type' => BadRequestException::class,
            'message' => 'gateway html',
            'http_status_code' => 400,
        ]);
        $this->assertTrue($bad['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $bad['error_category']);

        $auth = $this->classifier->classify([
            'type' => AuthenticationException::class,
            'message' => 'unauthorized',
        ]);
        $this->assertTrue($auth['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $auth['error_category']);
    }

    public function testTimeoutAndTransportExceptionsAreRetryable(): void
    {
        $timeout = $this->classifier->classify([
            'type' => TimeoutException::class,
            'message' => 'Idle timeout reached for https://api.example/chat',
        ]);
        $this->assertTrue($timeout['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_TIMEOUT, $timeout['error_category']);

        $transport = $this->classifier->classify([
            'type' => TransportException::class,
            'message' => 'Connection closed before HTTP/2 settings could be received',
        ]);
        $this->assertTrue($transport['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_NETWORK, $transport['error_category']);
    }

    public function testCodexIdleTimeoutMessageIsRetryableTimeout(): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => 'Codex WebSocket idle timeout.',
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_TIMEOUT, $result['error_category']);
        $this->assertSame('LLM provider request timed out.', $result['user_message']);
        $this->assertSame('Codex WebSocket idle timeout.', $result['message']);
    }

    public function testUnknownFailureWithoutTimeoutTokensStaysGenericRetryableProvider(): void
    {
        $result = $this->classifier->classify([
            'type' => \RuntimeException::class,
            'message' => '[server_error/server_error] overloaded please try again',
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
