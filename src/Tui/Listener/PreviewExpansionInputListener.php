<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Toggles session-local preview expansion for previewable transcript blocks (Ctrl+O).
 *
 * Only mutates {@see \Ineersa\Tui\Transcript\TranscriptDisplayState::$previewableBlocksExpanded}
 * on {@see \Ineersa\Tui\Runtime\TuiSessionState}. Does not touch Hatfield settings,
 * session metadata, runtime commands, or canonical events.
 *
 * Registered at priority {@see InputPriority::PREVIEW_EXPANSION}: Ctrl+C/D handling and completion-overlay cleanup
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
                $keys = $screen->editorWidget()->getKeybindings();
                if (!$keys->matches($event->getData(), 'toggle_preview_expansion')) {
                    return;
                }

                $event->stopPropagation();

                $state->transcriptDisplayState->previewableBlocksExpanded =
                    !$state->transcriptDisplayState->previewableBlocksExpanded;

                // Re-push blocks so TranscriptMountedWidget reconciles with the updated
                // preview expansion fingerprint and re-renders tool/diff previews.
                $screen->setTranscriptBlocks($state->transcript);

                $tui->requestRender();
            },
            priority: InputPriority::PREVIEW_EXPANSION,
        );
    }
}
