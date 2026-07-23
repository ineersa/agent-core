<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Thesis: probe service returns sanitized Codex/z.ai sections for success and
 * degrades missing credentials / HTTP errors without suppressing the sibling provider.
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
    public function testProbeParsesSuccessfulOpenAiAndZaiResponsesConcurrently(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));

        $openaiBody = json_encode([
            'plan_type' => 'pro',
            'email' => 'user@example.com',
            'credits' => ['balance' => 12.5],
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
                'limits' => [
                    [
                        'type' => 'TOKENS_LIMIT',
                        'usage' => 1000,
                        'currentValue' => 250,
                        'percentage' => 25,
                        'nextResetTime' => (int) ((microtime(true) + 3600) * 1000),
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $zaiModelsBody = json_encode([
            'data' => [
                ['id' => 'glm-5.1'],
                ['id' => 'glm-5.2'],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mock = new MockHttpClient(static function (string $method, string $url) use ($openaiBody, $zaiQuotaBody, $zaiModelsBody): MockResponse {
            self::assertSame('GET', $method);
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse($openaiBody, ['http_code' => 200]);
            }
            if (str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiQuotaBody, ['http_code' => 200]);
            }
            if (str_contains($url, '/models')) {
                return new MockResponse($zaiModelsBody, ['http_code' => 200]);
            }

            return new MockResponse('not found', ['http_code' => 404]);
        });

        putenv('ZAI_API_KEY=zai-test-key');
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertSame('OpenAI Codex', $report->openaiCodex->title);
        $this->assertNull($report->openaiCodex->error);
        $this->assertSame('pro', $report->openaiCodex->plan);
        $this->assertSame('user@example.com', $report->openaiCodex->account);
        $this->assertSame(12.5, $report->openaiCodex->credits);
        $this->assertNotEmpty($report->openaiCodex->windows);
        $this->assertSame('Codex (5h)', $report->openaiCodex->windows[0]->label);
        $this->assertSame(83.0, $report->openaiCodex->windows[0]->percentLeft);
        $this->assertSame('in 2h', $report->openaiCodex->windows[0]->resetDescription);

        $this->assertSame('z.ai', $report->zai->title);
        $this->assertNull($report->zai->error);
        $this->assertSame(2, $report->zai->modelCount);
        $this->assertNotEmpty($report->zai->windows);
        $this->assertStringContainsString('Tokens', $report->zai->windows[0]->label);
        $this->assertSame(75.0, $report->zai->windows[0]->percentLeft);
        $this->assertSame(3, $mock->getRequestsCount());
    }

    #[Test]
    public function testProbeDegradesMissingCredentialsIndependently(): void
    {
        // No Codex credentials saved; z.ai env key missing.
        $mock = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP must not be called when credentials are missing');
        });

        putenv('ZAI_API_KEY');
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertFalse($report->openaiCodex->configured);
        $this->assertStringContainsString('Not configured', (string) $report->openaiCodex->error);
        $this->assertStringContainsString('auth:codex', (string) $report->openaiCodex->error);

        $this->assertTrue($report->zai->configured);
        $this->assertStringContainsString('ZAI_API_KEY', (string) $report->zai->error);
        $this->assertSame(0, $mock->getRequestsCount());
    }

    #[Test]
    public function testProbeDegradesHttpErrorsWithoutSuppressingSibling(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'token',
            refresh: 'refresh',
            expires: time() + 3600,
            accountId: 'acct',
        ));

        $zaiQuotaBody = json_encode([
            'success' => true,
            'code' => 200,
            'data' => [
                'limits' => [
                    ['type' => 'TOKENS_LIMIT', 'usage' => 100, 'currentValue' => 10, 'percentage' => 10],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mock = new MockHttpClient(static function (string $method, string $url) use ($zaiQuotaBody): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('unauthorized', ['http_code' => 401]);
            }
            if (str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiQuotaBody, ['http_code' => 200]);
            }
            if (str_contains($url, '/models')) {
                return new MockResponse('{}', ['http_code' => 500]);
            }

            return new MockResponse('nope', ['http_code' => 404]);
        });

        putenv('ZAI_API_KEY=ok');
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertStringContainsString('expired', (string) $report->openaiCodex->error);
        $this->assertNull($report->zai->error);
        $this->assertNotEmpty($report->zai->windows);
        $this->assertNull($report->zai->modelCount);
    }

    private function catalogWithProviders(): HatfieldModelCatalog
    {
        return new HatfieldModelCatalog(new AiConfig(
            providers: [
                'openai-codex' => new AiProviderConfig(
                    id: 'openai-codex',
                    type: 'codex',
                    enabled: true,
                    baseUrl: 'https://chatgpt.com/backend-api',
                ),
                'zai' => new AiProviderConfig(
                    id: 'zai',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://api.z.ai/api/coding/paas/v4',
                    apiKey: 'env:ZAI_API_KEY',
                ),
            ],
        ));
    }
}
