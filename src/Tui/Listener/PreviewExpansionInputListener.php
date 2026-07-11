<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Toggles session-local preview expansion for previewable transcript blocks (Ctrl+O).
 *
 * Only mutates {@see \Ineersa\Tui\Transcript\TranscriptDisplayState::$previewableBlocksExpanded}
 * on {@see \Ineersa\Tui\Runtime\TuiSessionState}. Does not touch Hatfield settings,
 * session metadata, runtime commands, or canonical events.
 *
 * Registered at priority 98: Ctrl+C/D handling and completion-overlay cleanup
 * keep their higher-priority slots, while Ctrl+O is consumed before lower-priority
 * model/completion/editor input routing can treat it as normal editor input.
 */
final class PreviewExpansionInputListener implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $screen = $context->screen;
        $tui = $context->tui;

        $context->tui->addListener(
            static function (InputEvent $event) use ($state, $screen, $tui): void {
                if ("\x0f" !== $event->getData()) {
                    return;
                }

                $event->stopPropagation();

                $state->transcriptDisplayState->previewableBlocksExpanded =
                    !$state->transcriptDisplayState->previewableBlocksExpanded;

                // Re-push blocks so LiveTextWidget invalidates and the transcript
                // re-renders with the updated preview budget (cache keys include expansion).
                $screen->setTranscriptBlocks($state->transcript, forceInvalidate: true);

                $tui->requestRender();
            },
            priority: 98,
        );
    }
}
