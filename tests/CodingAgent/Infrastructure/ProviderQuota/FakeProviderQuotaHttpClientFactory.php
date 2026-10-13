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

        return new MockHttpClient(static function (string $method, string $url) use ($zaiBody): MockResponse {
            if ('GET' === $method && str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiBody, ['http_code' => 200]);
            }

            throw new \LogicException('Unexpected provider quota endpoint.');
        });
    }
}
