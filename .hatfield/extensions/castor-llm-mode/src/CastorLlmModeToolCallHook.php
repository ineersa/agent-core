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

        // bash is a typed DTO built-in: calls arrive in the native Symfony
        // method-parameter envelope, DTO fields under the `arguments` key.
        // No flat fallback — a non-enveloped call is malformed, not legacy.
        $fields = $context->arguments['arguments'] ?? null;
        if (!\is_array($fields)) {
            return null;
        }

        $command = $fields['command'] ?? null;
        if (!\is_string($command)) {
            return null;
        }

        if (!$this->rewriter->isCastorCommand($command)) {
            return null;
        }

        // Rewritten arguments keep the native envelope shape.
        return ['arguments' => ['command' => $this->rewriter->rewrite($command)]];
    }
}
