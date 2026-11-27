<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\Event;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by HeadlessController for each decoded JSONL command under the
 * event name NAME_PREFIX.$command->type (e.g. controller.command.start_run).
 *
 * Listeners use one #[AsEventListener] per handled command type with that
 * per-type event name. The controller rejects a command type without
 * listeners before ACK.
 *
 * The $emit callable allows listeners to write RuntimeEvents back to
 * the controller's stdout.
 */
final class ControllerCommandEvent extends Event
{
    public const string NAME_PREFIX = 'controller.command.';

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
