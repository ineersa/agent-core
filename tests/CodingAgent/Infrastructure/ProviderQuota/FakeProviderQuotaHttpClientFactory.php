<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Deterministic provider-quota HTTP for APP_ENV=test.
 *
 * Keeps the real {@see \Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService}
 * while never contacting auth networks.
 */
final class FakeProviderQuotaHttpClientFactory
{
    public static function create(): HttpClientInterface
    {
        $openaiBody = json_encode([
            'plan_type' => 'pro',
            'email' => 'user@example.com',
            'rate_limit' => [
                'primary_window' => [
                    'used_percent' => 17,
                    'limit_window_seconds' => 18000,
                    'reset_after_seconds' => 7200,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $zaiBody = json_encode([
            'success' => true,
            'code' => 200,
            'data' => [
                'limits' => [[
                    'type' => 'TOKENS_LIMIT',
                    'usage' => 1000,
                    'currentValue' => 250,
                    'percentage' => 25,
                    'nextResetTime' => (int) ((microtime(true) + 3600) * 1000),
                ]],
            ],
        ], \JSON_THROW_ON_ERROR);

        return new MockHttpClient(static function (string $method, string $url) use ($openaiBody, $zaiBody): MockResponse {
            if ('GET' === $method && str_contains($url, '/wham/usage')) {
                return new MockResponse($openaiBody, ['http_code' => 200]);
            }
            if ('GET' === $method && str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiBody, ['http_code' => 200]);
            }

            return new MockResponse('unexpected', ['http_code' => 500]);
        });
    }
}
