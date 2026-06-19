<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Theme\ThemeColorEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FooterStateSegmentProviderTest extends TestCase
{
    private TuiSessionState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = new TuiSessionState('test-session');
    }

    #[Test]
    public function testThinkingColorReturnsDedicatedTokenForEachLevel(): void
    {
        // Set up a state that the provider can read.
        // We use reflection to verify the private thinkingColor() output
        // indirectly by checking that the ◆ segment uses the right token.
        $state = $this->state;
        $state->footerModel = 'deepseek-v4-pro';

        $provider = new FooterStateSegmentProvider($state);

        // off → ThinkingOff
        $state->footerReasoning = 'off';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingOff, $segments[0]->color);
        self::assertSame('◆', $segments[0]->text);
        self::assertSame(ThemeColorEnum::ThinkingOff, $segments[1]->color);

        // minimal → ThinkingMinimal
        $state->footerReasoning = 'minimal';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingMinimal, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingMinimal, $segments[1]->color);

        // low → ThinkingLow
        $state->footerReasoning = 'low';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingLow, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingLow, $segments[1]->color);

        // medium → ThinkingMedium
        $state->footerReasoning = 'medium';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingMedium, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingMedium, $segments[1]->color);

        // high → ThinkingHigh
        $state->footerReasoning = 'high';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingHigh, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingHigh, $segments[1]->color);

        // xhigh → ThinkingXhigh
        $state->footerReasoning = 'xhigh';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingXhigh, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingXhigh, $segments[1]->color, 'Model name should use same thinking colour as diamond');

        // Unknown / empty → ThinkingText
        $state->footerReasoning = '';
        $segments = $provider->getSegments();
        self::assertSame(ThemeColorEnum::ThinkingText, $segments[0]->color);
        self::assertSame(ThemeColorEnum::ThinkingText, $segments[1]->color);
    }

    #[Test]
    public function testModelNameColoredWithThinkingColorNotAccent(): void
    {
        $state = $this->state;
        $state->footerModel = 'glm-5.1';
        $state->footerReasoning = 'high';

        $provider = new FooterStateSegmentProvider($state);
        $segments = $provider->getSegments();

        // Model name segment (priority 1) uses thinking color, NOT Accent
        $modelSegment = $segments[1];
        self::assertSame('glm-5.1', $modelSegment->text);
        self::assertSame(ThemeColorEnum::ThinkingHigh, $modelSegment->color);
        self::assertNotSame(ThemeColorEnum::Accent, $modelSegment->color);
    }

    #[Test]
    public function testNoReasoningTextSegmentInFooter(): void
    {
        $state = $this->state;
        $state->footerModel = 'flash';
        $state->footerReasoning = 'medium';

        $provider = new FooterStateSegmentProvider($state);
        $segments = $provider->getSegments();

        // Verify that the word "medium" does not appear as a text segment
        foreach ($segments as $segment) {
            self::assertStringNotContainsString(
                'medium',
                $segment->text,
                'Reasoning level text should not appear in footer segments',
            );
        }
    }

    #[Test]
    public function testDiamondAndModelNameShareSameColor(): void
    {
        $state = $this->state;
        $state->footerModel = 'deepseek-v4-pro';

        foreach (['off', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $level) {
            $state->footerReasoning = $level;
            $provider = new FooterStateSegmentProvider($state);
            $segments = $provider->getSegments();

            // Priority 0 = ◆, priority 1 = model name — same color
            self::assertSame(
                $segments[0]->color,
                $segments[1]->color,
                "Diamond and model name should share the same thinking colour for level '{$level}'",
            );
        }
    }

    #[Test]
    public function testCacheHitSegmentAppearsWhenTelemetryExists(): void
    {
        $state = $this->state;
        $state->footerModel = 'test-model';
        $state->contextWindow = 32768;

        // Simulate cache telemetry: 78 cache-read out of 100 input → 78%
        $state->usage->inputTokens = 100;
        $state->usage->cacheReadTokens = 78;
        $state->usage->hasCacheTelemetry = true;

        $provider = new FooterStateSegmentProvider($state);
        $segments = $provider->getSegments();

        // Find the cache segment (priority 12).
        $cacheSegments = array_filter(
            $segments,
            static fn (FooterSegment $s): bool => 12 === $s->priority,
        );
        self::assertCount(1, $cacheSegments, 'Cache segment should exist when telemetry is present');

        $cacheSegment = array_values($cacheSegments)[0];
        self::assertStringContainsString('↻', $cacheSegment->text, 'Cache segment should contain ↻ symbol');
        self::assertStringContainsString('78%', $cacheSegment->text, 'Cache segment should show 78%');
        self::assertSame(ThemeColorEnum::Success, $cacheSegment->color);
    }

    #[Test]
    public function testCacheHitSegmentAbsentWhenTelemetryIsAbsent(): void
    {
        $state = $this->state;
        $state->footerModel = 'test-model';
        $state->contextWindow = 32768;

        // No cache telemetry set.
        self::assertFalse($state->usage->hasCacheTelemetry);
        self::assertSame(0, $state->usage->cacheReadTokens);

        $provider = new FooterStateSegmentProvider($state);
        $segments = $provider->getSegments();

        // No segment with priority 12 should exist.
        $cacheSegments = array_filter(
            $segments,
            static fn (FooterSegment $s): bool => 12 === $s->priority,
        );
        self::assertCount(0, $cacheSegments, 'Cache segment should NOT exist when telemetry is absent');
    }

    #[Test]
    public function testContextWindowSegmentMovedToPriority13(): void
    {
        $state = $this->state;
        $state->footerModel = 'test-model';
        $state->contextWindow = 32768;
        $state->usage->latestInputTokens = 5000;

        $provider = new FooterStateSegmentProvider($state);
        $segments = $provider->getSegments();

        // Context window segment should now be at priority 13.
        $ctxSegments = array_filter(
            $segments,
            static fn (FooterSegment $s): bool => 13 === $s->priority,
        );
        self::assertCount(1, $ctxSegments, 'Context window segment should be at priority 13');
    }
}
