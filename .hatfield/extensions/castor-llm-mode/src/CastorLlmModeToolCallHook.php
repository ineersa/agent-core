<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\CastorLlmMode;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;

final class CastorLlmModeToolCallHook implements ToolCallRewriteHookInterface
{
    public function __construct(
        private readonly CastorCommandRewriter $rewriter,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rewriteArguments(ToolCallContextDTO $context): ?array
    {
        if ('bash' !== $context->toolName) {
            return null;
        }

        // Typed DTO tools (bash) arrive in the native nested shape.
        $effective = $context->arguments['arguments'] ?? $context->arguments;
        $command = \is_array($effective) ? ($effective['command'] ?? null) : null;
        if (!\is_string($command)) {
            return null;
        }

        if (!$this->rewriter->isCastorCommand($command)) {
            return null;
        }

        $rewritten = ['command' => $this->rewriter->rewrite($command)];
        if (isset($context->arguments['arguments'])) {
            return ['arguments' => $rewritten];
        }

        return $rewritten;
    }
}
