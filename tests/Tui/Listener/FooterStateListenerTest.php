<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Listener\FooterStateListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Widget\LiveTextWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\TickEvent;

final class FooterStateListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testRepeatedTicksSkipFooterRefreshWhenFingerprintUnchanged(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'footer-coalesce');
        $state = new TuiSessionState('footer-coalesce');
        $state->footerModel = 'test-model';
        $state->sessionStartTime = microtime(true) - 5.0;

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new FooterStateListener($this->footerInitializer($context)))->register($context);

        $footerWidget = $this->footerWidget($harness->screen());
        $handler = $this->firstTickHandler($context);
        $tick = new TickEvent();

        $before = $footerWidget->getRenderRevision();
        $handler($tick);
        $afterFirst = $footerWidget->getRenderRevision();
        $handler($tick);
        $afterSecond = $footerWidget->getRenderRevision();

        $this->assertGreaterThan($before, $afterFirst);
        $this->assertSame($afterFirst, $afterSecond);
    }

    #[Test]
    public function testTickRefreshesFooterWhenFingerprintChanges(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'footer-change');
        $state = new TuiSessionState('footer-change');
        $state->footerModel = 'test-model';
        $state->sessionStartTime = microtime(true) - 5.0;

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new FooterStateListener($this->footerInitializer($context)))->register($context);

        $footerWidget = $this->footerWidget($harness->screen());
        $handler = $this->firstTickHandler($context);

        $handler(new TickEvent());
        $afterFirst = $footerWidget->getRenderRevision();

        $state->footerModel = 'other-model';
        $handler(new TickEvent());
        $afterSecond = $footerWidget->getRenderRevision();

        $this->assertGreaterThan($afterFirst, $afterSecond);
    }

    private function footerWidget(ChatScreen $screen): LiveTextWidget
    {
        $ref = new \ReflectionProperty(ChatScreen::class, 'footerWidget');
        $ref->setAccessible(true);
        $widget = $ref->getValue($screen);
        $this->assertInstanceOf(LiveTextWidget::class, $widget);

        return $widget;
    }

    private function footerInitializer(object $context): FooterStateInitializer
    {
        return new FooterStateInitializer(
            $context->sessionStore,
            new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                sessions: new SessionsConfig(),
                cwd: '/tmp',
            ),
        );
    }

    private function firstTickHandler(object $context): callable
    {
        $ref = new \ReflectionProperty($context->ticks, 'handlers');
        $ref->setAccessible(true);
        $handlers = $ref->getValue($context->ticks);
        $this->assertIsArray($handlers);
        $this->assertNotEmpty($handlers);

        return $handlers[0];
    }
}
