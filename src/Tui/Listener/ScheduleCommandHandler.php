<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Scheduling\SessionScheduleRepository;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final readonly class ScheduleCommandHandler implements SlashCommandHandler
{
    private PickerOverlay $overlay;

    public function __construct(private TuiRuntimeContext $context, private SessionScheduleRepository $schedules, private LoggerInterface $logger)
    {
        $this->overlay = new PickerOverlay();
        $context->tui->addListener(function (FocusEvent $event): void {
            if ($this->overlay->isOpen() && $event->getPrevious() === $this->overlay->listWidget() && $event->getTarget() !== $this->overlay->listWidget()) {
                $this->overlay->close();
            }
        });
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if ($this->overlay->isOpen()) {
            return new NoOp();
        }
        $runId = $this->context->state->handle->runId ?? $this->context->state->sessionId;
        $definitions = $this->schedules->all($runId);
        if ([] === $definitions) {
            return new TranscriptMessage('No schedules in this live session.', 'system');
        }
        $items = [];
        foreach ($definitions as $definition) {
            $next = $this->schedules->recurring($definition)->getTrigger()->getNextRunDate(Clock::get()->now());
            $target = $definition->arguments['command'] ?? $definition->arguments['script'] ?? '';
            $target = \is_string($target) ? StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\n", "\r"], ' ', $target))) : '';
            $target = mb_strimwidth($target, 0, 80, '…');
            $items[] = ['value' => $definition->id, 'label' => \sprintf('%s | %s %s | every %s | next %s | expires %s', $definition->id, $definition->tool, $target, $definition->every, $next?->format(\DateTimeInterface::ATOM) ?? 'none', $definition->expiresAt?->format(\DateTimeInterface::ATOM) ?? 'on close')];
        }
        $header = new TextWidget(text: 'Schedules: Enter selects cancellation, Esc closes');
        $list = new SelectListWidget(items: $items, maxVisible: SelectListKeybindings::MAX_VISIBLE);
        $selected = null;
        $close = function () use ($list): void {
            if ($this->context->tui->getFocus() === $list) {
                $this->context->tui->setFocus($this->context->screen->editorWidget());
            }
            $this->overlay->close();
        };
        $list->onCancel($close);
        $list->onSelect(function (SelectEvent $event) use ($runId, $list, $header, &$selected, $close): void {
            if ($this->context->tui->getFocus() !== $list || $this->context->sessionServices->questionCoordinator->actionRequired() || $this->context->sessionServices->questionController->isOpen()) {
                return;
            }
            if (null === $selected) {
                $selected = $event->getItem()['value'];
                $header->setText('Cancel future invocations? Running work is not stopped.');
                $list->setItems([['value' => 'no', 'label' => 'No'], ['value' => 'yes', 'label' => 'Yes']]);

                return;
            }
            $close();
            if ('yes' === $event->getItem()['value'] && $this->context->state->visibleQuestionOwnerRunId() === $runId) {
                try {
                    $this->schedules->cancel($runId, $selected);
                    $this->context->screen->setStatus('idle', 'Schedule cancelled. Running work is unchanged.');
                } catch (\Throwable $exception) {
                    $this->logger->warning('schedule.cancel_failed', [
                        'run_id' => $runId,
                        'session_id' => $runId,
                        'component' => 'tui.schedule',
                        'event_type' => 'schedule.cancel_failed',
                        'schedule_id' => $selected,
                        'exception_class' => $exception::class,
                    ]);
                    $this->context->screen->setStatus('error', 'Schedule cancellation failed. Reopen /schedule to inspect it.');
                }
                $this->context->screen->requestRender();
            }
        });
        $this->overlay->mount($this->context->tui, $this->context->screen, $list, $header);

        return new NoOp();
    }
}
