<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\RetainedOutputCapBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Infrastructure\Filesystem\PathSymlinkGuard;
use Ineersa\CodingAgent\Config\OutputCapConfig;

/**
 * Seals host-owned output-cap full-body files into retained completion evidence.
 *
 * Arbitrary saved_path values outside the exact run-owned output-cap scope are ignored.
 * The sealed body is the exact capped text stream (text/plain), not a re-encoded preview.
 * Directory-level scope redirection (owned name → sibling run scope) is refused before open.
 */
final readonly class RetainedOutputCapBodyPromoter implements RetainedOutputCapBodyPromoterInterface
{
    public function __construct(
        private OutputCapConfig $config,
        private RetainedToolCompletionBodyStoreInterface $bodyStore,
    ) {
    }

    public function promoteIfOwned(
        string $owningRootSessionId,
        string $messageId,
        array $normalizedToolResult,
        string $sourceRunId,
    ): ?RetainedToolCompletionBodyReference {
        $details = $normalizedToolResult['details'] ?? null;
        if (!\is_array($details)) {
            return null;
        }
        $outputCap = $details['output_cap'] ?? null;
        if (!\is_array($outputCap) || true !== ($outputCap['capped'] ?? false)) {
            return null;
        }
        $savedPath = $outputCap['saved_path'] ?? null;
        if (!\is_string($savedPath) || '' === trim($savedPath)) {
            return null;
        }

        $canonicalRoot = realpath($this->config->storageDir);
        if (false === $canonicalRoot || !is_dir($canonicalRoot) || is_link($this->config->storageDir)) {
            return null;
        }
        $scopeName = 'run-'.hash('sha256', $sourceRunId);
        $scopeReal = PathSymlinkGuard::resolveDirectChildDirectory($canonicalRoot, $scopeName);
        if (null === $scopeReal) {
            return null;
        }
        $savedReal = PathSymlinkGuard::resolveContainedRegularFile($scopeReal, $savedPath);
        if (null === $savedReal) {
            return null;
        }

        $stream = fopen($savedReal, 'rb');
        if (false === $stream) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Owned output-cap body is unreadable.');
        }
        try {
            return $this->bodyStore->sealJsonStream(
                owningRootSessionId: $owningRootSessionId,
                messageId: $messageId,
                jsonStream: $stream,
                mediaType: 'text/plain',
            );
        } finally {
            fclose($stream);
        }
    }
}
