<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class DynamicToolDescriptionProcessorTest extends TestCase
{
    private ToolboxInterface $toolbox;
    private DynamicToolDescriptionProcessor $processor;

    protected function setUp(): void
    {
        $this->toolbox = $this->createToolbox([
            new Tool(new ExecutionReference('read_handler'), 'read', 'Read file contents', ['type' => 'object']),
            new Tool(new ExecutionReference('write_handler'), 'write', 'Write file contents', ['type' => 'object']),
            new Tool(new ExecutionReference('bash_handler'), 'bash', 'Execute bash command', ['type' => 'object']),
        ]);
    }

    /* ───────── Resolver with tools_ref ───────── */

    public function testResolverPathFiltersToolsByActiveSet(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('toolset:run:abc:turn:1')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read'],
                allowListNames: ['read'],
            ));

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'toolset:run:abc:turn:1',
            'turn_no' => 1,
            'run_id' => 'abc',
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertArrayHasKey('tools', $options);
        $tools = $options['tools'];
        self::assertCount(1, $tools);
        self::assertSame('read', $tools[0]->getName());
        // Resolver-only options should be cleaned up
        self::assertArrayNotHasKey('tools_ref', $options);
        self::assertArrayNotHasKey('turn_no', $options);
    }

    public function testResolverPathWithEmptyActiveSetResultsInNoTools(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: [], allowListNames: []));

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'toolset:run:abc:turn:1',
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        // Empty active set removes tools option, falling through to no-tools path
        self::assertArrayNotHasKey('tools', $options);
    }

    public function testResolverPathPassesTurnNoAndRunIdToResolver(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('toolset:run:x:turn:5', 5, 'x')
            ->willReturn(new ActiveToolSet(toolNames: ['bash'], allowListNames: ['bash']));

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'toolset:run:x:turn:5',
            'turn_no' => 5,
            'run_id' => 'x',
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertCount(1, $options['tools']);
        self::assertSame('bash', $options['tools'][0]->getName());
    }

    /* ───────── Fallback behavior unchanged ───────── */

    public function testWithoutResolverUsesAllToolboxTools(): void
    {
        $processor = new DynamicToolDescriptionProcessor($this->toolbox);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), []);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertArrayHasKey('tools', $options);
        self::assertCount(3, $options['tools']);
        self::assertSame('read', $options['tools'][0]->getName());
        self::assertSame('write', $options['tools'][1]->getName());
        self::assertSame('bash', $options['tools'][2]->getName());
    }

    public function testWithoutToolsRefResolverIsNotCalled(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), []);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertCount(3, $options['tools']);
    }

    public function testFlatStringArrayFilteringStillWorksWithResolverButNoRef(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);

        // Passing tools as flat string array without tools_ref — should fall
        // through to existing filtering logic and not invoke resolver.
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools' => ['bash'],
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertCount(1, $options['tools']);
        self::assertSame('bash', $options['tools'][0]->getName());
    }

    public function testResolverWithToolsRefAndDescriptionOverrides(): void
    {
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'bash'], allowListNames: ['read', 'bash']));

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'ref',
            'tools' => ['all_tools_from_toolbox'], // overridden by resolver
            'tool_descriptions' => ['read' => 'Custom read description'],
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertCount(2, $options['tools']);
        $names = array_map(static fn (Tool $t): string => $t->getName(), $options['tools']);
        self::assertContains('read', $names);
        self::assertContains('bash', $names);

        // Description override should still apply
        foreach ($options['tools'] as $tool) {
            if ('read' === $tool->getName()) {
                self::assertSame('Custom read description', $tool->getDescription());
            }
        }
    }

    public function testResolverWithoutToolboxPreservesFlatStringNames(): void
    {
        // When resolver provides tool names but there is no Symfony Toolbox
        // to resolve them into Tool objects, the flat string array from
        // the resolver is still set in options. The processor cannot create
        // Tool objects without a toolbox, so it preserves the names.
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: ['read'], allowListNames: ['read']));

        $processor = new DynamicToolDescriptionProcessor(toolbox: null, toolSetResolver: $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'ref',
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        // Without a toolbox, the resolver's flat names are preserved
        self::assertArrayHasKey('tools', $options);
        self::assertSame(['read'], $options['tools']);
    }

    public function testResolverPathUpdatesToolsOptionPreservingExistingFiltering(): void
    {
        // When resolver sets tool names and toolbox has matching tools,
        // the existing filtering path still runs and produces Tool[] results.
        $resolver = $this->createMock(ToolSetResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: ['write', 'bash'], allowListNames: ['write', 'bash']));

        $processor = new DynamicToolDescriptionProcessor($this->toolbox, $resolver);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools_ref' => 'ref',
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertCount(2, $options['tools']);
        self::assertSame('write', $options['tools'][0]->getName());
        self::assertSame('bash', $options['tools'][1]->getName());
    }

    /* ───────── Empty-tools / no-tools path ───────── */

    /**
     * When callers pass tools:[] for invocations that must not use tools
     * (e.g. summarization/compaction), the processor must remove BOTH
     * 'tools' and 'tool_descriptions' from options. Strict OpenAI-compatible
     * providers (e.g. vLLM, Runpod proxy) reject requests with an empty
     * tools array.
     */
    public function testEmptyToolsArrayRemovesBothToolsAndToolDescriptions(): void
    {
        $processor = new DynamicToolDescriptionProcessor($this->toolbox);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools' => [],
            'tool_descriptions' => ['read' => 'should be removed'],
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertArrayNotHasKey('tools', $options, 'Empty tools array MUST be removed so strict providers do not reject the request');
        self::assertArrayNotHasKey('tool_descriptions', $options, 'Tool descriptions MUST be removed when tools are empty');
    }

    /**
     * When no toolbox is available and a resolver short-circuits with an
     * empty active set, the processor also removes 'tools' and
     * 'tool_descriptions' from options.
     */
    public function testEmptyToolsWithoutToolboxRemovesToolsOption(): void
    {
        $processor = new DynamicToolDescriptionProcessor(toolbox: null);
        $input = new Input('test-model', new \Symfony\AI\Platform\Message\MessageBag(), [
            'tools' => [],
        ]);

        $processor->processInput($input);

        $options = $input->getOptions();
        self::assertArrayNotHasKey('tools', $options);
    }

    /* ───────── Helpers ───────── */

    /**
     * @param list<Tool> $tools
     */
    private function createToolbox(array $tools): ToolboxInterface
    {
        return new class($tools) implements ToolboxInterface {
            /** @param list<Tool> $tools */
            public function __construct(
                private readonly array $tools,
            ) {
            }

            public function getTools(): array
            {
                return $this->tools;
            }

            public function execute(\Symfony\AI\Platform\Result\ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
            {
                throw new \RuntimeException('Not implemented in tests');
            }
        };
    }
}
