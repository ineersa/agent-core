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

        // SubmitListener promotes a draft session before calling this service; an empty id here
        // is a defensive safety net — do not promote placeholders without a session directory.
        if ('' === $state->sessionId) {
            $this->logger->warning('Pasted image promotion skipped without session id', [
                'component' => 'PastedImageSubmissionService',
                'event_type' => 'paste_promotion_skipped_no_session',
                'run_id' => 'draft',
                'session_id' => '',
            ]);

            return $text;
        }

        $attachmentsDir = $this->sessionStore->ensureSessionAttachmentsDirectory($state->sessionId);
        $sessionRelativeBase = $this->projectRelativeAttachmentsBase($state->sessionId);

        $referenced = [];
        /** @var list<array{index: int, placeholder: string, pending: PastedImagePendingDTO, validated: PastedImageValidatedDTO, destination: string, filename: string}> $plans */
        $plans = [];

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

                if (file_exists($destination)) {
                    $this->surfaceError($state, $screen, \sprintf('Session attachment already exists for %s.', $placeholder));

                    return null;
                }

                $plans[] = [
                    'index' => $index,
                    'placeholder' => $placeholder,
                    'pending' => $pending,
                    'validated' => $validated,
                    'destination' => $destination,
                    'filename' => $filename,
                ];
            }
        }

        $resolved = $text;
        /** @var list<string> $tempDestinations */
        $tempDestinations = [];
        /** @var list<string> $finalizedDestinations Paths created by successful rename() in this invocation */
        $finalizedDestinations = [];

        try {
            foreach ($plans as $plan) {
                $temp = $this->stageToTempDestination($plan['pending']->stagedPath, $plan['destination']);
                if (null === $temp) {
                    throw new \RuntimeException('Failed to stage pasted image into session attachments.');
                }
                $tempDestinations[] = $temp;
            }

            foreach ($plans as $i => $plan) {
                $temp = $tempDestinations[$i];
                if (!@rename($temp, $plan['destination'])) {
                    throw new \RuntimeException('Failed to finalize pasted image attachment.');
                }
                @chmod($plan['destination'], 0o600);
                $finalizedDestinations[] = $plan['destination'];
            }

            foreach ($plans as $plan) {
                $relativePath = $sessionRelativeBase.'/'.$plan['filename'];
                $reference = PastedImagePlaceholderFormatter::llmReference($plan['index'], $relativePath);
                $resolved = str_replace($plan['placeholder'], $reference, $resolved);

                $this->deleteStagedFile($plan['pending']->stagedPath);
                unset($state->pastedImagePendingByIndex[$plan['index']]);
            }
        } catch (\Throwable $e) {
            foreach ($tempDestinations as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
            foreach ($finalizedDestinations as $destination) {
                if (is_file($destination)) {
                    @unlink($destination);
                }
            }
            $this->surfaceError($state, $screen, $e->getMessage());

            return null;
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

    /**
     * Copy source bytes into a hidden temp file beside the final attachment name.
     *
     * Cross-filesystem promotion invariant: never write directly to the final attachment path
     * until all placeholders pass preflight. Each image is copied to a unique ".<name>.<rand>.tmp"
     * file in the attachments directory. All renames run only after every staged copy succeeds;
     * staged files and pending state are cleared only after every rename succeeds. On failure,
     * temp files and any destinations created in this invocation are unlinked; original staged
     * files and pending entries stay intact for retry. Pre-existing attachment files are never touched.
     */
    private function stageToTempDestination(string $source, string $destination): ?string
    {
        $dir = \dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return null;
        }

        $temp = $dir.'/.'.basename($destination).'.'.bin2hex(random_bytes(4)).'.tmp';
        if (!@copy($source, $temp)) {
            @unlink($temp);

            return null;
        }
        @chmod($temp, 0o600);

        return $temp;
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
            'run_id' => '' !== $state->sessionId ? $state->sessionId : 'draft',
            'message' => $message,
        ]);
    }
}
