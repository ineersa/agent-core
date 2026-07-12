<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModelClient;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as HttpResponse;

final class CodexModelClientTest extends TestCase
{
    public function testItSupportsCodexModel(): void
    {
        $modelClient = new CodexModelClient(new MockHttpClient(), 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertTrue($modelClient->supports(new CodexModel('gpt-5.5')));
    }

    public function testItDoesNotSupportOtherModels(): void
    {
        $modelClient = new CodexModelClient(new MockHttpClient(), 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertFalse($modelClient->supports(new Model('test-model')));
    }

    public function testItIsExecutingTheCorrectRequest(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/codex/responses', $url);
            self::assertSame('Authorization: Bearer test-access-token', $options['normalized_headers']['authorization'][0]);
            self::assertSame('chatgpt-account-id: acct-123', $options['normalized_headers']['chatgpt-account-id'][0]);
            self::assertSame('originator: hatfield', $options['normalized_headers']['originator'][0]);
            self::assertSame('OpenAI-Beta: responses=experimental', $options['normalized_headers']['openai-beta'][0]);
            self::assertArrayHasKey('x-client-request-id', $options['normalized_headers']);

            $body = json_decode($options['body'], true);
            self::assertSame('POST', $method);
            self::assertSame('gpt-5.5', $body['model']);
            self::assertSame('test message', $body['input'][0]['content']);
            self::assertSame(1, $body['temperature']);
            self::assertFalse($body['store']);
            self::assertTrue($body['stream']);
            self::assertSame('low', $body['text']['verbosity']);
            self::assertSame(['reasoning.encrypted_content'], $body['include']);
            self::assertSame('auto', $body['tool_choice']);
            self::assertTrue($body['parallel_tool_calls']);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test message']]],
            ['temperature' => 1],
        );
    }

    public function testItUsesCustomResponsesPath(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/custom/responses', $url);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123', '/custom/responses');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    public function testItHandlesStructuredOutputOption(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/codex/responses', $url);

            $body = json_decode($options['body'], true);
            // Verify structured output fields are preserved
            self::assertSame('json', $body['text']['format']['type']);
            self::assertSame('foo', $body['text']['format']['name']);
            // Verify verbosity is merged alongside format
            self::assertSame('low', $body['text']['verbosity']);

            return new MockResponse();
        };

        $options = [
            'temperature' => 0.7,
            'response_format' => [
                'type' => 'json',
                'json_schema' => [
                    'name' => 'foo',
                    'schema' => [],
                ],
            ],
        ];

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            $options,
        );
    }

    public function testItUsesCustomOriginator(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('originator: my-app', $options['normalized_headers']['originator'][0]);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123', '/codex/responses', 'my-app');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    public function testItStripsInternalHatfieldKeysFromBody(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);
            self::assertArrayNotHasKey('_agent_core_invocation', $body);
            self::assertArrayNotHasKey('_hatfield_reasoning', $body);
            // stream is NOT stripped — it is a valid Codex API field and is preserved
            self::assertTrue($body['stream']);
            self::assertArrayNotHasKey('tools_ref', $body);
            self::assertArrayNotHasKey('turn_no', $body);
            self::assertArrayNotHasKey('run_id', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            '_agent_core_invocation' => ['some' => 'metadata'],
            '_hatfield_reasoning' => 'medium',
            'stream' => true,
            'tools_ref' => 'toolset-1',
            'turn_no' => 1,
            'run_id' => 'run-abc',
            'temperature' => 0.7,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            $options,
        );
    }

    public function testItPreservesValidCodexApiKeysInBody(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);
            // Valid Codex API keys must be preserved
            self::assertArrayHasKey('reasoning', $body);
            self::assertSame('high', $body['reasoning']['effort']);
            self::assertArrayHasKey('temperature', $body);
            self::assertSame(0.5, $body['temperature']);
            self::assertArrayHasKey('model', $body);
            self::assertSame('gpt-5.5', $body['model']);
            self::assertArrayHasKey('input', $body);
            // stream is preserved (not stripped) — valid Codex field
            self::assertTrue($body['stream']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            'reasoning' => ['effort' => 'high', 'summary' => 'auto'],
            'temperature' => 0.5,
            'stream' => true,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            $options,
        );
    }

    public function testItStripsInternalKeysWhilePreservingPayloadAndModel(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);
            // Internal keys stripped (_hatfield_ prefix)
            self::assertArrayNotHasKey('_hatfield_reasoning', $body);
            // stream is preserved (valid Codex API field)
            self::assertTrue($body['stream']);
            // Model and payload preserved
            self::assertSame('gpt-5.4-mini', $body['model']);
            self::assertSame('Hello world', $body['input'][0]['content']);
            self::assertSame('user', $body['input'][0]['role']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $modelClient->request(
            new CodexModel('gpt-5.4-mini'),
            ['input' => [['role' => 'user', 'content' => 'Hello world']]],
            ['stream' => true, '_hatfield_reasoning' => 'medium'],
        );
    }

    public function testItIncludesCodexRequiredDefaultsInBody(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);

            // Codex Responses API required fields
            self::assertFalse($body['store']);
            self::assertTrue($body['stream']);
            self::assertSame('low', $body['text']['verbosity']);
            self::assertSame(['reasoning.encrypted_content'], $body['include']);
            self::assertSame('auto', $body['tool_choice']);
            self::assertTrue($body['parallel_tool_calls']);

            // Internal keys stripped
            self::assertArrayNotHasKey('_agent_core_invocation', $body);
            self::assertArrayNotHasKey('_hatfield_reasoning', $body);
            self::assertArrayNotHasKey('tools_ref', $body);
            self::assertArrayNotHasKey('turn_no', $body);
            self::assertArrayNotHasKey('run_id', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            '_agent_core_invocation' => ['some' => 'data'],
            '_hatfield_reasoning' => 'medium',
            'tools_ref' => 'toolset-1',
            'turn_no' => 1,
            'run_id' => 'run-abc',
            'temperature' => 0.5,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test']]],
            $options,
        );
    }

    public function testCodexDefaultsDoNotOverrideExplicitValues(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);

            // Explicit values must not be overridden by defaults
            self::assertTrue($body['store']);
            self::assertSame('high', $body['text']['verbosity']);
            self::assertSame(['custom_feature'], $body['include']);
            self::assertSame('manual', $body['tool_choice']);
            self::assertFalse($body['parallel_tool_calls']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            'store' => true,
            'text' => ['verbosity' => 'high'],
            'include' => ['custom_feature'],
            'tool_choice' => 'manual',
            'parallel_tool_calls' => false,
            'stream' => true,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test']]],
            $options,
        );
    }

    public function testLogsRequestSummaryOnRequest(): void
    {
        $loggedContext = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->identicalTo('llm.provider.request_prepared'),
                $this->callback(function (array $context) use (&$loggedContext): bool {
                    $loggedContext = $context;

                    // Must include structural metadata
                    $this->assertArrayHasKey('body_keys', $context);
                    $this->assertArrayHasKey('input_count', $context);
                    $this->assertArrayHasKey('input_types', $context);
                    $this->assertArrayHasKey('model', $context);
                    $this->assertArrayHasKey('has_instructions', $context);
                    $this->assertArrayHasKey('has_stream', $context);
                    $this->assertArrayHasKey('has_include', $context);
                    $this->assertArrayHasKey('has_store', $context);
                    $this->assertArrayHasKey('tool_count', $context);
                    $this->assertArrayHasKey('originator', $context);

                    // Model name is safe
                    $this->assertSame('gpt-5.5', $context['model']);

                    // Must NOT contain sensitive data (keys sampled)
                    $contextStr = implode(' ', (array) $context);
                    $this->assertStringNotContainsString('test-access-token', $contextStr);
                    $this->assertStringNotContainsString('test prompt', $contextStr);
                    $this->assertStringNotContainsString('test-access', $contextStr);

                    return true;
                }),
            );

        $httpClient = new MockHttpClient([static function () {
            return new MockResponse();
        }]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-access-token',
            'acct-123',
            '/codex/responses',
            'hatfield',
            $logger,
        );

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test prompt']]],
            ['temperature' => 1],
        );

        // Additional structural assertions on the captured context
        $this->assertNotNull($loggedContext);
        $this->assertStringContainsString('input', $loggedContext['body_keys']);
        $this->assertStringContainsString('model', $loggedContext['body_keys']);
        $this->assertSame(1, $loggedContext['input_count']);
        $this->assertStringContainsString('user', $loggedContext['input_types']);
        $this->assertTrue($loggedContext['has_store']);
        $this->assertTrue($loggedContext['has_stream']);
        $this->assertSame('hatfield', $loggedContext['originator']);

        // Must contain new diagnostics fields
        $this->assertArrayHasKey('has_client_request_id', $loggedContext);
    }

    /**
     * When run_id is provided in options, prompt_cache_key must be set
     * in the request body so Codex can cache the prompt across turns.
     * Pi-mono: prompt_cache_key = sessionId.
     */
    public function testItSetsPromptCacheKeyFromRunId(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('prompt_cache_key', $body);
            self::assertSame('session-abc-123', $body['prompt_cache_key']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            ['run_id' => 'session-abc-123'],
        );
    }

    /**
     * Without run_id, prompt_cache_key must NOT be present.
     */
    public function testItDoesNotSetPromptCacheKeyWithoutRunId(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('prompt_cache_key', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    /**
     * Explicit prompt_cache_key from payload must NOT be overridden by run_id.
     * run_id uses ??= so an explicit value wins.
     */
    public function testExplicitPromptCacheKeyOverridesRunIdValue(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('prompt_cache_key', $body);
            self::assertSame('explicit-key', $body['prompt_cache_key'], 'Explicit value must win over run_id');

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']], 'prompt_cache_key' => 'explicit-key'],
            ['run_id' => 'session-abc-123'],
        );
    }

    /**
     * Test thesis: a long-lived worker recovers from an expired/revoked token via
     * one force-refresh + retry, without manual auth:codex --refresh.
     */
    public function testRefreshesAndRetriesOnceOn401(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
            static function (string $method, string $url, array $options) use (&$requestCount): HttpResponse {
                ++$requestCount;
                self::assertSame('Authorization: Bearer new-token', $options['normalized_headers']['authorization'][0]);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'hatfield',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(200, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    public function test401WhenRefreshReturnsNullDoesNotRetry(): void
    {
        $requestCount = 0;
        $refresher = static function (): ?string {
            return null;
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'hatfield',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);
    }

    public function testPersistent401AfterRetryDoesNotLoop(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'hatfield',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    /**
     * Test thesis: a failed refresh (revoked token) is logged and degrades gracefully
     * to the original 401 — no retry, no silent swallow.
     */
    public function test401WhenRefresherThrowsDoesNotRetryAndLogsRefreshFailed(): void
    {
        $requestCount = 0;
        $refresher = static function (): ?string {
            throw new \RuntimeException('refresh token revoked');
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $logger = new TestLogger();
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'hatfield',
            $logger,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);

        $refreshFailed = null;
        foreach ($logger->records as $record) {
            if ('codex.token.refresh_failed' === $record['message']) {
                $refreshFailed = $record;
                break;
            }
        }
        $this->assertNotNull($refreshFailed, 'Expected codex.token.refresh_failed warning log');
        $this->assertSame('warning', $refreshFailed['level']);
        $this->assertSame('codex.token.refresh_failed', $refreshFailed['context']['event_type']);
        $this->assertSame('codex_model_client', $refreshFailed['context']['component']);
        $this->assertSame(\RuntimeException::class, $refreshFailed['context']['exception_class']);
    }

    public function test401WithoutRefresherPassesThrough(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);
    }
}
