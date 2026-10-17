<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Sealed retained completion bodies under the owning root session tree.
 *
 * Layout: <sessions>/<root>/runtime/retained-tool-completions/<message_id>.json
 * Explicit root-session deletion removes the whole tree. Ordinary shutdown and
 * output-cap cleanup do not touch these files.
 */
final readonly class SessionRetainedToolCompletionBodyStore implements RetainedToolCompletionBodyStoreInterface
{
    private const string RELATIVE_DIR = 'runtime/retained-tool-completions';

    public function __construct(
        private HatfieldSessionStore $sessions,
        private LockFactory $lockFactory,
        private Filesystem $filesystem,
    ) {
    }

    public function sealJson(
        string $owningRootSessionId,
        string $messageId,
        string $json,
        string $mediaType,
    ): RetainedToolCompletionBodyReference {
        $this->validateComponent($owningRootSessionId, 'owningRootSessionId');
        $this->validateComponent($messageId, 'messageId');
        if ('' === trim($mediaType)) {
            throw new \InvalidArgumentException('Sealed body mediaType must be nonblank.');
        }
        if ('' === $json) {
            throw new \InvalidArgumentException('Sealed body JSON must be non-empty.');
        }
        json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $path = $this->path($owningRootSessionId, $messageId);
        $lock = $this->lockFactory->createLock('retained-tool-completion.'.hash('sha256', $owningRootSessionId.'|'.$messageId));
        $lock->acquire(true);
        try {
            $this->filesystem->mkdir(\dirname($path), 0700);
            $bytes = \strlen($json);
            $sha256 = hash('sha256', $json);
            if (is_link($path)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body cannot be a symbolic link.');
            }
            if (is_file($path)) {
                $existingHash = hash_file('sha256', $path);
                $existingBytes = filesize($path);
                if (false === $existingHash || false === $existingBytes) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Existing retained completion body is unreadable.');
                }
                if ($existingHash !== $sha256 || $existingBytes !== $bytes) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Conflict, 'Conflicting sealed retained completion body.');
                }
            } else {
                try {
                    AtomicFileWriter::write($path, $json, fileMode: 0600, directoryMode: 0700);
                } catch (AtomicFileWriterException $exception) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Failed to seal retained completion body.', $exception);
                }
            }

            return RetainedToolCompletionBodyReference::ownedSealed(
                owningRootSessionId: $owningRootSessionId,
                ownedBodyId: $messageId,
                mediaType: $mediaType,
                bytes: $bytes,
                sha256: $sha256,
            );
        } finally {
            $lock->release();
        }
    }

    public function readJson(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): array
    {
        $this->assertOwnedReference($reference, $authorizedRootSessionId);
        $path = $this->path($reference->owningRootSessionId, $reference->ownedBodyId);
        $lock = $this->lockFactory->createLock('retained-tool-completion.'.hash('sha256', $reference->owningRootSessionId.'|'.$reference->ownedBodyId));
        $lock->acquire(true);
        try {
            if (!is_file($path) || is_link($path)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Retained completion body is missing.');
            }
            $stream = fopen($path, 'rb');
            if (false === $stream) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body is unreadable.');
            }
            try {
                $stat = fstat($stream);
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                $digest = hash_final($hash);
                if (false === $stat || $stat['size'] !== $reference->bytes || $bytes !== $reference->bytes || $digest !== $reference->sha256 || !rewind($stream)) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body failed integrity validation.');
                }
                $raw = stream_get_contents($stream);
                if (!\is_string($raw)) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body could not be streamed.');
                }
                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body JSON is corrupt.', $exception);
                }
                if (!\is_array($decoded)) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body JSON must decode to an array.');
                }

                return [
                    'bytes' => $reference->bytes,
                    'sha256' => $reference->sha256,
                    'media_type' => $reference->mediaType,
                    'json' => $decoded,
                ];
            } finally {
                fclose($stream);
            }
        } finally {
            $lock->release();
        }
    }

    public function exists(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): bool
    {
        $this->assertOwnedReference($reference, $authorizedRootSessionId);
        $path = $this->path($reference->owningRootSessionId, $reference->ownedBodyId);

        return is_file($path) && !is_link($path);
    }

    private function assertOwnedReference(
        RetainedToolCompletionBodyReference $reference,
        string $authorizedRootSessionId,
    ): void {
        $this->validateComponent($authorizedRootSessionId, 'authorizedRootSessionId');
        if (RetainedToolCompletionBodyKindEnum::OwnedSealed !== $reference->kind) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Body store only resolves owned sealed references.');
        }
        if ($reference->owningRootSessionId !== $authorizedRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion body is outside the authorized root session.');
        }
        $this->validateComponent((string) $reference->ownedBodyId, 'ownedBodyId');
    }

    private function path(string $owningRootSessionId, string $messageId): string
    {
        return $this->sessions->resolveSessionsBasePath()
            .'/'.$owningRootSessionId
            .'/'.self::RELATIVE_DIR
            .'/'.$messageId.'.json';
    }

    private function validateComponent(string $value, string $field): void
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not be empty.', $field));
        }
        if (false !== strpbrk($value, '/\\') || '.' === $value || '..' === $value || str_contains($value, "\0")) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, \sprintf('Invalid retained completion path component "%s".', $field));
        }
    }
}
