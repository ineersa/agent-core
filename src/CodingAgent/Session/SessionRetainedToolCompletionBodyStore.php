<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Infrastructure\Filesystem\PathSymlinkGuard;
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

    public function sealJsonStream(
        string $owningRootSessionId,
        string $messageId,
        $jsonStream,
        string $mediaType,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): RetainedToolCompletionBodyReference {
        $this->validateComponent($owningRootSessionId, 'owningRootSessionId');
        $this->validateComponent($messageId, 'messageId');
        if ('' === trim($mediaType)) {
            throw new \InvalidArgumentException('Sealed body mediaType must be nonblank.');
        }
        if (!\is_resource($jsonStream)) {
            throw new \InvalidArgumentException('Sealed body JSON stream must be an open resource.');
        }

        $path = $this->path($owningRootSessionId, $messageId);
        $lock = $this->lockFactory->createLock('retained-tool-completion.'.hash('sha256', $owningRootSessionId.'|'.$messageId));
        $lock->acquire(true);
        try {
            $this->assertOwnedPathContainment($owningRootSessionId, $path, createParents: true);
            $tmpPath = $path.'.tmp.'.bin2hex(random_bytes(8));
            $out = fopen($tmpPath, 'wb');
            if (false === $out) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Failed to open temporary retained completion body.');
            }

            $hash = hash_init('sha256');
            $bytes = 0;
            try {
                while (!feof($jsonStream)) {
                    $chunk = fread($jsonStream, 8192);
                    if (false === $chunk) {
                        throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Failed while streaming retained completion body.');
                    }
                    if ('' === $chunk) {
                        continue;
                    }
                    $written = fwrite($out, $chunk);
                    if (false === $written || $written !== \strlen($chunk)) {
                        throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Failed to persist streamed retained completion body.');
                    }
                    hash_update($hash, $chunk);
                    $bytes += $written;
                }
            } finally {
                fclose($out);
            }

            if (0 === $bytes) {
                @unlink($tmpPath);
                throw new \InvalidArgumentException('Sealed body JSON must be non-empty.');
            }
            $sha256 = hash_final($hash);
            if (null !== $expectedBytes && $expectedBytes !== $bytes) {
                @unlink($tmpPath);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Streamed retained completion body byte length mismatch.');
            }
            if (null !== $expectedSha256 && $expectedSha256 !== $sha256) {
                @unlink($tmpPath);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Streamed retained completion body digest mismatch.');
            }

            // Structural JSON validation only for JSON media under the inspection
            // budget. Larger or non-JSON sealed copies remain opaque byte streams
            // with hash integrity until a bounded inspect call decodes them.
            if (RetainedToolCompletionBounds::MEDIA_TYPE_JSON === $mediaType
                && $bytes <= RetainedToolCompletionBounds::INSPECTION_BODY_MAX_BYTES) {
                $this->assertValidJsonFile($tmpPath);
            }

            if (is_link($path) || PathSymlinkGuard::pathHasSymlinkAncestor($path, $this->sessionsBaseRealpath())) {
                @unlink($tmpPath);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body cannot be a symbolic link.');
            }
            if (is_file($path)) {
                $existingHash = hash_file('sha256', $path);
                $existingBytes = filesize($path);
                @unlink($tmpPath);
                if (false === $existingHash || false === $existingBytes) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Existing retained completion body is unreadable.');
                }
                if ($existingHash !== $sha256 || $existingBytes !== $bytes) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Conflict, 'Conflicting sealed retained completion body.');
                }
            } else {
                try {
                    if (!@rename($tmpPath, $path)) {
                        throw new AtomicFileWriterException('rename', \sprintf('Failed to publish retained completion body "%s".', $path));
                    }
                    @chmod($path, 0600);
                } catch (AtomicFileWriterException $exception) {
                    @unlink($tmpPath);
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
        } catch (\Throwable $exception) {
            if (isset($tmpPath) && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function inspectJson(
        RetainedToolCompletionBodyReference $reference,
        string $authorizedRootSessionId,
        ?int $maxDecodeBytes = null,
    ): array {
        $this->assertOwnedReference($reference, $authorizedRootSessionId);
        $path = $this->path($reference->owningRootSessionId, $reference->ownedBodyId);
        $lock = $this->lockFactory->createLock('retained-tool-completion.'.hash('sha256', $reference->owningRootSessionId.'|'.$reference->ownedBodyId));
        $lock->acquire(true);
        try {
            $this->assertOwnedPathContainment($reference->owningRootSessionId, $path, createParents: false);
            if (!is_file($path) || is_link($path) || PathSymlinkGuard::pathHasSymlinkAncestor($path, $this->sessionsBaseRealpath())) {
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
                if (false === $stat || $stat['size'] !== $reference->bytes || $bytes !== $reference->bytes || $digest !== $reference->sha256) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body failed integrity validation.');
                }

                $result = [
                    'bytes' => $reference->bytes,
                    'sha256' => $reference->sha256,
                    'media_type' => $reference->mediaType,
                    'truncated' => true,
                ];

                $budget = $maxDecodeBytes ?? RetainedToolCompletionBounds::INSPECTION_BODY_MAX_BYTES;
                if ($budget < 0) {
                    throw new \InvalidArgumentException('maxDecodeBytes must be non-negative.');
                }
                if ($reference->bytes > $budget) {
                    return $result;
                }

                if (!rewind($stream)) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body could not be rewound for bounded decode.');
                }
                $raw = stream_get_contents($stream, $budget + 1);
                if (!\is_string($raw) || \strlen($raw) !== $reference->bytes) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body could not be streamed within budget.');
                }
                if (RetainedToolCompletionBounds::MEDIA_TYPE_JSON !== $reference->mediaType) {
                    $result['truncated'] = false;
                    $result['text'] = $raw;

                    return $result;
                }
                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body JSON is corrupt.', $exception);
                }
                $result['truncated'] = false;
                $result['json'] = $decoded;

                return $result;
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
        try {
            $this->assertOwnedPathContainment($reference->owningRootSessionId, $path, createParents: false);
        } catch (RetainedToolCompletionFailure) {
            return false;
        }

        return is_file($path) && !is_link($path) && !PathSymlinkGuard::pathHasSymlinkAncestor($path, $this->sessionsBaseRealpath());
    }

    public function isHeld(string $authorizedRootSessionId, string $messageId): bool
    {
        $this->validateComponent($authorizedRootSessionId, 'authorizedRootSessionId');
        $this->validateComponent($messageId, 'messageId');
        $path = $this->path($authorizedRootSessionId, $messageId);
        try {
            $this->assertOwnedPathContainment($authorizedRootSessionId, $path, createParents: false);
        } catch (RetainedToolCompletionFailure) {
            return false;
        }

        return is_file($path) && !is_link($path) && !PathSymlinkGuard::pathHasSymlinkAncestor($path, $this->sessionsBaseRealpath());
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

    private function sessionsBaseRealpath(): string
    {
        $base = $this->sessions->resolveSessionsBasePath();
        $real = realpath($base);
        if (false === $real || !is_dir($real) || is_link($base)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Sessions base path is not a resolvable owned directory.');
        }

        return $real;
    }

    private function assertOwnedPathContainment(string $owningRootSessionId, string $path, bool $createParents): void
    {
        $sessionsBase = $this->sessionsBaseRealpath();
        $sessionDir = $sessionsBase.'/'.$owningRootSessionId;
        if (!is_dir($sessionDir) || is_link($sessionDir)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Owning root session directory is missing or redirected.');
        }
        $sessionReal = realpath($sessionDir);
        if (false === $sessionReal || !str_starts_with($sessionReal.'/', $sessionsBase.'/')) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Owning root session escapes the sessions base.');
        }

        $runtimeDir = $sessionDir.'/runtime';
        $bodyDir = $runtimeDir.'/retained-tool-completions';
        if ($createParents) {
            if (is_link($runtimeDir) || is_link($bodyDir)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion ancestor directory cannot be a symbolic link.');
            }
            $this->filesystem->mkdir($bodyDir, 0700);
        }
        foreach ([$runtimeDir, $bodyDir] as $ancestor) {
            if (!is_dir($ancestor) || is_link($ancestor)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion ancestor directory is missing or redirected.');
            }
            $ancestorReal = realpath($ancestor);
            if (false === $ancestorReal || !str_starts_with($ancestorReal.'/', $sessionReal.'/')) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion ancestor escapes the owning root session.');
            }
        }

        if (PathSymlinkGuard::pathHasSymlinkAncestor($path, $sessionsBase)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion path has a symbolic-link ancestor.');
        }

        $parentReal = realpath(\dirname($path));
        if (false === $parentReal || !str_starts_with($parentReal.'/', $sessionReal.'/')) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion path is outside the owning root session.');
        }
        if (is_file($path) || is_link($path)) {
            $pathReal = realpath($path);
            if (false === $pathReal || !str_starts_with($pathReal, $parentReal.'/')) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion leaf escapes the owning body directory.');
            }
        }
    }

    private function assertValidJsonFile(string $path): void
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            @unlink($path);
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Temporary retained completion body is unreadable.');
        }
        try {
            // Bounded structural validation: decode once from the temp file contents
            // already written on disk; callers must not keep the decoded tree.
            $raw = stream_get_contents($stream);
            if (!\is_string($raw) || '' === $raw) {
                @unlink($path);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Temporary retained completion body is empty.');
            }
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                @unlink($path);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Sealed retained completion body JSON is corrupt.', $exception);
            }
            if (!(null === $decoded || \is_bool($decoded) || \is_int($decoded) || \is_float($decoded) || \is_string($decoded) || \is_array($decoded))) {
                @unlink($path);
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Sealed retained completion body JSON must decode to a JSON value.');
            }
            unset($decoded, $raw);
        } finally {
            fclose($stream);
        }
    }
}
