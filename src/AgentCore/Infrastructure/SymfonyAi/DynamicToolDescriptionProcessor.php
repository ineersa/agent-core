<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\Tool;

final readonly class DynamicToolDescriptionProcessor implements InputProcessorInterface
{
    public function __construct(
        private ?ToolboxInterface $toolbox = null,
        private ?ToolSetResolverInterface $toolSetResolver = null,
    ) {
    }

    public function processInput(Input $input, ?ModelInvocationInput $invocationInput = null): void
    {
        $options = $input->getOptions();

        // Resolver correlation stays in typed Hatfield input and never enters
        // the provider options array.
        if (null !== $this->toolSetResolver && null !== $invocationInput?->toolsRef) {
            if ($this->resolveToolset($options, $input, $invocationInput)) {
                return;
            }
            // Fall through to existing tool filtering logic with the resolved names.
        }

        $currentTools = $options['tools'] ?? null;

        if (\is_array($currentTools) && $this->isToolArray($currentTools)) {
            $tools = $currentTools;
        } else {
            $tools = $this->toolbox?->getTools() ?? [];
        }

        // Callers may pass tools:[] to guarantee no-tools for invocations
        // that must not use tools (e.g. summarization, compaction).
        // This branch handles both an explicit [] and an empty toolbox.
        //
        // 'tool_descriptions' is always removed when there are no tools.
        // 'tools' is only removed when the ORIGINAL value was explicitly
        // empty (before toolbox fallback).  This preserves resolver-provided
        // flat string names when the toolbox is unavailable — those names
        // are intentionally non-empty and should not be destroyed by the
        // empty-tools guard.
        //
        // Strict OpenAI-compatible providers (vLLM, Runpod proxy) reject
        // requests containing an empty tools array, so the explicit []
        // path must omit 'tools' entirely.
        if ([] === $tools) {
            unset($options['tool_descriptions']);
            if ([] === $currentTools) {
                unset($options['tools']);
            }
            $input->setOptions($options);

            return;
        }

        if (\is_array($currentTools) && $this->isFlatStringArray($currentTools)) {
            $tools = array_values(array_filter(
                $tools,
                static fn (Tool $tool): bool => \in_array($tool->getName(), $currentTools, true),
            ));
        }

        $descriptionOverrides = \is_array($options['tool_descriptions'] ?? null)
            ? $options['tool_descriptions']
            : [];

        if ([] !== $descriptionOverrides) {
            $tools = array_map(
                static fn (Tool $tool): Tool => new Tool(
                    reference: $tool->getReference(),
                    name: $tool->getName(),
                    description: \is_string($descriptionOverrides[$tool->getName()] ?? null)
                        ? $descriptionOverrides[$tool->getName()]
                        : $tool->getDescription(),
                    parameters: $tool->getParameters(),
                ),
                $tools,
            );
        }

        unset($options['tool_descriptions']);
        $options['tools'] = $tools;
        $input->setOptions($options);
    }

    /**
     * Resolve active tool names from ToolSetResolver.
     *
     * When the resolved set has tools, inject them as a flat string array
     * into options['tools'] and return false so downstream filtering runs.
     * When the set is empty, finalise options immediately (short-circuit)
     * and return true so the caller returns early, preventing fallback.
     *
     * @param array<string, mixed> $options (by reference)
     *
     * @return bool true to short-circuit (no tools available); false to continue
     */
    private function resolveToolset(array &$options, Input $input, ModelInvocationInput $invocationInput): bool
    {
        \assert(null !== $this->toolSetResolver);
        \assert(null !== $invocationInput->toolsRef);

        $activeSet = $this->toolSetResolver->resolve(
            $invocationInput->toolsRef,
            $invocationInput->turnNo,
            $invocationInput->runId,
        );

        if ([] === $activeSet->toolNames) {
            // Empty active set: clear everything and short-circuit so
            // downstream does not fall back to the full toolbox.
            unset($options['tools'], $options['tool_descriptions']);
            $input->setOptions($options);

            return true;
        }

        // Inject resolved tool names as a flat string array so existing
        // filtering logic picks them up.
        $options['tools'] = $activeSet->toolNames;

        return false;
    }

    /**
     * @param array<mixed> $tools
     */
    private function isFlatStringArray(array $tools): bool
    {
        return array_reduce(
            $tools,
            static fn (bool $carry, mixed $item): bool => $carry && \is_string($item),
            true,
        );
    }

    /**
     * @param array<mixed> $tools
     */
    private function isToolArray(array $tools): bool
    {
        return array_reduce(
            $tools,
            static fn (bool $carry, mixed $item): bool => $carry && $item instanceof Tool,
            true,
        );
    }
}
