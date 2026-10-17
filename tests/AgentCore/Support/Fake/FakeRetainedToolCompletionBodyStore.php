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

    public function sealJson(
        string $owningRootSessionId,
        string $messageId,
        string $json,
        string $mediaType,
    ): RetainedToolCompletionBodyReference {
        $key = $owningRootSessionId.'|'.$messageId;
        $bytes = \strlen($json);
        $sha256 = hash('sha256', $json);
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

    public function readJson(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): array
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
        $decoded = json_decode($stored['json'], true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion body JSON must decode to an array.');
        }

        return [
            'bytes' => $stored['bytes'],
            'sha256' => $stored['sha256'],
            'media_type' => $stored['media_type'],
            'json' => $decoded,
        ];
    }

    public function exists(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): bool
    {
        if ($reference->owningRootSessionId !== $authorizedRootSessionId) {
            return false;
        }

        return isset($this->bodies[$reference->owningRootSessionId.'|'.$reference->ownedBodyId]);
    }

    public function delete(string $owningRootSessionId, string $messageId): void
    {
        unset($this->bodies[$owningRootSessionId.'|'.$messageId]);
    }
}
