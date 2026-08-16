<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Context DTO provided to ToolCallHookInterface::onToolCall().
 *
 * Contains identifier, invocation details, arguments, and runtime metadata
 * for a pending tool call. This is a public API DTO; all properties are
 * readonly and the class is final+readonly.
 *
 * @see ToolCallHookInterface
 * @see ToolCallDecisionDTO
 */
final readonly class ToolCallContextDTO
{
    /**
     * @param array<string, mixed> $arguments Provider-visible tool call arguments, shape depends on the tool kind:
     *                                        - Typed built-in tools (read, write, edit, bash, bg_status, view_image,
     *                                        ask_human, subagent, fork, agent_retrieve, hatfield_docs) follow the
     *                                        native Symfony AI method-parameter envelope: DTO fields are nested under
     *                                        the `arguments` key, e.g. ['arguments' => ['path' => './file.txt']].
     *                                        - Raw dynamic tools (MCP, extension-registered tools, settings) receive the
     *                                        flat provider map verbatim, e.g. ['path' => './file.txt'].
     * @param array<string, mixed> $metadata  runtime context (e.g. session flags, provider metadata)
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $cwd = null,
        public array $metadata = [],
    ) {
    }
}
