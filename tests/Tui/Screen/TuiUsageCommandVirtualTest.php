<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

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
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\UsageCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thesis: production parser/router/registrar/handler for /usage renders
 * provider sections when present and always renders session totals.
 */
final class TuiUsageCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tmpDir;
    private CodexAuthStorage $authStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('usage-virtual');
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
        parent::tearDown();
    }

    #[Test]
    public function testUsageRoutesAndRendersProviderAndSessionSections(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));
        putenv('ZAI_API_KEY=secret-zai-key');

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

        $probe = $this->probe(new MockHttpClient(static function (string $method, string $url) use ($openaiBody, $zaiBody): MockResponse {
            self::assertSame('GET', $method);
            if (str_contains($url, '/wham/usage')) {
                return new MockResponse($openaiBody, ['http_code' => 200]);
            }
            if (str_contains($url, '/quota/limit')) {
                return new MockResponse($zaiBody, ['http_code' => 200]);
            }

            self::fail('Unexpected URL: '.$url);
        }), both: true);

        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('usage-virtual');
        $state->footerModel = 'openai-codex/gpt-5.6-luna';
        $state->footerReasoning = 'high';
        $state->contextWindow = 272000;
        $state->usage->inputTokens = 12345;
        $state->usage->outputTokens = 2100;
        $state->usage->latestInputTokens = 9000;
        $state->usage->totalCost = 0.123;
        $state->usage->cacheReadTokens = 4000;
        $state->usage->cacheCreationTokens = 100;
        $state->usage->hasCacheTelemetry = true;

        $harness = new VirtualTuiHarness(sessionId: 'usage-virtual');
        (new UsageCommandRegistrar($probe, new TestLogger()))->registerCatalog($catalog);
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
        (new UsageCommandRegistrar($probe, new TestLogger()))->register($context);

        $this->assertTrue($catalog->has('usage'));
        $meta = null;
        foreach ($catalog->allMetadata() as $item) {
            if ('usage' === $item->name) {
                $meta = $item;
                break;
            }
        }
        $this->assertNotNull($meta);
        $this->assertStringContainsString('quota', strtolower((string) $meta->description));

        $router = new SubmissionRouter(new CommandParser(), $context->sessionServices->commandRegistry);
        $result = $router->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('## Provider usage / quota status', $result->text);
        $this->assertStringContainsString('### OpenAI Codex', $result->text);
        $this->assertStringContainsString('Codex (5h): 83% left, resets in 2h', $result->text);
        $this->assertStringContainsString('### z.ai', $result->text);
        $this->assertStringContainsString('Tokens (250/1,000): 75% left', $result->text);
        $this->assertStringNotContainsString('Models visible', $result->text);
        $this->assertStringContainsString('### Session totals', $result->text);
        $this->assertStringContainsString('openai-codex/gpt-5.6-luna', $result->text);
        $this->assertStringContainsString('**Tokens (session cumulative):** 12,345 in / 2,100 out', $result->text);
        $this->assertStringContainsString('**Estimated cost:** $0.123', $result->text);
        $this->assertStringContainsString('**Context (latest turn):**', $result->text);
        $this->assertStringContainsString('**Cache:**', $result->text);

        $block = (new TranscriptBlockFactory())->system('usage-virtual', $result->text, 1, $result->style);
        $this->assertInstanceOf(MarkdownWidget::class, (new TranscriptBlockWidgetFactory())->buildWidget($block, $harness->screen()->theme()));
        $harness->screen()->setTranscriptBlocks([$block]);
        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('OpenAI Codex', $screen);
        $this->assertStringContainsString('Session totals', $screen);
        $this->assertStringContainsString('12,345', $screen);
    }

    #[Test]
    public function testUsageOmitsProviderSectionsWhenProbeReturnsEmptyList(): void
    {
        $probe = $this->probe(new MockHttpClient(), both: false);

        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('usage-empty');
        $state->usage->inputTokens = 11;
        $state->usage->outputTokens = 2;
        $state->usage->totalCost = 0.01;
        $harness = new VirtualTuiHarness(sessionId: 'usage-empty');
        (new UsageCommandRegistrar($probe, new TestLogger()))->registerCatalog($catalog);
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
        (new UsageCommandRegistrar($probe, new TestLogger()))->register($context);

        $result = (new SubmissionRouter(new CommandParser(), $context->sessionServices->commandRegistry))->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringNotContainsString('No configured providers', $result->text);
        $this->assertStringNotContainsString('### OpenAI Codex', $result->text);
        $this->assertStringNotContainsString('### z.ai', $result->text);
        $this->assertStringContainsString('## Provider usage / quota status', $result->text);
        $this->assertStringContainsString('### Session totals', $result->text);
        $this->assertStringContainsString('11 in / 2 out', $result->text);
    }

    #[Test]
    public function testUsageShowsAndClearsProbingWorkingIndicator(): void
    {
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('usage-working');
        $harness = new VirtualTuiHarness(sessionId: 'usage-working');
        $screen = $harness->screen();
        $tui = $harness->tui();

        $workingDuringProbe = null;
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));
        // Force an HTTP request so the callback can observe the working indicator
        // that UsageCommandHandler paints before probe() returns.
        $probe = $this->probe(new MockHttpClient(static function () use (&$workingDuringProbe, $screen): MockResponse {
            $workingDuringProbe = $screen->workingMessage();

            return new MockResponse(json_encode([
                'plan_type' => 'pro',
                'rate_limit' => [
                    'primary_window' => [
                        'used_percent' => 10,
                        'limit_window_seconds' => 3600,
                        'reset_after_seconds' => 60,
                    ],
                ],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }), both: true, openAiOnly: true);

        (new UsageCommandRegistrar($probe, new TestLogger()))->registerCatalog($catalog);
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
        (new UsageCommandRegistrar($probe, new TestLogger()))->register($context);

        $result = (new SubmissionRouter(new CommandParser(), $context->sessionServices->commandRegistry))->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Checking provider usage...', $workingDuringProbe);
        $this->assertSame('', $screen->workingMessage());
    }

    #[Test]
    public function testUsageKeepsSessionTotalsWhenProviderProbeThrows(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh',
            expires: time() + 3600,
            accountId: 'acct_123',
        ));
        // Throw from the MockHttpClient factory so probe() escapes before intentional degradation.
        $probe = $this->probe(new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException("boom\nsecret-line");
        }), both: true, openAiOnly: true);

        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('usage-virtual-fail');
        $state->usage->inputTokens = 10;
        $state->usage->outputTokens = 5;
        $state->usage->totalCost = 0.001;

        $harness = new VirtualTuiHarness(sessionId: 'usage-virtual-fail');
        (new UsageCommandRegistrar($probe, new TestLogger()))->registerCatalog($catalog);
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
        (new UsageCommandRegistrar($probe, new TestLogger()))->register($context);

        $result = (new SubmissionRouter(new CommandParser(), $context->sessionServices->commandRegistry))->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provider probes failed', $result->text);
        $this->assertStringNotContainsString('secret-line', $result->text);
        $this->assertStringContainsString('### Session totals', $result->text);
        $this->assertStringContainsString('10 in / 5 out', $result->text);
    }

    private function probe(HttpClientInterface $http, bool $both, bool $openAiOnly = false): ProviderQuotaProbeService
    {
        $providers = [];
        if ($both || $openAiOnly) {
            $providers['openai-codex'] = new AiProviderConfig(
                id: 'openai-codex',
                type: 'openai-codex',
                enabled: true,
                baseUrl: 'https://chatgpt.com/backend-api',
            );
        }
        if ($both && !$openAiOnly) {
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
            new TestLogger(),
        );
    }
}
