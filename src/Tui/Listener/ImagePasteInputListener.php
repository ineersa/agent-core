<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\ImagePaste\ClipboardImageReaderInterface;
use Ineersa\Tui\ImagePaste\ClipboardImageReadOutcomeEnum;
use Ineersa\Tui\ImagePaste\PastedImagePendingDTO;
use Ineersa\Tui\ImagePaste\PastedImagePlaceholderFormatter;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Widget\BracketedPasteTrait;

/**
 * Intercepts standalone Ctrl+V for image clipboard paste.
 *
 * Clipboard helpers run asynchronously: start() returns immediately, poll() runs
 * on TuiTickDispatcher. Native bracketed paste buffering also recognizes image paths.
 * GitHub issue #119.
 */
final class ImagePasteInputListener implements TuiListenerRegistrar
{
    use BracketedPasteTrait;

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
        $editorWidget = $screen->editorWidget();

        $clipboardReader = $this->clipboardReader;
        $validationService = $this->validationService;
        $blockFactory = $this->blockFactory;
        $logger = $this->logger;

        $context->lifecycle->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType) use ($clipboardReader, $state): void {
            if (TuiSessionLifecycleEventTypeEnum::SessionEnded !== $eventType) {
                return;
            }

            if ($clipboardReader->isReading()) {
                $clipboardReader->cancel();
            }
            $state->pastedImagePasteInProgressIndex = null;
        });

        $context->ticks->add(static function () use (
            $screen,
            $state,
            $editor,
            $clipboardReader,
            $validationService,
            $blockFactory,
            $logger,
        ): ?bool {
            if (!$clipboardReader->isReading() && null === $state->pastedImagePasteInProgressIndex) {
                return null;
            }

            if (!$clipboardReader->isReading()) {
                $state->pastedImagePasteInProgressIndex = null;

                return null;
            }

            $poll = $clipboardReader->poll();
            if ($poll->pending) {
                return null;
            }

            $terminal = $poll->terminal;
            $index = $state->pastedImagePasteInProgressIndex;
            $placeholder = null !== $index ? PastedImagePlaceholderFormatter::placeholder($index) : null;
            $state->pastedImagePasteInProgressIndex = null;

            if (null === $terminal || null === $index || null === $placeholder) {
                return null;
            }

            $editorText = $editor->getText();
            $placeholderStillPresent = str_contains($editorText, $placeholder);

            if (ClipboardImageReadOutcomeEnum::Image !== $terminal->outcome) {
                if ($placeholderStillPresent) {
                    $editor->setText(str_replace($placeholder, '', $editorText));
                }
                self::appendSystemMessage(
                    $state,
                    $screen,
                    $blockFactory,
                    $terminal->userMessage ?? 'Clipboard image paste is not available.',
                );
                if (null !== $terminal->diagnostic) {
                    $logger->info('Clipboard image paste skipped', [
                        'component' => 'ImagePasteInputListener',
                        'event_type' => 'clipboard_paste_skipped',
                        'session_id' => $state->sessionId,
                        'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
                        'outcome' => $terminal->outcome->value,
                        'diagnostic' => $terminal->diagnostic,
                    ]);
                }
                $screen->requestRender();

                return null;
            }

            $tempPath = $terminal->tempPath;
            if (null === $tempPath) {
                if ($placeholderStillPresent) {
                    $editor->setText(str_replace($placeholder, '', $editorText));
                }
                $screen->requestRender();

                return null;
            }

            if (!$placeholderStillPresent) {
                @unlink($tempPath);
                $logger->info('Discarded clipboard paste after placeholder removed', [
                    'component' => 'ImagePasteInputListener',
                    'event_type' => 'clipboard_paste_discarded',
                    'session_id' => $state->sessionId,
                    'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
                    'image_index' => $index,
                ]);
                $screen->requestRender();

                return null;
            }

            try {
                $validationService->validateFile($tempPath);
            } catch (\Throwable $e) {
                $validationService->logValidationFailure('clipboard_paste_validation_failed', $e);
                @unlink($tempPath);
                $editor->setText(str_replace($placeholder, '', $editor->getText()));
                self::appendSystemMessage($state, $screen, $blockFactory, $e->getMessage());
                $screen->requestRender();

                return null;
            }

            $state->pastedImagePendingByIndex[$index] = new PastedImagePendingDTO(
                stagedPath: $tempPath,
            );

            $logger->info('Pasted image placeholder staged', [
                'component' => 'ImagePasteInputListener',
                'event_type' => 'clipboard_paste_staged',
                'session_id' => $state->sessionId,
                'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
                'image_index' => $index,
            ]);
            $screen->requestRender();

            return null;
        });

        $context->tui->addListener(
            function (InputEvent $event) use (
                $screen,
                $state,
                $editor,
                $clipboardReader,
                $blockFactory,
                $logger,
                $editorWidget,
                $context,
            ): void {
                if ($context->tui->getFocusManager()->getFocus() !== $editorWidget) {
                    return;
                }

                $data = $event->getData();
                $buffering = $this->isBufferingPaste();
                $paste = $this->processBracketedPaste($data);
                if ($buffering || $this->isBufferingPaste() || null !== $paste) {
                    $event->stopPropagation();
                    if (null !== $paste) {
                        if (!$this->stageImagePath($paste, $context)) {
                            $editorWidget->handleInput("\x1b[200~".$paste."\x1b[201~");
                        }
                    }
                    if ('' !== $data) {
                        $editorWidget->handleInput($data);
                    }
                    $screen->requestRender();

                    return;
                }

                if (!$editorWidget->getKeybindings()->matches($event->getData(), 'paste_image')) {
                    return;
                }

                if ($clipboardReader->isReading() || null !== $state->pastedImagePasteInProgressIndex) {
                    self::appendSystemMessage(
                        $state,
                        $screen,
                        $blockFactory,
                        'Already reading an image from the clipboard.',
                    );
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                $start = $clipboardReader->startRead();
                if (!$start->started) {
                    $immediate = $start->immediate;
                    if (null !== $immediate) {
                        self::appendSystemMessage(
                            $state,
                            $screen,
                            $blockFactory,
                            $immediate->userMessage ?? 'Clipboard image paste is not available.',
                        );
                        $screen->setTranscriptBlocks($state->transcript);
                        $screen->requestRender();
                        if (null !== $immediate->diagnostic) {
                            $logger->info('Clipboard image paste skipped', [
                                'component' => 'ImagePasteInputListener',
                                'event_type' => 'clipboard_paste_skipped',
                                'session_id' => $state->sessionId,
                                'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
                                'outcome' => $immediate->outcome->value,
                                'diagnostic' => $immediate->diagnostic,
                            ]);
                        }
                    }
                    $event->stopPropagation();

                    return;
                }

                $index = $state->nextPastedImageIndex;
                ++$state->nextPastedImageIndex;
                $placeholder = PastedImagePlaceholderFormatter::placeholder($index);
                $state->pastedImagePasteInProgressIndex = $index;

                $prefix = $editor->getText();
                if ('' !== $prefix && !str_ends_with($prefix, ' ') && !str_ends_with($prefix, "\n")) {
                    $editor->getWidget()->handleInput(' ');
                }
                $editor->getWidget()->handleInput($placeholder);

                $screen->requestRender();
                $event->stopPropagation();

                $logger->info('Pasted image placeholder inserted', [
                    'component' => 'ImagePasteInputListener',
                    'event_type' => 'clipboard_paste_started',
                    'session_id' => $state->sessionId,
                    'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
                    'image_index' => $index,
                ]);
            },
            priority: InputPriority::IMAGE_PASTE,
        );
    }

    private function stageImagePath(string $paste, TuiRuntimeContext $context): bool
    {
        $source = trim($paste);
        if (str_contains($source, "\0") || !(new Filesystem())->isAbsolutePath($source) || !is_file($source) || !is_readable($source)) {
            return false;
        }

        // Non-images stay text; supported but damaged/oversized images use normal validation errors.
        if (!$this->validationService->isSupportedImageFile($source)) {
            return false;
        }

        $staged = null;
        try {
            $this->validationService->validateFile($source);
            $filesystem = new Filesystem();
            $staged = $filesystem->tempnam(sys_get_temp_dir(), 'hatfield-paste-');
            $filesystem->copy($source, $staged, true);
            $this->validationService->validateFile($staged);
        } catch (\Throwable $e) {
            $this->validationService->logValidationFailure('image_path_paste_validation_failed', $e);
            if (null !== $staged) {
                (new Filesystem())->remove($staged);
            }
            self::appendSystemMessage($context->state, $context->screen, $this->blockFactory, $e->getMessage());
            $context->screen->requestRender();

            return true;
        }

        // Only owned copies enter pending state: submission and cancellation may delete them.
        $index = $context->state->nextPastedImageIndex++;
        $context->state->pastedImagePendingByIndex[$index] = new PastedImagePendingDTO(stagedPath: $staged);
        $editor = $context->screen->promptEditor();
        $prefix = $editor->getText();
        if ('' !== $prefix && !str_ends_with($prefix, ' ') && !str_ends_with($prefix, "\n")) {
            $editor->getWidget()->handleInput(' ');
        }
        $editor->getWidget()->handleInput(PastedImagePlaceholderFormatter::placeholder($index));
        $context->screen->requestRender();

        return true;
    }

    private static function appendSystemMessage(
        \Ineersa\Tui\Runtime\TuiSessionState $state,
        \Ineersa\Tui\Screen\ChatScreen $screen,
        TranscriptBlockFactory $blockFactory,
        string $message,
    ): void {
        $state->appendTranscriptBlock($blockFactory->system(
            runId: '' !== $state->sessionId ? $state->sessionId : 'draft',
            text: $message,
            seq: \count($state->transcript) + 1,
            style: 'info',
        ));
        $screen->setTranscriptBlocks($state->transcript);
    }
}
