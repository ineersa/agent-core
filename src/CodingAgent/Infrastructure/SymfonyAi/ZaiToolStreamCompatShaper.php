<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;

/**
 * Emits {@code tool_stream: true} into provider options for z.ai models
 * that support streaming tool-call deltas.
 *
 * Activated by {@see ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM}
 * in the resolved {@see ProviderCompatibility}.
 */
final readonly class ZaiToolStreamCompatShaper implements ProviderCompatibilityFeatureShaperInterface
{
    public function supports(ProviderCompatibility $compat): bool
    {
        return $compat->has(ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        ProviderCompatibility $compat,
    ): ProviderRequest {
        return new ProviderRequest(options: array_merge($options, ['tool_stream' => true]));
    }
}
