<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\Event;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by HeadlessController for each decoded JSONL command.
 *
 * Listeners use #[AsEventListener] and check $command->type to handle
 * specific command types (start_run, user_message, cancel, resume).
 *
 * The $emit callable allows listeners to write RuntimeEvents back to
 * the controller's stdout.
 */
final class ControllerCommandEvent extends Event
{
    /**
     * @param callable(RuntimeEvent): void $emit
     */
    public function __construct(
        public readonly RuntimeCommand $command,
        private readonly mixed $emit,
        public readonly string $sessionId = '',
    ) {
    }

    /**
     * Emit a runtime event to the controller's stdout.
     */
    public function emit(RuntimeEvent $event): void
    {
        ($this->emit)($event);
    }
}
