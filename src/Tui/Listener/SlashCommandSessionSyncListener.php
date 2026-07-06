<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;

/**
 * Keeps {@see SlashCommandRegistry} active session id aligned with the current
 * TUI session after resume/switch, not only after draft promotion on submit.
 */
final class SlashCommandSessionSyncListener implements TuiListenerRegistrar
{
    public function __construct(private readonly SlashCommandRegistry $slashCommandRegistry)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $registry = $this->slashCommandRegistry;
        $context->lifecycle->subscribe(static function (TuiSessionLifecycleEventDTO $event) use ($registry): void {
            if (!self::isSessionIdentityEvent($event->type)) {
                return;
            }
            if ('' === $event->sessionId) {
                $registry->setActiveSessionId(null);

                return;
            }
            $registry->setActiveSessionId($event->sessionId);
        });
    }

    private static function isSessionIdentityEvent(TuiSessionLifecycleEventTypeEnum $type): bool
    {
        return match ($type) {
            TuiSessionLifecycleEventTypeEnum::SessionStarted,
            TuiSessionLifecycleEventTypeEnum::SessionResumed,
            TuiSessionLifecycleEventTypeEnum::SessionDraftStarted => true,
            default => false,
        };
    }
}
