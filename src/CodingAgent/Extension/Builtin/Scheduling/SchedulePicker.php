<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\Scheduling;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class SchedulePicker implements ExtensionCommandHandlerInterface
{
    private ?TuiExtensionContextInterface $tui = null;
    private ?ContainerWidget $overlay = null;

    public function __construct(private readonly SessionScheduleRepository $schedules)
    {
    }

    public function wire(TuiExtensionContextInterface $context): void
    {
        $this->tui = $context;
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        $tui = $this->tui;
        if (null === $tui || null !== $this->overlay) {
            return;
        }
        $runId = $tui->getSessionId();
        $items = [];
        foreach ($this->schedules->all($runId) as $row) {
            $target = $row->arguments['command'] ?? $row->arguments['script'] ?? '';
            $target = \is_string($target) ? StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\r", "\n"], ' ', $target))) : '';
            $next = $this->schedules->recurring($row)->getTrigger()->getNextRunDate(new \DateTimeImmutable());
            $items[] = ['value' => $row->id, 'label' => \sprintf('%s | %s %s | every %s | next %s | expires %s', $row->id, $row->tool, mb_strimwidth($target, 0, 80, '…'), $row->every, $next?->format(\DateTimeInterface::ATOM) ?? 'none', $row->expiresAt?->format(\DateTimeInterface::ATOM) ?? 'on close')];
        }
        if ([] === $items) {
            $context->notify('No schedules in this live session.');

            return;
        }
        $header = new TextWidget(text: 'Schedules: Enter selects cancellation, Esc closes');
        $list = new SelectListWidget(items: $items, maxVisible: 10);
        $selected = null;
        $close = function () use ($tui): void {
            if (null !== $this->overlay) {
                $tui->removeOverlay($this->overlay);
            } $this->overlay = null;
            $tui->requestRender();
        };
        $list->onCancel($close);
        $list->onSelect(function (SelectEvent $event) use ($runId, $tui, $list, $header, &$selected, $close): void {
            if ($tui->getSessionId() !== $runId) {
                $close();

                return;
            }
            if (null === $selected) {
                $selected = $event->getItem()['value'];
                $header->setText('Cancel future invocations? Running work is not stopped.');
                $list->setItems([['value' => 'no', 'label' => 'No'], ['value' => 'yes', 'label' => 'Yes']]);

                return;
            }
            $close();
            if ('yes' === $event->getItem()['value']) {
                $this->schedules->cancel($runId, $selected);
                $tui->setTransientStatus('scheduling', 'Schedule cancelled. Running work is unchanged.');
                $tui->requestRender();
            }
        });
        $this->overlay = (new ContainerWidget())->add($header)->add($list);
        $tui->insertOverlayAfterEditor($this->overlay);
        $tui->setFocus($list);
        $tui->requestRender();
    }
}
