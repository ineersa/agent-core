<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

/**
 * Public Hatfield-owned background-agent capability for extensions.
 *
 * Invokes a configured model through Hatfield's normal AI infrastructure
 * with only the isolated tools supplied on the request. The public operation
 * is blocking: it returns only after the provider transport completes and any
 * tool-loop work finishes. Internally Hatfield may stream (HTTP SSE, WebSocket,
 * etc.) so providers such as Codex remain usable.
 *
 * This API never exposes Hatfield ambient tools, provider objects, credentials,
 * Symfony AI types, streaming handles, or structured-output helpers.
 */
interface AgentRunnerInterface
{
    /**
     * Run one isolated agent invocation to completion.
     *
     * Side effects occur only through the request's isolated tools (for
     * example extension tools that persist observations). Model/provider/tool
     * failures propagate as exceptions.
     */
    public function run(AgentCallRequestDTO $request): void;
}
