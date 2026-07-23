<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Thesis: probe only configured providers; success renders core quota lines;
 * degraded responses stay sanitized and never leak secrets.
 */
final class ProviderQuotaProbeServiceTest extends TestCase
{
    private string $tmpDir;
    private CodexAuthStorage $authStorage;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('provider-quota');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/.hatfield');
        $this->authStorage = new CodexAuthStorage(
            $this->tmpDir,
            new LockFactory(new FlockStore($this->tmpDir)),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        putenv('ZAI_API_KEY');
    }

    #[Test]
    public function testProbeConfiguredProvidersSuccess(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));
        putenv('ZAI_API_KEY=test-zai-key');

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
        $zaiQuotaBody = json_encode([
            'success' => true,
            'code' => 200,
            'data' => [
                'limits' => [[
                    'type' => 'TOKENS_LIMIT',
                    'usage' => 1000,
                    'currentValue' => 250,
                    'percentage' => 25,
                    // Absolute epoch ms; humanized countdown is asserted via pattern (not exact wall-clock).
                    'nextResetTime' => (int) ((microtime(true) + 3600) * 1000),
                ]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mock = new MockHttpClient(static function (string $method, string $url) use ($openaiBody, $zaiQuotaBody): MockResponse {
            self::assertSame('GET', $method);
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse($openaiBody, ['http_code' => 200]);
            }
            if (str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiQuotaBody, ['http_code' => 200]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $report = $this->service($mock, both: true)->probe();
        $this->assertCount(2, $report->sections);
        $this->assertSame('OpenAI Codex', $report->sections[0]->title);
        $joinedOpenAi = implode("\n", $report->sections[0]->lines);
        $this->assertStringContainsString('Codex (5h): 83% left, resets in 2h', $joinedOpenAi);
        $this->assertStringContainsString('Plan: pro', $joinedOpenAi);
        $this->assertStringContainsString('Account: user@example.com', $joinedOpenAi);
        $this->assertStringNotContainsString('Error:', $joinedOpenAi);
        $this->assertStringNotContainsString('7200s', $joinedOpenAi);
        $this->assertSame('z.ai', $report->sections[1]->title);
        $joinedZai = implode("\n", $report->sections[1]->lines);
        // OpenAI reset_after_seconds is relative → exact "2h". z.ai uses absolute epoch ms, so countdown
        // can tick during the probe; accept any humanized form (not raw seconds-only).
        $this->assertMatchesRegularExpression(
            '/Tokens \(250\/1,000\): 75% left, resets in (\d+h(\d+m)?|\d+m(\d+s)?)/',
            $joinedZai,
        );
        $this->assertStringNotContainsString('Error:', $joinedZai);
    }

    #[Test]
    public function testAbsentProvidersProduceEmptySectionList(): void
    {
        $mock = new MockHttpClient(static function (): MockResponse {
            self::fail('No HTTP should run when providers are absent from settings');
        });

        $report = $this->service($mock, both: false)->probe();
        $this->assertSame([], $report->sections);
    }

    #[Test]
    public function testDegradedOpenAiDoesNotSuppressZaiOrLeakSecrets(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'secret-access-token',
            refresh: 'secret-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));
        putenv('ZAI_API_KEY=secret-zai-key');

        $logger = new TestLogger();
        $zaiQuotaBody = json_encode([
            'success' => true,
            'code' => 200,
            'data' => [
                'limits' => [[
                    'type' => 'TOKENS_LIMIT',
                    'usage' => 100,
                    'currentValue' => 10,
                    'percentage' => 10,
                    'nextResetTime' => (int) ((microtime(true) + 600) * 1000),
                ]],
            ],
        ], \JSON_THROW_ON_ERROR);

        // 401 exercises the actionable Codex auth remediation path (not a generic status line).
        $mock = new MockHttpClient(static function (string $method, string $url) use ($zaiQuotaBody): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('{"error":"secret-body"}', ['http_code' => 401]);
            }
            if (str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiQuotaBody, ['http_code' => 200]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $report = $this->service($mock, both: true, logger: $logger)->probe();
        $this->assertCount(2, $report->sections);
        $openAi = implode("\n", $report->sections[0]->lines);
        $this->assertStringContainsString('OpenAI auth token expired', $openAi);
        $this->assertStringContainsString('bin/console auth:codex', $openAi);
        $this->assertStringNotContainsString('secret-body', $openAi);
        $this->assertStringNotContainsString('secret-access-token', $openAi);
        $zai = implode("\n", $report->sections[1]->lines);
        $this->assertStringContainsString('90% left', $zai);
        $this->assertStringNotContainsString('Error:', $zai);

        foreach ($logger->records as $record) {
            $encoded = json_encode($record, \JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('secret-body', $encoded);
            $this->assertStringNotContainsString('secret-access-token', $encoded);
            $this->assertStringNotContainsString('secret-zai-key', $encoded);
        }
    }

    private function service(MockHttpClient $http, bool $both, ?TestLogger $logger = null): ProviderQuotaProbeService
    {
        $providers = [];
        if ($both) {
            $providers['openai-codex'] = new AiProviderConfig(
                id: 'openai-codex',
                type: 'openai-codex',
                enabled: true,
                baseUrl: 'https://chatgpt.com/backend-api',
            );
            $providers['zai'] = new AiProviderConfig(
                id: 'zai',
                type: 'generic',
                enabled: true,
                baseUrl: 'https://api.z.ai/api/coding/paas/v4',
                apiKey: 'env:ZAI_API_KEY',
            );
        }

        $ai = new AiConfig(defaultModel: null, defaultReasoning: null, providers: $providers);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            ai: [] === $providers ? null : $ai,
            catalog: [] === $providers ? null : new HatfieldModelCatalog($ai),
        );

        return new ProviderQuotaProbeService(
            $this->authStorage,
            $appConfig,
            $http,
            $logger ?? new TestLogger(),
        );
    }
}
