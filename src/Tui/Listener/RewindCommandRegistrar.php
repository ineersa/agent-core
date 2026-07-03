<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Extension\FileRewind\FileRewindRuntimePorts;
use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\FileRewind\TuiFileRewindPickerFlow;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class RewindCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly FileRewindPickerController $picker,
        private readonly FileRewindRuntimePorts $fileRewindRuntimePorts,
        private readonly TuiFileRewindPickerFlow $pickerFlow,
        private readonly TuiSessionSwitchServiceInterface $switcher,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $this->picker->setRuntimeRefs($context->tui, $context->screen, $context->state);
        $picker = $this->picker;
        $this->pickerFlow->setOpenCallback(static function (string $sessionId) use ($picker): void {
            $picker->open($sessionId);
        });
        $this->fileRewindRuntimePorts->bindConversationRewind(new class($this->switcher) implements ConversationRewindPortInterface {
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
