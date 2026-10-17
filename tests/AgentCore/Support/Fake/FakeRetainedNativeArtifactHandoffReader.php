<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Tool\RetainedNativeArtifactHandoffReaderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;

final class FakeRetainedNativeArtifactHandoffReader implements RetainedNativeArtifactHandoffReaderInterface
{
    /** @var array<string, array{text: string, media_type: string}> */
    private array $bodies = [];

    public function seed(
        string $parentRunId,
        string $artifactId,
        string $handoffId,
        string $text,
        string $mediaType = 'text/markdown',
    ): void {
        $this->bodies[$parentRunId.'|'.$artifactId.'|'.$handoffId] = [
            'text' => $text,
            'media_type' => $mediaType,
        ];
    }

    public function inspectHandoff(
        string $authorizedRootSessionId,
        string $parentRunId,
        string $artifactId,
        string $handoffId,
        int $expectedBytes,
        string $expectedSha256,
        string $expectedMediaType,
        ?int $maxDecodeBytes = null,
    ): array {
        if ($parentRunId !== $authorizedRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff is outside the authorized root session.');
        }
        $key = $parentRunId.'|'.$artifactId.'|'.$handoffId;
        if (!isset($this->bodies[$key])) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Native handoff generation is missing.');
        }
        $stored = $this->bodies[$key];
        $bytes = \strlen($stored['text']);
        $sha256 = hash('sha256', $stored['text']);
        if ($bytes !== $expectedBytes || $sha256 !== $expectedSha256 || $stored['media_type'] !== $expectedMediaType) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Native handoff generation failed integrity validation.');
        }
        $budget = $maxDecodeBytes ?? \PHP_INT_MAX;
        $result = [
            'bytes' => $bytes,
            'sha256' => $sha256,
            'media_type' => $expectedMediaType,
            'truncated' => $bytes > $budget,
        ];
        if ($bytes <= $budget) {
            $result['text'] = $stored['text'];
        }

        return $result;
    }
}
