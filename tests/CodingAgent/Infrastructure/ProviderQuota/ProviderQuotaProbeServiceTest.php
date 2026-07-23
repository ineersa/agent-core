<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexTokenRefresher;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
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
 * degrades missing credentials / HTTP / refresh failures without suppressing
 * the sibling provider; custom auth keys appear in 401 remediation.
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
            'additional_rate_limits' => [
                [
                    'limit_name' => 'Spark',
                    'rate_limit' => [
                        'primary_window' => [
                            'used_percent' => 40,
                            'limit_window_seconds' => 3600,
                            'reset_after_seconds' => 1800,
                        ],
                    ],
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
                    [
                        'type' => 'TIME_LIMIT',
                        'usage' => 100,
                        'currentValue' => 20,
                        'percentage' => 20,
                        'nextResetTime' => (int) ((microtime(true) + 7200) * 1000),
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
        $this->assertCount(2, $report->openaiCodex->windows);
        $labels = array_map(static fn ($w) => $w->label, $report->openaiCodex->windows);
        $this->assertContains('Codex (5h)', $labels);
        $this->assertContains('Spark (1h)', $labels);
        $spark = null;
        foreach ($report->openaiCodex->windows as $window) {
            if ('Spark (1h)' === $window->label) {
                $spark = $window;
                break;
            }
        }
        $this->assertNotNull($spark);
        $this->assertSame(60.0, $spark->percentLeft);
        $this->assertSame('in 30m', $spark->resetDescription);

        $this->assertSame('z.ai', $report->zai->title);
        $this->assertNull($report->zai->error);
        $this->assertSame(2, $report->zai->modelCount);
        $this->assertCount(2, $report->zai->windows);
        $zaiLabels = array_map(static fn ($w) => $w->label, $report->zai->windows);
        $this->assertTrue((bool) array_filter($zaiLabels, static fn (string $l): bool => str_contains($l, 'Tokens')));
        $this->assertTrue((bool) array_filter($zaiLabels, static fn (string $l): bool => str_starts_with($l, 'Time')));
        $this->assertSame(3, $mock->getRequestsCount());
    }

    #[Test]
    public function testProbeDegradesMissingCredentialsIndependently(): void
    {
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
        $this->assertStringContainsString('auth:codex', (string) $report->openaiCodex->error);
        $this->assertNull($report->zai->error);
        $this->assertNotEmpty($report->zai->windows);
        $this->assertNull($report->zai->modelCount);
    }

    #[Test]
    public function testOpenAi401UsesCustomAuthProfileHint(): void
    {
        $authKey = 'openai-codex-work';
        $this->authStorage->saveCredentials($authKey, new CodexAuthRecord(
            access: 'token',
            refresh: 'refresh',
            expires: time() + 3600,
            accountId: 'acct',
        ));

        $mock = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('unauthorized', ['http_code' => 401]);
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: new HatfieldModelCatalog(new AiConfig(
                providers: [
                    'openai-codex' => new AiProviderConfig(
                        id: 'openai-codex',
                        type: 'codex',
                        enabled: true,
                        baseUrl: 'https://chatgpt.com/backend-api',
                        authKey: $authKey,
                    ),
                ],
            )),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertStringContainsString('--auth-profile=work', (string) $report->openaiCodex->error);
    }

    #[Test]
    public function testZaiAlternateAuthRetryPreservesModelsAndWindows(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'token',
            refresh: 'refresh',
            expires: time() + 3600,
            accountId: 'acct',
        ));

        $zaiQuotaOk = json_encode([
            'success' => true,
            'code' => 200,
            'data' => [
                'limits' => [
                    ['type' => 'TOKENS_LIMIT', 'usage' => 100, 'currentValue' => 25, 'percentage' => 25],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $zaiModelsOk = json_encode([
            'data' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
        ], \JSON_THROW_ON_ERROR);

        $quotaAttempts = 0;
        $modelsAttempts = 0;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options = []) use (
            &$quotaAttempts,
            &$modelsAttempts,
            $zaiQuotaOk,
            $zaiModelsOk,
        ): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('{}', ['http_code' => 200]);
            }

            $auth = self::authorizationHeader($options);

            if (str_contains($url, '/quota/limit')) {
                ++$quotaAttempts;
                if (!str_starts_with(strtolower($auth), 'bearer ')) {
                    return new MockResponse('unauthorized', ['http_code' => 401]);
                }

                return new MockResponse($zaiQuotaOk, ['http_code' => 200]);
            }
            if (str_contains($url, '/models')) {
                ++$modelsAttempts;

                // First-form models succeed so the retry path must preserve them.
                return new MockResponse($zaiModelsOk, ['http_code' => 200]);
            }

            return new MockResponse('nope', ['http_code' => 404]);
        });

        putenv('ZAI_API_KEY=raw-key-without-bearer');
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertNull($report->zai->error);
        $this->assertSame(3, $report->zai->modelCount);
        $this->assertNotEmpty($report->zai->windows);
        $this->assertSame(75.0, $report->zai->windows[0]->percentLeft);
        $this->assertGreaterThanOrEqual(2, $quotaAttempts);
        $this->assertSame(1, $modelsAttempts, 'models should not be re-requested when first attempt succeeded');
    }

    #[Test]
    public function testZaiAlternateAuthRetryReRequestsModelsWhenFirstFormRejected(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'token',
            refresh: 'refresh',
            expires: time() + 3600,
            accountId: 'acct',
        ));

        // success=true with omitted code is a representative successful payload.
        $zaiQuotaOk = json_encode([
            'success' => true,
            'data' => [
                'limits' => [
                    ['type' => 'TOKENS_LIMIT', 'usage' => 200, 'currentValue' => 50, 'percentage' => 25],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $zaiModelsOk = json_encode([
            'data' => [['id' => 'm1'], ['id' => 'm2']],
        ], \JSON_THROW_ON_ERROR);

        $quotaAttempts = 0;
        $modelsAttempts = 0;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options = []) use (
            &$quotaAttempts,
            &$modelsAttempts,
            $zaiQuotaOk,
            $zaiModelsOk,
        ): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('{}', ['http_code' => 200]);
            }

            $auth = self::authorizationHeader($options);
            $isBearer = str_starts_with(strtolower($auth), 'bearer ');

            if (str_contains($url, '/quota/limit')) {
                ++$quotaAttempts;
                if (!$isBearer) {
                    return new MockResponse('unauthorized', ['http_code' => 401]);
                }

                return new MockResponse($zaiQuotaOk, ['http_code' => 200]);
            }
            if (str_contains($url, '/models')) {
                ++$modelsAttempts;
                // First-form models also rejected; alternate auth must re-request and count them.
                if (!$isBearer) {
                    return new MockResponse('unauthorized', ['http_code' => 401]);
                }

                return new MockResponse($zaiModelsOk, ['http_code' => 200]);
            }

            return new MockResponse('nope', ['http_code' => 404]);
        });

        putenv('ZAI_API_KEY=raw-key-without-bearer');
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertNull($report->zai->error);
        $this->assertSame(2, $report->zai->modelCount, 'models count must come from alternate-auth re-request');
        $this->assertNotEmpty($report->zai->windows);
        $this->assertSame(75.0, $report->zai->windows[0]->percentLeft);
        $this->assertGreaterThanOrEqual(2, $quotaAttempts);
        $this->assertSame(2, $modelsAttempts, 'models must be re-requested after first-form 401');
    }

    #[Test]
    public function testOpenAiUnexpectedHttpStatusIsErrorNotNote(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'token',
            refresh: 'refresh',
            expires: time() + 3600,
            accountId: 'acct',
        ));

        $mock = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse('boom', ['http_code' => 503]);
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $this->authStorage,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertStringContainsString('returned 503', (string) $report->openaiCodex->error);
        $this->assertNull($report->openaiCodex->note);
    }

    #[Test]
    public function testCredentialRefreshFailureDegradesOpenAiWithoutHttp(): void
    {
        $failingRefresher = new class extends CodexTokenRefresher {
            public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
            {
                throw new \RuntimeException('refresh denied by provider with token=SECRET');
            }
        };

        $auth = new CodexAuthStorage(
            $this->tmpDir,
            new LockFactory(new FlockStore($this->tmpDir)),
            $failingRefresher,
        );
        $auth->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh',
            expires: time() - 10,
            accountId: 'acct',
        ));

        $mock = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/wham/usage')) {
                self::fail('OpenAI HTTP must not run when credential refresh fails');
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $logger = new TestLogger();
        $service = new ProviderQuotaProbeService(
            codexAuthStorage: $auth,
            modelCatalog: $this->catalogWithProviders(),
            httpClient: $mock,
            logger: $logger,
        );
        $report = $service->probe();

        $this->assertStringContainsString('Auth token unavailable/expired', (string) $report->openaiCodex->error);
        $this->assertStringContainsString('auth:codex', (string) $report->openaiCodex->error);
        foreach ($logger->records as $record) {
            $context = $record['context'] ?? [];
            $this->assertArrayNotHasKey('error', $context);
            $this->assertArrayNotHasKey('reason_code', $context);
            $this->assertArrayHasKey('component', $context);
            $this->assertArrayHasKey('event_type', $context);
            $this->assertArrayHasKey('provider', $context);
            $this->assertArrayHasKey('exception_class', $context);
            $serialized = json_encode($context, \JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SECRET', $serialized);
            $this->assertStringNotContainsString('refresh denied', $serialized);
        }
    }

    #[Test]
    public function testFromAppConfigWithNullAiDoesNotRequireCatalog(): void
    {
        $appConfig = new AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig('default'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig($this->tmpDir.'/.hatfield/logs'),
            ai: null,
            catalog: null,
            cwd: $this->tmpDir,
        );
        $mock = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP must not be called without providers');
        });

        $service = ProviderQuotaProbeService::fromAppConfig(
            codexAuthStorage: $this->authStorage,
            appConfig: $appConfig,
            httpClient: $mock,
        );
        $report = $service->probe();

        $this->assertFalse($report->openaiCodex->configured);
        $this->assertFalse($report->zai->configured);
        $this->assertSame(0, $mock->getRequestsCount());
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

    /**
     * @param array<string, mixed> $options
     */
    private static function authorizationHeader(array $options): string
    {
        foreach (($options['headers'] ?? []) as $headerLine) {
            if (!\is_string($headerLine)) {
                continue;
            }
            if (str_starts_with(strtolower($headerLine), 'authorization:')) {
                return trim(substr($headerLine, \strlen('Authorization:')));
            }
        }

        return '';
    }
}
