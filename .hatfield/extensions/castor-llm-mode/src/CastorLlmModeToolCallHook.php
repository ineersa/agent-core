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

        // bash is a typed DTO built-in with flat provider arguments; the
        // command field is a top-level key. Rewrites return the flat map.
        $command = $context->arguments['command'] ?? null;
        if (!\is_string($command)) {
            return null;
        }

        if (!$this->rewriter->isCastorCommand($command)) {
            return null;
        }

        // Rewritten arguments keep the flat provider shape.
        return ['command' => $this->rewriter->rewrite($command)];
    }
}
