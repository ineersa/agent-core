<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;

/**
 * Promotes staged pasted images into session attachments and rewrites editor text.
 *
 * Only placeholders still present in the submitted prompt are promoted. Orphan staged
 * files (placeholder removed in the editor) are deleted best-effort; unsubmitted temp
 * files may remain until OS /tmp cleanup (documented limitation, issue #119).
 */
final class PastedImageSubmissionService
{
    public function __construct(
        private readonly PastedImageValidationService $validationService,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly AppConfig $appConfig,
        private readonly TranscriptBlockFactory $blockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function textContainsPlaceholder(string $text): bool
    {
        return 1 === preg_match(PastedImagePlaceholderFormatter::PLACEHOLDER_PATTERN, $text);
    }

    /**
     * @return string|null Resolved prompt text, or null when promotion failed (error surfaced)
     */
    public function resolveSubmittedText(
        string $text,
        TuiSessionState $state,
        ChatScreen $screen,
    ): ?string {
        if (!preg_match(PastedImagePlaceholderFormatter::PLACEHOLDER_PATTERN, $text)
            && [] === $state->pastedImagePendingByIndex) {
            return $text;
        }

        if ('' === $state->sessionId) {
            return $text;
        }

        $attachmentsDir = $this->sessionStore->ensureSessionAttachmentsDirectory($state->sessionId);
        $sessionRelativeBase = $this->projectRelativeAttachmentsBase($state->sessionId);

        $resolved = $text;
        $referenced = [];

        if (preg_match_all(PastedImagePlaceholderFormatter::PLACEHOLDER_PATTERN, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $index = (int) $match[1];
                $placeholder = $match[0];
                $referenced[$index] = true;

                $pending = $state->pastedImagePendingByIndex[$index] ?? null;
                if (null === $pending) {
                    $this->surfaceError($state, $screen, \sprintf('Missing staged image for %s.', $placeholder));

                    return null;
                }

                try {
                    $validated = $this->validationService->validateFile($pending->stagedPath);
                } catch (\Throwable $e) {
                    $this->validationService->logValidationFailure('paste_promotion_validation_failed', $e);
                    $this->surfaceError($state, $screen, $e->getMessage());

                    return null;
                }

                $filename = \sprintf('pasted-image-%d.%s', $index, $validated->extension);
                $destination = $attachmentsDir.'/'.$filename;

                if (!$this->copyFileAtomically($pending->stagedPath, $destination)) {
                    $this->surfaceError($state, $screen, 'Failed to save pasted image into session attachments.');

                    return null;
                }

                @chmod($destination, 0o644);

                $relativePath = $sessionRelativeBase.'/'.$filename;
                $reference = PastedImagePlaceholderFormatter::llmReference($index, $relativePath);
                $resolved = str_replace($placeholder, $reference, $resolved);

                $this->deleteStagedFile($pending->stagedPath);
                unset($state->pastedImagePendingByIndex[$index]);
            }
        }

        foreach ($state->pastedImagePendingByIndex as $index => $pending) {
            if (!isset($referenced[$index])) {
                $this->deleteStagedFile($pending->stagedPath);
                unset($state->pastedImagePendingByIndex[$index]);
            }
        }

        return $resolved;
    }

    private function projectRelativeAttachmentsBase(string $sessionId): string
    {
        $sessionsPath = $this->appConfig->sessions->path;
        if ('' === $sessionsPath) {
            return '.hatfield/sessions/'.$sessionId.'/attachments';
        }

        if (str_starts_with($sessionsPath, '/')) {
            $cwd = rtrim($this->appConfig->cwd, '/');
            if (str_starts_with($sessionsPath, $cwd.'/')) {
                $tail = substr($sessionsPath, \strlen($cwd) + 1);

                return rtrim($tail, '/').'/'.$sessionId.'/attachments';
            }

            return $sessionsPath.'/'.$sessionId.'/attachments';
        }

        return rtrim($sessionsPath, '/').'/'.$sessionId.'/attachments';
    }

    private function copyFileAtomically(string $source, string $destination): bool
    {
        $dir = \dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return false;
        }

        $temp = $dir.'/.'.basename($destination).'.'.bin2hex(random_bytes(4)).'.tmp';
        if (!@copy($source, $temp)) {
            $bytes = @file_get_contents($source);
            if (false === $bytes) {
                @unlink($temp);

                return false;
            }
            if (false === @file_put_contents($temp, $bytes, \LOCK_EX)) {
                @unlink($temp);

                return false;
            }
        }

        if (!@rename($temp, $destination)) {
            if (!@copy($temp, $destination)) {
                @unlink($temp);

                return false;
            }
            @unlink($temp);
        }

        return is_file($destination);
    }

    private function deleteStagedFile(string $path): void
    {
        if ('' !== $path && is_file($path)) {
            @unlink($path);
        }
    }

    private function surfaceError(TuiSessionState $state, ChatScreen $screen, string $message): void
    {
        $state->transcript[] = $this->blockFactory->error(
            runId: '' !== $state->sessionId ? $state->sessionId : 'draft',
            text: 'Image paste: '.$message,
            seq: \count($state->transcript) + 1,
        );
        $screen->setTranscriptBlocks($state->transcript);
        $screen->setWorkingMessage('');

        $this->logger->error('Pasted image promotion failed', [
            'component' => 'PastedImageSubmissionService',
            'event_type' => 'paste_promotion_failed',
            'session_id' => $state->sessionId,
            'message' => $message,
        ]);
    }
}
