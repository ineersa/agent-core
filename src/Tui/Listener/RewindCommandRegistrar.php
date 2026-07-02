<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Extension\FileRewind\FileRewindPickerFlowAdapter;
use Ineersa\CodingAgent\Extension\FileRewind\FileRewindRuntimePortsHolder;
use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class RewindCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly FileRewindPickerController $picker,
        private readonly TuiSessionSwitchServiceInterface $switcher,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $this->picker->setRuntimeRefs($context->tui, $context->screen, $context->state);
        $this->commandRegistry->setActiveSessionId($context->state->sessionId);
        $picker = $this->picker;
        FileRewindPickerFlowAdapter::instance()->setOpenCallback(static function (string $sessionId) use ($picker): void {
            $picker->open($sessionId);
        });
        FileRewindRuntimePortsHolder::instance()->ports()->bindConversationRewind(new class($this->switcher) implements ConversationRewindPortInterface {
            public function __construct(private TuiSessionSwitchServiceInterface $switcher)
            {
            }

            public function rewindToTurn(int $turnNo): void
            {
                $this->switcher->rewindToTurn($turnNo);
            }
        });
    }
}
