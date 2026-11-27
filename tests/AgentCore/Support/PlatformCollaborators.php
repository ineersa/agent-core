<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Symfony\AI\Platform\Message\MessageBag;

final class PlatformCollaborators
{
    public static function convertHook(AgentMessageConverter $converter): ConvertToLlmHookInterface
    {
        return new class($converter) implements ConvertToLlmHookInterface {
            public function __construct(private AgentMessageConverter $converter)
            {
            }

            public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null, string $modelName = ''): MessageBag
            {
                return $this->converter->toMessageBagForTarget($messages, $modelName);
            }
        };
    }

    public static function modelResolver(): ModelResolverInterface
    {
        return new class implements ModelResolverInterface {
            public function resolve(string $defaultModel, bool $hasConversationMessages, ModelInvocationInput $input, ModelResolutionOptions $options): ResolvedModel
            {
                return new ResolvedModel($defaultModel);
            }
        };
    }

    public static function toolSetResolver(string ...$names): ToolSetResolverInterface
    {
        return new class($names) implements ToolSetResolverInterface {
            public function __construct(private array $names)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet($this->names, $this->names);
            }
        };
    }

    public static function toolSettings(int $maxParallelism): ToolExecutionSettingsInterface
    {
        return new class($maxParallelism) implements ToolExecutionSettingsInterface {
            public function __construct(private int $maxParallelism)
            {
            }

            public function defaultMode(): string
            {
                return 'sequential';
            }

            public function maxParallelism(): int
            {
                return $this->maxParallelism;
            }
        };
    }
}
