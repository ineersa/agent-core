<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Tui\Widget\ScheduledTickTrait;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\WidgetContext;

/** Tool header with a lifecycle-owned, once-per-second elapsed label. */
final class ToolDurationHeaderWidget extends TextWidget
{
    use ScheduledTickTrait;

    private readonly ?\DateTimeImmutable $startedAt;

    public function __construct(
        private readonly string $header,
        private readonly TranscriptBlock $result,
        private readonly TuiTheme $theme,
        private readonly ThemeColorEnum $color,
        private readonly string $suffix = '',
        private readonly ClockInterface $clock = new Clock(),
    ) {
        parent::__construct(truncate: true);
        $start = $result->meta['started_at'] ?? null;
        $this->startedAt = \is_string($start) ? new \DateTimeImmutable($start) : null;
        $this->refresh();
    }

    protected function onAttach(WidgetContext $context): void
    {
        if ($this->result->streaming && null !== $this->startedAt) {
            $this->startScheduledTick(1.0);
        }
    }

    protected function onDetach(): void
    {
        $this->clearScheduledTick();
    }

    protected function resolveScheduledTickContext(): ?WidgetContext
    {
        return $this->getContext();
    }

    protected function onScheduledTick(): void
    {
        $this->refresh();
    }

    private function refresh(): void
    {
        $durationMs = $this->result->meta['duration_ms'] ?? null;
        if ($this->result->streaming && null !== $this->startedAt) {
            $durationMs = (int) round(((float) $this->clock->now()->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000);
        }
        $elapsed = '';
        if (\is_int($durationMs)) {
            $durationMs = max(0, $durationMs);
            $seconds = intdiv($durationMs, 1000);
            $elapsed = $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m'.($seconds % 60).'s';
            if ($durationMs < 1000) {
                $elapsed = $durationMs.'ms';
            }
            $elapsed = $this->theme->color(ThemeColorEnum::Dim, ' · '.$elapsed);
        }
        $text = $this->theme->color($this->color, $this->header).$elapsed.$this->suffix;
        if ($text !== $this->getText()) {
            $this->setText($text);
        }
    }
}
