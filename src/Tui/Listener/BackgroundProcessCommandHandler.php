<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundProcessDTO;
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
    private ?BackgroundProcessDTO $pending = null;

    public function __construct(private readonly TuiRuntimeContext $context, private readonly LoggerInterface $logger)
    {
        $this->overlay = new PickerOverlay();
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
        $runId = $context->state->visibleQuestionOwnerRunId();
        try {
            $snapshot = $context->client->backgroundProcesses($runId);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $runId);

            return new NoOp();
        }
        if ([] === $snapshot) {
            return new TranscriptMessage('No background processes running.', 'system');
        }

        $items = [];
        foreach ($snapshot as $index => $process) {
            $elapsed = max(0, Clock::get()->now()->getTimestamp() - $process->startedAt->getTimestamp());
            $text = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\r", "\n"], ' ', $process->command)));
            $items[] = [
                'value' => (string) $index,
                'label' => \sprintf('PID %d | %s | %ds | %s', $process->pid, $process->status, $elapsed, mb_substr($text, 0, 120)),
            ];
        }
        $title = 'Background processes: Enter asks to stop, Esc closes';
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
                $header->setText(\sprintf('Stop this process? PID %d', $this->pending->pid));
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
                $stopped = $context->client->stopBackgroundProcess($runId, $process->id);
                $context->screen->setStatus('session', \sprintf('Process PID %d %s.', $process->pid, $stopped ? 'stop requested' : 'had already finished'));
            } catch (\Throwable $e) {
                $this->reportFailure($e, $runId);
            }
            $context->screen->requestRender();
        });
        $this->overlay->mount($context->tui, $context->screen, $list, $header);

        return new NoOp();
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
        $this->context->screen->setStatus('error', 'Background process action failed: '.ThrowableMessage::sanitize($e));
    }
}
