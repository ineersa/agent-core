<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;

final class FakeRetainedToolCompletionBodyStore implements RetainedToolCompletionBodyStoreInterface
{
    /** @var array<string, array{json: string, media_type: string, bytes: int, sha256: string}> */
    private array $bodies = [];

    public function sealJsonStream(
        string $owningRootSessionId,
        string $messageId,
        $jsonStream,
        string $mediaType,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): RetainedToolCompletionBodyReference {
        if (!\is_resource($jsonStream)) {
            throw new \InvalidArgumentException('JSON stream must be a resource.');
        }
        $json = stream_get_contents($jsonStream);
        if (!\is_string($json) || '' === $json) {
            throw new \InvalidArgumentException('Sealed body JSON must be non-empty.');
        }
        $key = $owningRootSessionId.'|'.$messageId;
        $bytes = \strlen($json);
        $sha256 = hash('sha256', $json);
        if (null !== $expectedBytes && $expectedBytes !== $bytes) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Streamed retained completion body byte length mismatch.');
        }
        if (null !== $expectedSha256 && $expectedSha256 !== $sha256) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Streamed retained completion body digest mismatch.');
        }
        if (isset($this->bodies[$key]) && ($this->bodies[$key]['sha256'] !== $sha256 || $this->bodies[$key]['bytes'] !== $bytes)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Conflict, 'Conflicting sealed retained completion body.');
        }
        $this->bodies[$key] = [
            'json' => $json,
            'media_type' => $mediaType,
            'bytes' => $bytes,
            'sha256' => $sha256,
        ];

        return RetainedToolCompletionBodyReference::ownedSealed(
            owningRootSessionId: $owningRootSessionId,
            ownedBodyId: $messageId,
            mediaType: $mediaType,
            bytes: $bytes,
            sha256: $sha256,
        );
    }

    public function inspectJson(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId, ?int $maxDecodeBytes = null): array
    {
        if (RetainedToolCompletionBodyKindEnum::OwnedSealed !== $reference->kind) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Fake store only resolves owned sealed references.');
        }
        if ($reference->owningRootSessionId !== $authorizedRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion body is outside the authorized root session.');
        }
        $key = $reference->owningRootSessionId.'|'.$reference->ownedBodyId;
        if (!isset($this->bodies[$key])) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Retained completion body is missing.');
        }
        $stored = $this->bodies[$key];
        if ($stored['sha256'] !== $reference->sha256 || $stored['bytes'] !== $reference->bytes) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body failed integrity validation.');
        }
        $result = [
            'bytes' => $stored['bytes'],
            'sha256' => $stored['sha256'],
            'media_type' => $stored['media_type'],
            'truncated' => true,
        ];
        $budget = $maxDecodeBytes ?? \PHP_INT_MAX;
        if ($stored['bytes'] > $budget) {
            return $result;
        }
        if ('application/json' !== $stored['media_type']) {
            $result['truncated'] = false;
            $result['text'] = $stored['json'];

            return $result;
        }
        $decoded = json_decode($stored['json'], true, 512, \JSON_THROW_ON_ERROR);
        $result['truncated'] = false;
        $result['json'] = $decoded;

        return $result;
    }

    public function exists(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): bool
    {
        if ($reference->owningRootSessionId !== $authorizedRootSessionId) {
            return false;
        }

        return isset($this->bodies[$reference->owningRootSessionId.'|'.$reference->ownedBodyId]);
    }

    public function isHeld(string $authorizedRootSessionId, string $messageId): bool
    {
        return isset($this->bodies[$authorizedRootSessionId.'|'.$messageId]);
    }

    public function delete(string $owningRootSessionId, string $messageId): void
    {
        unset($this->bodies[$owningRootSessionId.'|'.$messageId]);
    }
}
