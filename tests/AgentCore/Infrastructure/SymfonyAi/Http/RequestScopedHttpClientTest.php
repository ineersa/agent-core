<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Http\RequestScopedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Thesis: nested runWithOptions merges max_duration onto HttpClient request options
 * without putting transport keys into provider JSON bodies; stacks are fiber-local.
 */
final class RequestScopedHttpClientTest extends TestCase
{
    public function testRunWithOptionsMergesMaxDurationOntoRequest(): void
    {
        $seen = [];
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('{}');
        });
        $client = new RequestScopedHttpClient($inner);

        RequestScopedHttpClient::runWithOptions(
            ['max_duration' => 300],
            static function () use ($client): void {
                $client->request('POST', 'http://example.test/v1/chat/completions', [
                    'json' => ['model' => 'flash', 'stream' => true],
                ]);
            },
        );

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertNotSame(300, $seen[0]['timeout'] ?? null);
        $this->assertNotSame(300.0, $seen[0]['timeout'] ?? null);
        $body = $seen[0]['body'] ?? null;
        if (\is_string($body)) {
            $decoded = json_decode($body, true);
            $this->assertSame(['model' => 'flash', 'stream' => true], $decoded);
        } else {
            $this->assertSame(['model' => 'flash', 'stream' => true], $seen[0]['json'] ?? null);
        }
    }

    public function testOutsideScopeLeavesDefaultOptionsUntouched(): void
    {
        $seen = [];
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('{}');
        });
        $client = new RequestScopedHttpClient($inner);

        $client->request('GET', 'http://example.test/health');

        $this->assertCount(1, $seen);
        $this->assertNotSame(300, $seen[0]['max_duration'] ?? null);
        $this->assertNotSame(300.0, $seen[0]['max_duration'] ?? null);
    }

    public function testFiberDoesNotInheritMainScopedOptions(): void
    {
        $seen = [];
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('{}');
        });
        $client = new RequestScopedHttpClient($inner);

        RequestScopedHttpClient::runWithOptions(
            ['max_duration' => 300],
            function () use ($client, &$seen): void {
                $fiber = new \Fiber(static function () use ($client): void {
                    $client->request('GET', 'http://example.test/fiber');
                });
                $fiber->start();
                $this->assertTrue($fiber->isTerminated());
                $this->assertCount(1, $seen);
                $this->assertNotSame(300.0, (float) ($seen[0]['max_duration'] ?? 0));
            },
        );
    }
}
