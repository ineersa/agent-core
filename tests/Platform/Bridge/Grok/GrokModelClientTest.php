<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Grok\Tests;

use Ineersa\Platform\Bridge\Grok\GrokModelClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as HttpResponse;

final class GrokModelClientTest extends TestCase
{
    public function testItSupportsResponsesModel(): void
    {
        $client = new GrokModelClient(new MockHttpClient(), 'https://cli-chat-proxy.grok.com', 'tok');
        $this->assertTrue($client->supports(new ResponsesModel('grok-composer-2.5-fast')));
        $this->assertFalse($client->supports(new Model('other')));
    }

    public function testItSendsSpoofedHeadersAndConversationId(): void
    {
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): HttpResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://cli-chat-proxy.grok.com/v1/responses', $url);
                self::assertSame('Authorization: Bearer test-access', $options['normalized_headers']['authorization'][0]);
                self::assertSame('Accept: text/event-stream', $options['normalized_headers']['accept'][0]);
                self::assertSame(
                    'User-Agent: grok-pager/0.2.91 grok-shell/0.2.91 (macos; aarch64)',
                    $options['normalized_headers']['user-agent'][0],
                );
                self::assertSame('x-grok-client-identifier: grok-pager', $options['normalized_headers']['x-grok-client-identifier'][0]);
                self::assertSame('x-grok-client-version: 0.2.91', $options['normalized_headers']['x-grok-client-version'][0]);
                self::assertSame('x-xai-token-auth: xai-grok-cli', $options['normalized_headers']['x-xai-token-auth'][0]);
                self::assertSame('x-grok-model-override: grok-composer-2.5-fast', $options['normalized_headers']['x-grok-model-override'][0]);
                self::assertSame('x-grok-conv-id: run-abc', $options['normalized_headers']['x-grok-conv-id'][0]);

                $body = json_decode($options['body'], true);
                self::assertSame('run-abc', $body['prompt_cache_key']);
                self::assertSame('grok-composer-2.5-fast', $body['model']);
                self::assertSame(['reasoning.encrypted_content'], $body['include']);
                self::assertArrayNotHasKey('run_id', $body);
                self::assertArrayNotHasKey('tools_ref', $body);
                self::assertArrayNotHasKey('turn_no', $body);
                self::assertArrayNotHasKey('provider_cache_key', $body);

                return new MockResponse();
            },
        ]);

        $client = new GrokModelClient($httpClient, 'https://cli-chat-proxy.grok.com', 'test-access');
        $client->request(
            new ResponsesModel('grok-composer-2.5-fast'),
            ['input' => [['role' => 'user', 'content' => 'hi']]],
            ['run_id' => 'run-abc', 'tools_ref' => 't1', 'turn_no' => 2, 'provider_cache_key' => 'pk'],
        );
    }

    public function testItDoesNotDoubleV1InUrl(): void
    {
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): HttpResponse {
                self::assertSame('https://cli-chat-proxy.grok.com/v1/responses', $url);
                self::assertStringNotContainsString('/v1/v1/', $url);

                return new MockResponse();
            },
        ]);

        $client = new GrokModelClient($httpClient, 'https://cli-chat-proxy.grok.com/', 'tok', '/v1/responses');
        $client->request(new ResponsesModel('grok-build'), ['input' => [['role' => 'user', 'content' => 'x']]]);
    }

    public function testItDropsEmptyContentAndReasoningStatus(): void
    {
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): HttpResponse {
                $body = json_decode($options['body'], true);
                self::assertCount(2, $body['input']);
                self::assertSame('user', $body['input'][0]['role']);
                self::assertSame('reasoning', $body['input'][1]['type']);
                self::assertArrayNotHasKey('status', $body['input'][1]);

                return new MockResponse();
            },
        ]);

        $client = new GrokModelClient($httpClient, 'https://cli-chat-proxy.grok.com', 'tok');
        $client->request(
            new ResponsesModel('grok-build'),
            [
                'input' => [
                    ['role' => 'user', 'content' => 'keep'],
                    ['role' => 'assistant', 'content' => ''],
                    ['type' => 'reasoning', 'status' => 'completed', 'content' => [['type' => 'reasoning_text', 'text' => 't']]],
                ],
            ],
        );
    }

    public function testRefreshesAndRetriesOnceOn401(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options) use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
            static function (string $method, string $url, array $options) use (&$requestCount): HttpResponse {
                ++$requestCount;
                self::assertSame('Authorization: Bearer new-token', $options['normalized_headers']['authorization'][0]);
                self::assertSame('x-grok-client-version: 0.2.91', $options['normalized_headers']['x-grok-client-version'][0]);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);

        $client = new GrokModelClient(
            $httpClient,
            'https://cli-chat-proxy.grok.com',
            'stale-token',
            '/v1/responses',
            null,
            $refresher,
        );

        $result = $client->request(
            new ResponsesModel('grok-composer-2.5-fast'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            ['run_id' => 'keep-me'],
        );

        $this->assertSame(200, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    public function test401WhenRefreshReturnsNullDoesNotRetry(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $client = new GrokModelClient(
            $httpClient,
            'https://cli-chat-proxy.grok.com',
            'stale',
            '/v1/responses',
            null,
            static fn (): ?string => null,
        );

        $result = $client->request(
            new ResponsesModel('grok-build'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);
    }
}
