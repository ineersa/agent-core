<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final readonly class BackgroundBashInputListener implements TuiListenerRegistrar
{
    public function __construct(private BackgroundProcessManager $processes)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $overlay = new PickerOverlay();
        $close = static function () use ($overlay, $context): void {
            $overlay->close();
            $context->tui->setFocus($context->screen->editorWidget());
        };
        $processes = $this->processes;
        $context->tui->addListener(static function (InputEvent $event) use ($context, $overlay, $close, $processes): void {
            $parser = $context->screen->editorWidget()->getKeybindings()->getParser();
            $data = $event->getData();
            if ($parser->isKeyRelease($data) || !$parser->matches($data, Key::ctrl('b'))) {
                return;
            }

            if ($overlay->isOpen()) {
                $event->stopPropagation();
                $overlay->listWidget()?->handleInput("\r");

                return;
            }
            if ($context->screen->hasInputOverlay() || $context->tui->getFocus() !== $context->screen->editorWidget()) {
                return;
            }

            $runId = $context->state->visibleQuestionOwnerRunId();
            $candidates = $processes->foregroundTools($runId);
            if ([] === $candidates) {
                return;
            }
            $event->stopPropagation();
            $choose = static function (int $index) use ($context, $runId, $candidates, $close): void {
                $candidate = $candidates[$index];
                $close();
                if ($context->state->visibleQuestionOwnerRunId() !== $runId) {
                    return;
                }
                $context->client->send($runId, new UserCommand('background_bash', payload: [
                    'record_id' => $candidate->id,
                    'tool_call_id' => $candidate->toolCallId,
                ]));
            };
            if (1 === \count($candidates)) {
                $choose(0);

                return;
            }

            $items = [];
            foreach ($candidates as $index => $candidate) {
                $items[] = ['label' => \sprintf('Bash %s (PID %d)', $candidate->toolCallId, $candidate->pid), 'value' => (string) $index];
            }
            $list = new SelectListWidget(items: $items, maxVisible: SelectListKeybindings::MAX_VISIBLE);
            $list->onSelect(static function (SelectEvent $event) use ($choose): void {
                $choose((int) $event->getItem()['value']);
            });
            $list->onCancel($close);
            $overlay->mount($context->tui, $context->screen, $list, new TextWidget(text: 'Background Bash: select a command, Enter or Ctrl+B confirms, Esc cancels'));
        }, priority: InputPriority::COMPLETION_SUBAGENT - 1);
    }
}
