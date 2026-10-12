<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpRequestBodyDecoderTest extends TestCase
{
    public function testPlainAndZstdRequestsDecodeToTheSamePayload(): void
    {
        $payload = ['input' => [['role' => 'user', 'content' => 'synthetic history']], 'prompt_cache_key' => 'synthetic-cache'];
        $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        $decoded = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$decoded): MockResponse {
            $decoded[] = HttpRequestBodyDecoder::decode($options);

            return new MockResponse('{}');
        });
        $http->request('POST', 'https://test.invalid/responses', ['body' => $json])->getContent();
        $http->request('POST', 'https://test.invalid/responses', ['body' => zstd_compress($json, 3), 'headers' => ['content-encoding' => 'zstd']])->getContent();
        $this->assertSame([$payload, $payload], $decoded);
    }
}
