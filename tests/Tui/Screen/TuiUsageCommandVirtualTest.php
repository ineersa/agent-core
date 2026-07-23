<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandRegistry;
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
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Thesis: production parser/router/registrar/handler for /usage renders
 * provider sections when present and always renders session totals.
 */
final class TuiUsageCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testUsageRoutesAndRendersProviderAndSessionSections(): void
    {
        $probe = new class implements ProviderQuotaProbeServiceInterface {
            public function probe(): ProviderQuotaReportDTO
            {
                return new ProviderQuotaReportDTO([
                    new ProviderQuotaSectionDTO('OpenAI Codex', [
                        '- Codex (5h): 83% left, resets in 2h',
                        '- Plan: pro',
                        '- Account: user@example.com',
                    ]),
                    new ProviderQuotaSectionDTO('z.ai', [
                        '- Tokens (250/1,000): 75% left, resets in 1h',
                    ]),
                ]);
            }
        };

        $registry = new SlashCommandRegistry();
        $state = new TuiSessionState('usage-virtual');
        $state->footerModel = 'openai-codex/gpt-5.6-luna';
        $state->footerReasoning = 'high';
        $state->contextWindow = 372000;
        $state->usage->inputTokens = 12345;
        $state->usage->outputTokens = 2100;
        $state->usage->latestInputTokens = 9000;
        $state->usage->totalCost = 0.123;
        $state->usage->cacheReadTokens = 4000;
        $state->usage->cacheCreationTokens = 100;
        $state->usage->hasCacheTelemetry = true;

        $harness = new VirtualTuiHarness(sessionId: 'usage-virtual');
        (new UsageCommandRegistrar($registry, $probe, new TestLogger()))->register(
            $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withState($state)
                ->withScreen($harness->screen())
                ->build(),
        );

        $this->assertTrue($registry->has('usage'));
        $meta = null;
        foreach ($registry->allMetadata() as $item) {
            if ('usage' === $item->name) {
                $meta = $item;
                break;
            }
        }
        $this->assertNotNull($meta);
        $this->assertStringContainsString('quota', strtolower((string) $meta->description));

        $router = new SubmissionRouter(new CommandParser(), $registry);
        $result = $router->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('## Provider usage / quota status', $result->text);
        $this->assertStringContainsString('### OpenAI Codex', $result->text);
        $this->assertStringContainsString('Codex (5h): 83% left, resets in 2h', $result->text);
        $this->assertStringContainsString('### z.ai', $result->text);
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
        $probe = new class implements ProviderQuotaProbeServiceInterface {
            public function probe(): ProviderQuotaReportDTO
            {
                return new ProviderQuotaReportDTO([]);
            }
        };

        $registry = new SlashCommandRegistry();
        $state = new TuiSessionState('usage-empty');
        $state->usage->inputTokens = 11;
        $state->usage->outputTokens = 2;
        $state->usage->totalCost = 0.01;
        $harness = new VirtualTuiHarness(sessionId: 'usage-empty');
        (new UsageCommandRegistrar($registry, $probe, new TestLogger()))->register(
            $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withState($state)
                ->withScreen($harness->screen())
                ->build(),
        );

        $result = (new SubmissionRouter(new CommandParser(), $registry))->route('/usage');
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
        $registry = new SlashCommandRegistry();
        $state = new TuiSessionState('usage-working');
        $harness = new VirtualTuiHarness(sessionId: 'usage-working');
        $screen = $harness->screen();
        $tui = $harness->tui();

        $workingDuringProbe = null;
        $probe = new class($workingDuringProbe, $screen) implements ProviderQuotaProbeServiceInterface {
            public function __construct(
                private ?string &$workingDuringProbe,
                private \Ineersa\Tui\Screen\ChatScreen $screen,
            ) {
            }

            public function probe(): ProviderQuotaReportDTO
            {
                $this->workingDuringProbe = $this->screen->registry()->getWorkingMessage();

                return new ProviderQuotaReportDTO([]);
            }
        };

        (new UsageCommandRegistrar($registry, $probe, new TestLogger()))->register(
            $this->buildTuiContext()
                ->withTui($tui)
                ->withState($state)
                ->withScreen($screen)
                ->build(),
        );

        $result = (new SubmissionRouter(new CommandParser(), $registry))->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Checking provider usage...', $workingDuringProbe);
        $this->assertSame('', $screen->registry()->getWorkingMessage());
    }

    #[Test]
    public function testUsageKeepsSessionTotalsWhenProviderProbeThrows(): void
    {
        $probe = new class implements ProviderQuotaProbeServiceInterface {
            public function probe(): ProviderQuotaReportDTO
            {
                throw new \RuntimeException("boom\nsecret-line");
            }
        };

        $registry = new SlashCommandRegistry();
        $state = new TuiSessionState('usage-virtual-fail');
        $state->usage->inputTokens = 10;
        $state->usage->outputTokens = 5;
        $state->usage->totalCost = 0.001;

        $harness = new VirtualTuiHarness(sessionId: 'usage-virtual-fail');
        (new UsageCommandRegistrar($registry, $probe, new TestLogger()))->register(
            $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withState($state)
                ->withScreen($harness->screen())
                ->build(),
        );

        $result = (new SubmissionRouter(new CommandParser(), $registry))->route('/usage');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provider probes failed', $result->text);
        $this->assertStringNotContainsString('secret-line', $result->text);
        $this->assertStringContainsString('### Session totals', $result->text);
        $this->assertStringContainsString('10 in / 5 out', $result->text);
    }
}
