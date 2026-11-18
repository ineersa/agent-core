<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;

/**
 * An OM tool handler whose structured result is also available unencoded, so
 * the Claude Code MCP bridge can report `ok: false` / `isError` results as
 * MCP tool errors while sending the same TOON text Hatfield receives.
 */
interface OmToolHandlerInterface extends ContextualExtensionToolHandlerInterface
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function result(array $arguments, ToolInvocationContextDTO $context): array;
}
