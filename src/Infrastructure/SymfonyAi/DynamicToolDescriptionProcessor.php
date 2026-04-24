<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\Tool;

final readonly class DynamicToolDescriptionProcessor implements InputProcessorInterface
{
    public function __construct(
        private ?ToolboxInterface $toolbox = null,
    ) {
    }

    public function processInput(Input $input): void
    {
        $options = $input->getOptions();
        $currentTools = $options['tools'] ?? null;

        if (\is_array($currentTools) && $this->isToolArray($currentTools)) {
            $tools = $currentTools;
        } else {
            $tools = $this->toolbox?->getTools() ?? [];
        }

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
