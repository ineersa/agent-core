<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
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

    public function processInput(Input $input): void
    {
        $options = $input->getOptions();

        // Resolve active toolset via ToolSetResolver when a tools_ref is present.
        // If the resolver short-circuits (empty active set), finalise options and return.
        if (null !== $this->toolSetResolver && isset($options['tools_ref']) && \is_string($options['tools_ref'])) {
            if ($this->resolveToolset($options, $input)) {
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
        // that must not use tools (e.g. summarization).  The empty
        // array satisfies isToolArray([]) === true (array_reduce returns
        // the initial true), so this branch handles both an explicit []
        // and an empty toolbox, short-circuiting before any tool description
        // or fallback logic runs.
        if ([] === $tools) {
            unset($options['tool_descriptions']);
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
     * Always cleans up resolver-specific options to prevent leaking.
     *
     * @param array<string, mixed> $options (by reference)
     *
     * @return bool true to short-circuit (no tools available); false to continue
     */
    private function resolveToolset(array &$options, Input $input): bool
    {
        \assert(null !== $this->toolSetResolver);

        $toolsRef = $options['tools_ref'];
        $turnNo = isset($options['turn_no']) && \is_int($options['turn_no']) ? $options['turn_no'] : null;
        $runId = isset($options['run_id']) && \is_string($options['run_id']) ? $options['run_id'] : null;

        $activeSet = $this->toolSetResolver->resolve($toolsRef, $turnNo, $runId);

        // Clean up resolver-only options so they don't leak to the platform.
        unset($options['tools_ref'], $options['turn_no'], $options['run_id']);

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
