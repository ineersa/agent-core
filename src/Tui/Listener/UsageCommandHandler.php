<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Tui;

/**
 * Handler for the /usage slash command.
 *
 * Probes provider quotas through the runtime contract and formats
 * current-session totals from {@see TuiSessionState::$usage}.
 *
 * The slash-command interface is synchronous, so network probes can block the
 * event loop for up to the probe timeout. To avoid an unexplained frozen
 * terminal, this handler paints an immediate working indicator (same pattern as
 * {@see SubmitListener}'s pre-dispatch Working... feedback) before probing and
 * clears it afterward.
 *
 * @internal Registered by UsageCommandRegistrar
 */
final class UsageCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly ProviderQuotaProbeServiceInterface $quotaProbe,
        private readonly TuiSessionState $state,
        private readonly ?ChatScreen $screen = null,
        private readonly ?Tui $tui = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $this->showProbingIndicator();

        try {
            try {
                $report = $this->quotaProbe->probe();
            } catch (\Throwable $e) {
                // Top-level guard: probe service should already degrade per-provider,
                // but never let an unexpected failure blank the session totals.
                return new TranscriptMessage(
                    implode("\n", [
                        '## Provider usage / quota status',
                        '',
                        'Provider probes failed: '.$this->sanitizeError($e),
                        '',
                        ...$this->formatSessionLines(),
                    ]),
                    'system',
                    'markdown',
                );
            }

            $lines = [
                '## Provider usage / quota status',
                '',
                ...$this->formatSection($report->openaiCodex),
                '',
                ...$this->formatSection($report->zai),
                '',
                ...$this->formatSessionLines(),
            ];

            return new TranscriptMessage(implode("\n", $lines), 'system', 'markdown');
        } finally {
            $this->clearProbingIndicator();
        }
    }

    private function showProbingIndicator(): void
    {
        if (null === $this->screen || null === $this->tui) {
            return;
        }

        // Force a terminal repaint so the user sees the indicator immediately
        // instead of staring at a frozen editor while provider HTTP runs.
        $this->screen->setWorkingMessage('Checking provider usage...');
        try {
            $this->tui->requestRender();
            $this->tui->processRender();
        } catch (\Throwable $e) {
            // Non-fatal: render may fail if the terminal is in a transient state.
            $this->logger->debug('UsageCommandHandler: immediate probing render failed (non-fatal)', [
                'component' => 'UsageCommandHandler',
                'event_type' => 'usage_probe_indicator_render_failed',
                'session_id' => $this->state->sessionId,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function clearProbingIndicator(): void
    {
        if (null === $this->screen) {
            return;
        }

        // Clear only our own message so concurrent Working... from a run is not clobbered incorrectly.
        if ('Checking provider usage...' === $this->screen->registry()->getWorkingMessage()) {
            $this->screen->setWorkingMessage('');
        }
    }

    /**
     * @return list<string>
     */
    private function formatSection(ProviderQuotaSectionDTO $section): array
    {
        $lines = ['### '.$section->title];

        if (null !== $section->error && '' !== $section->error) {
            $lines[] = '- **Error:** '.$section->error;
        }

        $windows = $section->windows;
        if ([] === $windows && (null === $section->error || '' === $section->error)) {
            $lines[] = '- Quota windows: unavailable';
        }

        foreach ($windows as $window) {
            $lines[] = '- '.$this->formatWindow($window);
        }

        if (null !== $section->plan && '' !== $section->plan) {
            $lines[] = '- **Plan:** '.$section->plan;
        }
        if (null !== $section->account && '' !== $section->account) {
            $lines[] = '- **Account:** '.$section->account;
        }
        if (null !== $section->credits) {
            $lines[] = \sprintf('- **Credits:** %.2f', $section->credits);
        }
        if (null !== $section->modelCount) {
            $lines[] = \sprintf('- **Models visible:** %d', $section->modelCount);
        }
        if (null !== $section->note && '' !== $section->note) {
            $lines[] = '- **Note:** '.$section->note;
        }

        return $lines;
    }

    private function formatWindow(ProviderQuotaWindowDTO $window): string
    {
        $reset = null !== $window->resetDescription && '' !== $window->resetDescription
            ? ', resets '.$window->resetDescription
            : '';

        return \sprintf(
            '%s: %.0f%% left%s',
            $window->label,
            $window->percentLeft,
            $reset,
        );
    }

    /**
     * @return list<string>
     */
    private function formatSessionLines(): array
    {
        $usage = $this->state->usage;
        $lines = ['### Session totals'];

        $model = trim($this->state->footerModel);
        if ('' !== $model) {
            $reasoning = trim($this->state->footerReasoning);
            $modelLine = '- **Model:** `'.$model.'`';
            if ('' !== $reasoning) {
                $modelLine .= ' (reasoning: '.$reasoning.')';
            }
            $lines[] = $modelLine;
        }

        $context = ContextUsageFormatter::format(
            '' !== $model ? $model : null,
            $usage->latestInputTokens,
            $this->state->contextWindow,
        );
        if (null !== $context) {
            // Latest-turn context usage — distinct from cumulative session tokens below.
            $lines[] = '- **Context (latest turn):** '.$context->text
                .\sprintf(' (%s / %s tokens)', number_format($usage->latestInputTokens), number_format($this->state->contextWindow));
        } elseif ($this->state->contextWindow > 0) {
            $lines[] = \sprintf(
                '- **Context window:** %s tokens (latest-turn usage unavailable)',
                number_format($this->state->contextWindow),
            );
        }

        $lines[] = \sprintf(
            '- **Tokens (session cumulative):** %s in / %s out',
            number_format($usage->inputTokens),
            number_format($usage->outputTokens),
        );
        $lines[] = \sprintf('- **Estimated cost:** $%.3f', $usage->totalCost);

        if ($usage->hasCacheTelemetry) {
            $cachePct = $usage->cacheReadHitPercentage();
            $cacheLine = \sprintf(
                '- **Cache:** %s read / %s creation',
                number_format($usage->cacheReadTokens),
                number_format($usage->cacheCreationTokens),
            );
            if (null !== $cachePct) {
                $cacheLine .= \sprintf(' (%.0f%% hit)', $cachePct);
            }
            $lines[] = $cacheLine;
        }

        return $lines;
    }

    private function sanitizeError(\Throwable $e): string
    {
        // User-facing text: class + bounded first line only; never multi-line dumps.
        $trimmed = trim($e->getMessage());
        if ('' === $trimmed) {
            return $e::class;
        }
        $firstLine = explode("\n", $trimmed, 2)[0];
        $bounded = mb_strlen($firstLine) > 200 ? mb_substr($firstLine, 0, 200).'…' : $firstLine;

        return $e::class.': '.$bounded;
    }
}
