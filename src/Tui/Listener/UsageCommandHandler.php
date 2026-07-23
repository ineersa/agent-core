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

/**
 * Handler for the /usage slash command.
 *
 * Probes provider quotas through the runtime contract and formats
 * current-session totals from {@see TuiSessionState::$usage}.
 *
 * @internal Registered by UsageCommandRegistrar
 */
final class UsageCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly ProviderQuotaProbeServiceInterface $quotaProbe,
        private readonly TuiSessionState $state,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        try {
            $report = $this->quotaProbe->probe();
        } catch (\Throwable $e) {
            // Top-level guard: probe service should already degrade per-provider,
            // but never let an unexpected failure blank the session totals.
            return new TranscriptMessage(
                implode("\n", [
                    '## Provider usage / quota status',
                    '',
                    'Provider probes failed: '.$this->sanitizeError($e->getMessage()),
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

    private function sanitizeError(string $message): string
    {
        $trimmed = trim($message);
        if ('' === $trimmed) {
            return 'unknown error';
        }
        // Bound length; never echo multi-line dumps that might contain secrets.
        $firstLine = explode("\n", $trimmed, 2)[0];

        return mb_strlen($firstLine) > 200 ? mb_substr($firstLine, 0, 200).'…' : $firstLine;
    }
}
