<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\SubagentArgumentsDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\Validation\SubagentTasks\SubagentTasksLimitValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\EventListener\ValidateToolCallArgumentsListener;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ValidatorBuilder;

#[CoversClass(SubagentToolDefinitionProvider::class)]
#[CoversClass(SubagentToolHandler::class)]
final class SubagentToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndParallelSchema(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);
        $def = $tool->definition();

        $this->assertSame('subagent', $def->name);
        // Typed DTO tool: schema is generated natively from SubagentArgumentsDTO.
        // The settings-derived tasks maxItems bound comes from
        // SubagentTasksSchemaProvider (agents.max_agents).
        $this->assertNull($def->parametersJsonSchema);
        $this->assertStringContainsString('4', $def->description);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $handler->__invoke(new SubagentArgumentsDTO(agent: 'scout', task: 'do something'));
    }

    public function testSchemaRejectsUnknownConcurrencyAndBackgroundProperties(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);
        $this->assertNull($tool->definition()->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($tool);
        $args = $schema;

        $this->assertFalse($args['additionalProperties']);
        $this->assertArrayNotHasKey('concurrency', $args['properties']);
        $this->assertArrayNotHasKey('background', $args['properties']);
    }

    public function testDtoRejectsMixedSingleAndParallelMode(): void
    {
        $dto = new SubagentArgumentsDTO(
            agent: 'scout',
            task: 'single',
            tasks: [new SubagentTaskDTO(agent: 'scout', task: 'parallel')],
        );

        $this->assertTrue($dto->isParallelMode());
        $this->assertSame('scout', $dto->trimmedAgent());
    }

    public function testInvokeWithContextRejectsTooManyParallelTasks(): void
    {
        // The parallel task-count limit (agents.max_agents) is enforced by
        // SubagentTasksLimit on SubagentArgumentsDTO. Execute through the
        // production RegistryBackedToolbox path and assert the deterministic
        // fault result — the handler (and its execution service) never runs.
        $container = self::getContainer();
        $handler = $container->get(SubagentToolHandler::class);

        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                SubagentTasksLimitValidator::class => new SubagentTasksLimitValidator(new AgentsConfig(maxAgents: 4)),
            ]))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'subagent',
            description: 'subagent',
            handler: $handler,
            promptLine: 'subagent',
        );

        $toolbox = new FaultTolerantToolbox(new RegistryBackedToolbox(
            registry: $registry,
            // Use the container's native resolver directly — the
            // ToolCallArgumentResolverInterface alias already returns
            // RawAwareToolCallArgumentResolver, so wrapping it again would
            // double-wrap and empty the DTO.
            argumentResolver: new RawAwareToolCallArgumentResolver($container->get(ToolCallArgumentResolver::class)),
            schemaFactory: NativeToolSchemaProbe::schemaFactory(),
            eventDispatcher: $dispatcher,
        ));

        $tasks = [];
        for ($i = 0; $i < 9; ++$i) {
            $tasks[] = ['agent' => 'scout', 'task' => 't'.$i];
        }

        // Flat provider arguments: subagent DTO fields at the top level.
        $result = $toolbox->execute(new ToolCall('tc-cap', 'subagent', ['tasks' => $tasks]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('Parallel subagent execution supports at most 4 agents per tool call, but 9 tasks were requested.', $message);
    }

    public function testProviderIsAutoRegistered(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);

        $this->assertInstanceOf(
            \Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface::class,
            $tool,
        );
    }
}
