<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModelClient;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as HttpResponse;

final class CodexModelClientTest extends TestCase
{
    public function testItWrapsHttpClientInEventSourceHttpClient(): void
    {
        $httpClient = new MockHttpClient();
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertInstanceOf(CodexModelClient::class, $modelClient);
    }

    public function testItAcceptsEventSourceHttpClientDirectly(): void
    {
        $httpClient = new EventSourceHttpClient(new MockHttpClient());
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertInstanceOf(CodexModelClient::class, $modelClient);
    }

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
            self::assertSame('{"temperature":1,"model":"gpt-5.5","input":[{"role":"user","content":"test message"}]}', $options['body']);

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
            self::assertSame(
                '{"temperature":0.7,"text":{"format":{"name":"foo","schema":[],"type":"json"}},"model":"gpt-5.5","input":[{"role":"user","content":"Hello"}]}',
                $options['body'],
            );

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
            $body = \json_decode($options['body'], true);
            self::assertArrayNotHasKey('_agent_core_invocation', $body);
            self::assertArrayNotHasKey('_hatfield_suppress_developer_role', $body);
            self::assertArrayNotHasKey('stream', $body);
            self::assertArrayNotHasKey('tools_ref', $body);
            self::assertArrayNotHasKey('turn_no', $body);
            self::assertArrayNotHasKey('run_id', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            '_agent_core_invocation' => ['some' => 'metadata'],
            '_hatfield_suppress_developer_role' => true,
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
            $body = \json_decode($options['body'], true);
            // Valid Codex API keys must be preserved
            self::assertArrayHasKey('reasoning', $body);
            self::assertSame('high', $body['reasoning']['effort']);
            self::assertArrayHasKey('temperature', $body);
            self::assertSame(0.5, $body['temperature']);
            self::assertArrayHasKey('model', $body);
            self::assertSame('gpt-5.5', $body['model']);
            self::assertArrayHasKey('input', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            'reasoning' => ['effort' => 'high', 'summary' => 'auto'],
            'temperature' => 0.5,
            'stream' => true, // should be stripped
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
            $body = \json_decode($options['body'], true);
            // Internal keys stripped
            self::assertArrayNotHasKey('stream', $body);
            self::assertArrayNotHasKey('_hatfield_suppress_developer_role', $body);
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
            ['stream' => true, '_hatfield_suppress_developer_role' => true],
        );
    }
}
