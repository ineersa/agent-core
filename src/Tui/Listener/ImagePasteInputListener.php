<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\ImagePaste\ClipboardImageReaderInterface;
use Ineersa\Tui\ImagePaste\ClipboardImageReadOutcomeEnum;
use Ineersa\Tui\ImagePaste\PastedImagePendingDTO;
use Ineersa\Tui\ImagePaste\PastedImagePlaceholderFormatter;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Intercepts standalone Ctrl+V (\\x16) for image clipboard paste.
 *
 * Bracketed text paste (ESC[200~ … ESC[201~) is unchanged — handled by Symfony EditorWidget.
 * GitHub issue #119.
 */
final class ImagePasteInputListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly ClipboardImageReaderInterface $clipboardReader,
        private readonly PastedImageValidationService $validationService,
        private readonly TranscriptBlockFactory $blockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;
        $state = $context->state;
        $editor = $screen->promptEditor();

        $clipboardReader = $this->clipboardReader;
        $validationService = $this->validationService;
        $blockFactory = $this->blockFactory;
        $logger = $this->logger;

        $context->tui->addListener(
            static function (InputEvent $event) use (
                $screen,
                $state,
                $editor,
                $clipboardReader,
                $validationService,
                $blockFactory,
                $logger,
            ): void {
                if ("\x16" !== $event->getData()) {
                    return;
                }

                $read = $clipboardReader->readImageToTempFile();
                if (ClipboardImageReadOutcomeEnum::Image !== $read->outcome) {
                    $message = $read->userMessage ?? 'Clipboard image paste is not available.';
                    $state->transcript[] = $blockFactory->system(
                        runId: '' !== $state->sessionId ? $state->sessionId : 'draft',
                        text: $message,
                        seq: \count($state->transcript) + 1,
                        style: 'info',
                    );
                    $screen->setTranscriptBlocks($state->transcript);
                    $screen->requestRender();
                    $event->stopPropagation();

                    if (null !== $read->diagnostic) {
                        $logger->info('Clipboard image paste skipped', [
                            'component' => 'ImagePasteInputListener',
                            'event_type' => 'clipboard_paste_skipped',
                            'outcome' => $read->outcome->value,
                            'diagnostic' => $read->diagnostic,
                        ]);
                    }

                    return;
                }

                $tempPath = $read->tempPath;
                if (null === $tempPath) {
                    return;
                }

                try {
                    $validationService->validateFile($tempPath);
                } catch (\Throwable $e) {
                    $validationService->logValidationFailure('clipboard_paste_validation_failed', $e);
                    @unlink($tempPath);
                    $state->transcript[] = $blockFactory->system(
                        runId: '' !== $state->sessionId ? $state->sessionId : 'draft',
                        text: $e->getMessage(),
                        seq: \count($state->transcript) + 1,
                        style: 'info',
                    );
                    $screen->setTranscriptBlocks($state->transcript);
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                $index = $state->nextPastedImageIndex;
                ++$state->nextPastedImageIndex;
                $placeholder = PastedImagePlaceholderFormatter::placeholder($index);

                $state->pastedImagePendingByIndex[$index] = new PastedImagePendingDTO(
                    index: $index,
                    placeholder: $placeholder,
                    stagedPath: $tempPath,
                );

                $prefix = $editor->getText();
                if ('' !== $prefix && !str_ends_with($prefix, ' ') && !str_ends_with($prefix, "\n")) {
                    $editor->getWidget()->handleInput(' ');
                }
                $editor->getWidget()->handleInput($placeholder);

                $screen->requestRender();
                $event->stopPropagation();

                $logger->info('Pasted image placeholder inserted', [
                    'component' => 'ImagePasteInputListener',
                    'event_type' => 'clipboard_paste_staged',
                    'session_id' => $state->sessionId,
                    'image_index' => $index,
                ]);
            },
            priority: 96,
        );
    }
}
