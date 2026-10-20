<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Utility\ThrowableMessage;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class BackgroundProcessCommandHandler implements SlashCommandHandler
{
    private readonly PickerOverlay $overlay;
    private ?BackgroundWorkDTO $pending = null;
    private ?BackgroundWorkDTO $requested = null;
    private ?string $requestedRoot = null;
    private ?string $pendingNotice = null;
    private float $lastStatusPoll = 0.0;

    public function __construct(private readonly TuiRuntimeContext $context, private readonly LoggerInterface $logger)
    {
        $this->overlay = new PickerOverlay();
        $context->ticks->add(function (): ?bool {
            $this->pollOperationalStatus();

            return null;
        });
        $context->tui->addListener(function (FocusEvent $event): void {
            if ($this->overlay->isOpen() && $event->getPrevious() === $this->overlay->listWidget() && $event->getTarget() !== $this->overlay->listWidget()) {
                // A question, dialog or live-view switch owns the new focus.
                // Dismiss our snapshot without stealing focus or fetching again.
                $this->overlay->close();
            }
        });
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if ($this->overlay->isOpen()) {
            return new NoOp();
        }
        $context = $this->context;
        $runId = $context->state->handle->runId ?? $context->state->sessionId;
        try {
            $opening = $context->client->backgroundWork($runId);
            $context->state->subagentLiveCatalog->synchronizeOperationalStatus($opening->children);
            $snapshot = array_values(array_filter($opening->work, static fn (BackgroundWorkDTO $work): bool => !$work->terminal));
        } catch (\Throwable $e) {
            $this->reportFailure($e, $runId);

            return new NoOp();
        }
        if ([] === $snapshot) {
            return new TranscriptMessage('No background work running.', 'system');
        }

        $items = [];
        foreach ($snapshot as $index => $process) {
            $text = $this->label($process);
            $elapsed = null === $process->startedAt ? '' : \sprintf(' | %ds', max(0, Clock::get()->now()->getTimestamp() - $process->startedAt->getTimestamp()));
            $items[] = [
                'value' => (string) $index,
                'label' => \sprintf('%s [%s]%s | %s', $process->kind, $process->status, $elapsed, $text),
            ];
        }
        $title = 'Background work: Enter asks to cancel, Esc closes';
        $header = new TextWidget(text: $title);
        $list = new SelectListWidget(items: $items, maxVisible: SelectListKeybindings::MAX_VISIBLE);
        $this->pending = null;
        $selected = 0;
        // Confirmation cancellation restores this exact opening snapshot, never
        // a fresh query. Reopening /bg is the only way to refresh the list.
        $restore = function () use ($list, $items, $header, $title, &$selected): void {
            $this->pending = null;
            $header->setText($title);
            $list->setItems($items)->setSelectedIndex($selected);
        };
        $close = function () use ($context, $list): void {
            // Move focus first: removing the focused widget emits FocusEvent,
            // which would otherwise reenter PickerOverlay::close().
            if ($context->tui->getFocus() === $list) {
                $context->tui->setFocus($context->screen->editorWidget());
            }
            $this->overlay->close();
        };
        $list->onCancel(function () use ($restore, $close): void {
            null !== $this->pending ? $restore() : $close();
        });
        $list->onSelect(function (SelectEvent $event) use ($context, $runId, $snapshot, $list, $header, &$selected, $restore, $close): void {
            if ($context->tui->getFocus() !== $list || $context->sessionServices->questionCoordinator->actionRequired() || $context->sessionServices->questionController->isOpen()) {
                return;
            }
            if (null === $this->pending) {
                $selected = (int) $event->getItem()['value'];
                $this->pending = $snapshot[$selected];
                $header->setText('Cancel this background work?');
                $list->setItems([['value' => 'no', 'label' => 'No'], ['value' => 'yes', 'label' => 'Yes']]);

                return;
            }
            if ('yes' !== $event->getItem()['value']) {
                $restore();

                return;
            }
            $process = $this->pending;
            $close();
            if ($context->state->visibleQuestionOwnerRunId() !== $runId) {
                $context->screen->setStatus('error', 'The visible session changed; reopen /bg.');
                $context->screen->requestRender();

                return;
            }
            try {
                // Revalidate the immutable invocation against current root ownership.
                // The opening snapshot is presentation, not execution authority.
                $current = $context->client->backgroundWork($runId, $process->processId);
                $target = array_find($current->work, static fn (BackgroundWorkDTO $work): bool => $work->id === $process->id && $work->kind === $process->kind);
                if (null === $target) {
                    $this->reportUnavailable($runId);

                    return;
                }
                $this->requested = $target;
                $this->requestedRoot = $runId;
                if (!$target->terminal) {
                    if (null !== $target->processId) {
                        $context->client->stopBackgroundProcess($target->runIds[0], $target->processId);
                    } else {
                        foreach ($target->runIds as $childRunId) {
                            $context->client->cancel($childRunId);
                        }
                    }
                }
                $this->pendingNotice = 'Cancellation requested for '.$this->label($target);
                $context->screen->setStatus('background', $this->pendingNotice);
                $this->lastStatusPoll = 0.0;
                $this->pollOperationalStatus();
            } catch (\Throwable $e) {
                $this->requested = null;
                $context->screen->setStatus('background', null);
                $this->reportFailure($e, $runId);
            }
            $context->screen->requestRender();
        });
        $this->overlay->mount($context->tui, $context->screen, $list, $header);

        return new NoOp();
    }

    private function pollOperationalStatus(): void
    {
        $context = $this->context;
        if (!$context->state->sessionReady || microtime(true) - $this->lastStatusPoll < 0.5) {
            return;
        }
        $runId = $context->state->handle->runId ?? $context->state->sessionId;
        if (null !== $this->requested && $context->screen->status('background') !== $this->pendingNotice) {
            // A newer UI notice owns this row; never replace it with an older result.
            $this->requested = null;
        }
        if (null !== $this->requested && $this->requestedRoot !== $runId) {
            $context->screen->setStatus('background', null);
            $this->requested = null;

            return;
        }
        if (null === $this->requested && !array_any($context->state->subagentLiveCatalog->all(), static fn ($child): bool => !$child->isTerminal())) {
            return;
        }
        $this->lastStatusPoll = microtime(true);
        try {
            $snapshot = $context->client->backgroundWork($runId, $this->requested?->processId);
            $context->state->subagentLiveCatalog->synchronizeOperationalStatus($snapshot->children);
            if (null === $this->requested) {
                return;
            }
            $requested = $this->requested;
            $actual = array_find($snapshot->work, static fn (BackgroundWorkDTO $work): bool => $work->kind === $requested->kind && $work->id === $requested->id);
            if (null === $actual) {
                $this->reportUnavailable($runId);

                return;
            }
            if ($actual->terminal) {
                $context->screen->setTransientStatus('background', $this->label($actual).': '.$actual->status.'.');
                $this->requested = null;
                $context->screen->requestRender();
            }
        } catch (\Throwable $e) {
            $hadRequest = null !== $this->requested;
            $this->requested = null;
            if ($hadRequest) {
                $context->screen->setStatus('background', null);
            }
            $this->reportFailure($e, $runId);
        }
    }

    private function reportFailure(\Throwable $e, string $runId): void
    {
        $this->logger->warning('background_process.command_failed', [
            'component' => 'tui.background_process',
            'event_type' => 'background_process.command_failed',
            'run_id' => $runId,
            'session_id' => $runId,
            'exception_class' => $e::class,
        ]);
        $this->context->screen->setTransientStatus('background-error', 'Background work action failed: '.ThrowableMessage::sanitize($e));
        $this->context->screen->requestRender();
    }

    private function reportUnavailable(string $runId): void
    {
        $this->requested = null;
        $this->logger->warning('background_work.unavailable', [
            'component' => 'tui.background_process', 'event_type' => 'background_work.unavailable',
            'run_id' => $runId, 'session_id' => $runId,
        ]);
        $this->context->screen->setTransientStatus('background', 'Background work is unavailable; termination is not confirmed.');
        $this->context->screen->requestRender();
    }

    private function label(BackgroundWorkDTO $work): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\r", "\n"], ' ', $work->label)));
    }
}
