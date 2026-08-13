<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Explicit decoder for the stable RunStarted metadata shape persisted by StartRunHandler.
 *
 * Ladder: not Symfony Serializer — nested event envelope is generic RunEvent.payload with
 * a few optional fields; a small decoder avoids a synthetic full-envelope DTO while still
 * giving typed internal consumers.
 *
 * Canonical path:
 *   $event->payload['payload']['metadata']
 *     session.{kind,child_kind,parent_run_id,agent_name,artifact_id,interactive}
 *     model, reasoning, provider, context_window
 *     tools_scope.{allowed_tools,mcp}
 *     extensions (list of class names; key may be absent)
 */
final class RunStartedMetadataDecoder
{
    /**
     * @param array<string, mixed> $eventPayload Full RunEvent.payload for a run_started event
     */
    public function fromRunEventPayload(array $eventPayload): ?RunStartedMetadataDTO
    {
        $inner = $eventPayload['payload'] ?? null;
        if (!\is_array($inner)) {
            return null;
        }

        $metadata = $inner['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return null;
        }

        return $this->fromMetadataArray($metadata);
    }

    public function fromRunEvent(RunEvent $event): ?RunStartedMetadataDTO
    {
        if (RunEventTypeEnum::RunStarted->value !== $event->type) {
            return null;
        }

        return $this->fromRunEventPayload($event->payload);
    }

    /**
     * @param array<string, mixed> $metadata payload.payload.metadata
     */
    public function fromMetadataArray(array $metadata): RunStartedMetadataDTO
    {
        $sessionRaw = $metadata['session'] ?? [];
        $session = \is_array($sessionRaw) ? $this->decodeSession($sessionRaw) : new RunStartedSessionMetadataDTO();

        $toolsScope = null;
        $toolsScopeRaw = $metadata['tools_scope'] ?? null;
        if (\is_array($toolsScopeRaw)) {
            $toolsScope = $this->decodeToolsScope($toolsScopeRaw);
        }

        $extensionsKeyPresent = \array_key_exists('extensions', $metadata);
        $extensions = null;
        if ($extensionsKeyPresent) {
            $extensions = $this->decodeExtensions($metadata['extensions']);
        }

        $contextWindow = null;
        if (isset($metadata['context_window']) && is_numeric($metadata['context_window'])) {
            $resolved = (int) $metadata['context_window'];
            if ($resolved > 0) {
                $contextWindow = $resolved;
            }
        }

        return new RunStartedMetadataDTO(
            session: $session,
            model: $this->nullableNonEmptyString($metadata['model'] ?? null),
            reasoning: $this->nullableNonEmptyString($metadata['reasoning'] ?? null),
            toolsScope: $toolsScope,
            contextWindow: $contextWindow,
            extensions: $extensions,
            extensionsKeyPresent: $extensionsKeyPresent,
            provider: $this->nullableNonEmptyString($metadata['provider'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function decodeSession(array $session): RunStartedSessionMetadataDTO
    {
        $kind = \is_string($session['kind'] ?? null) ? $session['kind'] : null;
        $childKind = $this->nullableNonEmptyString($session['child_kind'] ?? null);

        $interactive = null;
        if (\array_key_exists('interactive', $session)) {
            $interactive = (bool) $session['interactive'];
        }

        return new RunStartedSessionMetadataDTO(
            kind: $kind,
            childKind: $childKind,
            parentRunId: $this->nullableNonEmptyString($session['parent_run_id'] ?? null),
            agentName: $this->nullableNonEmptyString($session['agent_name'] ?? null),
            artifactId: $this->nullableNonEmptyString($session['artifact_id'] ?? null),
            interactive: $interactive,
        );
    }

    /**
     * @param array<string, mixed> $toolsScope
     */
    private function decodeToolsScope(array $toolsScope): RunStartedToolsScopeDTO
    {
        $allowed = null;
        $rawAllowed = $toolsScope['allowed_tools'] ?? null;
        if (\is_array($rawAllowed)) {
            $allowed = [];
            foreach ($rawAllowed as $tool) {
                if (\is_string($tool)) {
                    $allowed[] = $tool;
                }
            }
        }

        $mcp = $toolsScope['mcp'] ?? [];
        if (!\is_array($mcp)) {
            $mcp = [];
        }

        return new RunStartedToolsScopeDTO(
            allowedTools: $allowed,
            mcp: $mcp,
        );
    }

    /**
     * @return list<string>
     */
    private function decodeExtensions(mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $classes = [];
        foreach ($raw as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $classes[] = trim($item);
            }
        }

        return $classes;
    }

    private function nullableNonEmptyString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }
}
