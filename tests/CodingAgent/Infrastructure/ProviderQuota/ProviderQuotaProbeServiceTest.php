<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderQuotaProbeServiceTest extends TestCase
{
    public function testChatGPTUsageIsManagementLinkWithoutCredentialsOrHttp(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('Management usage must not probe an endpoint or read credentials.');
        });
        $report = $this->service($client, [new AiProviderConfig('opaque-existing-id', type: 'chatgpt')])->probe();
        $this->assertCount(1, $report->sections);
        $this->assertSame('ChatGPT', $report->sections[0]->title);
        $this->assertStringContainsString('https://chatgpt.com/settings/usage', implode("\n", $report->sections[0]->lines));
        $this->assertStringContainsString('No numerical quota', implode("\n", $report->sections[0]->lines));
        $this->assertStringNotContainsString('% left', implode("\n", $report->sections[0]->lines));
    }

    public function testAbsentOrDisabledProvidersProduceNoSections(): void
    {
        $this->assertSame([], $this->service(new MockHttpClient(), [new AiProviderConfig('disabled', type: 'chatgpt', enabled: false)])->probe()->sections);
    }

    public function testZaiQuotaIsPreservedAlongsideManagementLink(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.z.ai/api/monitor/usage/quota/limit', $url);
            self::assertSame('Authorization: synthetic-key', $options['normalized_headers']['authorization'][0]);

            return new MockResponse('{"success":true,"code":200,"data":{"limits":[{"type":"TOKENS_LIMIT","usage":1000,"currentValue":250,"percentage":25}]}}');
        });
        $report = $this->service($http, [new AiProviderConfig('openai-codex', type: 'chatgpt'), new AiProviderConfig('zai', apiKey: 'synthetic-key')])->probe();
        $this->assertCount(2, $report->sections);
        $this->assertSame('z.ai', $report->sections[1]->title);
        $this->assertStringContainsString('Tokens (250/1,000): 75% left', implode("\n", $report->sections[1]->lines));
    }

    public function testZaiFailureDoesNotSuppressChatGPTOrExposeBody(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"sensitive-body"}', ['http_code' => 401]));
        $report = $this->service($http, [new AiProviderConfig('openai-codex', type: 'chatgpt'), new AiProviderConfig('zai', apiKey: 'synthetic-key')])->probe();
        $this->assertCount(2, $report->sections);
        $this->assertStringContainsString('https://chatgpt.com/settings/usage', implode("\n", $report->sections[0]->lines));
        $this->assertStringNotContainsString('sensitive-body', implode("\n", $report->sections[1]->lines));
        $this->assertStringContainsString('Error:', implode("\n", $report->sections[1]->lines));
    }

    /** @param list<AiProviderConfig> $providers */
    private function service(MockHttpClient $http, array $providers): ProviderQuotaProbeService
    {
        $indexed = [];
        foreach ($providers as $provider) {
            $indexed[$provider->id] = $provider;
        }
        $catalog = new HatfieldModelCatalog(new AiConfig(providers: $indexed));

        return new ProviderQuotaProbeService(new AppConfig(new TuiConfig('default'), new LoggingConfig(), catalog: $catalog), $http, new TestLogger());
    }
}
