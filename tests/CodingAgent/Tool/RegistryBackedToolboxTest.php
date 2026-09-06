<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionToolHookEventSubscriber;
use Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\Validation\ViewImage\ViewImageTargetValidator;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\EventListener\ValidateToolCallArgumentsListener;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Tests for RegistryBackedToolbox.
 *
 * RegistryBackedToolbox is a thin registry/rewrite decorator that delegates
 * execution to the native Symfony AI Toolbox. DTO-typed tools expose flat
 * provider arguments (the DTO's object schema at the Tool root, wrapped
 * internally under the reflected parameter name before native resolution);
 * raw-array tools (MCP, extension adapters) receive the flat provider map
 * through the raw-arguments resolver.
 */
final class RegistryBackedToolboxTest extends TestCase
{
    /* ───────── ToolboxInterface contract ───────── */

    public function testImplementsToolboxInterface(): void
    {
        $registry = new ToolRegistry();
        $toolbox = $this->createToolbox($registry);

        $this->assertInstanceOf(ToolboxInterface::class, $toolbox);
    }

    /* ───────── getTools() ───────── */

    public function testGetToolsReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry();
        $toolbox = $this->createToolbox($registry);

        $this->assertSame([], $toolbox->getTools());
    }

    public function testGetToolsConvertsRawToolToSymfonyTool(): void
    {
        $handler = $this->dummyHandler('permanent result');
        $registry = new ToolRegistry();
        $schema = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];

        $registry->registerTool(
            name: 'read',
            description: 'Read file contents',
            parametersJsonSchema: $schema,
            handler: $handler,
            promptLine: 'read: Read files',
            promptGuidelines: ['Use read for files'],
        );

        $toolbox = $this->createToolbox($registry);
        $tools = $toolbox->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame('read', $tools[0]->getName());
        $this->assertSame('Read file contents', $tools[0]->getDescription());
        $this->assertSame($schema, $tools[0]->getParameters());
        $this->assertSame($handler::class, $tools[0]->getReference()->getClass());
        $this->assertSame('__invoke', $tools[0]->getReference()->getMethod());
        $this->assertTrue($tools[0]->getMetadataValue('raw_arguments', false));
    }

    public function testGetToolsPreservesEmptyJsonSchemaObjectShapes(): void
    {
        $registry = new ToolRegistry();
        $registry->addDynamicTool(
            name: 'get_widget',
            description: 'Get a dashboard widget',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'widget_definition' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],
            handler: $this->dummyHandler('widget'),
        );

        $parameters = $this->createToolbox($registry)->getTools()[0]->getParameters();
        $encoded = json_encode($parameters, \JSON_THROW_ON_ERROR);

        $this->assertSame(
            '{"type":"object","properties":{"widget_definition":{"type":"object","properties":{}}}}',
            $encoded,
        );
    }

    public function testGetToolsNormalizesMissingRawSchemaToEmptyObjectSchema(): void
    {
        $registry = new ToolRegistry();
        $registry->addDynamicTool(
            name: 'no_arguments',
            description: 'Run without arguments',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('done'),
        );

        $parameters = $this->createToolbox($registry)->getTools()[0]->getParameters();

        $this->assertSame('{"type":"object","properties":{}}', json_encode($parameters, \JSON_THROW_ON_ERROR));
    }

    public function testGetToolsForDtoToolUsesNativeGeneratedSchema(): void
    {
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'ok';
            }
        };
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'view_image',
            description: 'View an image',
            handler: $handler,
            promptLine: 'view_image: View',
        );

        $toolbox = $this->createToolbox($registry);
        $tools = $toolbox->getTools();

        $this->assertCount(1, $tools);
        $tool = $tools[0];
        $this->assertSame('view_image', $tool->getName());
        $this->assertSame('View an image', $tool->getDescription());
        $this->assertFalse($tool->getMetadataValue('raw_arguments', false));

        // Flat provider schema: the DTO's object schema at the Tool root — no
        // parameter envelope, so the model passes DTO fields directly.
        $parameters = $tool->getParameters();
        $this->assertSame('object', $parameters['type']);
        $this->assertArrayNotHasKey('arguments', $parameters['properties']);
        $this->assertArrayHasKey('path', $parameters['properties']);
        $this->assertSame('string', $parameters['properties']['path']['type']);
        $this->assertSame(
            'Path to the image file (absolute, or relative to the working directory)',
            $parameters['properties']['path']['description'],
        );
        // path is required; nullable-with-default props are not.
        $this->assertSame(['path'], $parameters['required']);
        $this->assertFalse($parameters['additionalProperties']);
    }

    public function testGetToolsIncludesDynamicAfterPermanent(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(
            name: 'perm',
            description: 'Permanent',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('perm'),
            promptLine: 'perm',
        );
        $registry->addDynamicTool(
            name: 'dyn',
            description: 'Dynamic',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('dyn'),
        );

        $toolbox = $this->createToolbox($registry);
        $tools = $toolbox->getTools();

        $this->assertCount(2, $tools);
        $this->assertSame('perm', $tools[0]->getName());
        $this->assertSame('dyn', $tools[1]->getName());
    }

    public function testGetToolsPreservesOrder(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler('a'), promptLine: 'a');
        $registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler('b'), promptLine: 'b');
        $registry->registerTool(name: 'c', description: 'C', parametersJsonSchema: [], handler: $this->dummyHandler('c'), promptLine: 'c');

        $toolbox = $this->createToolbox($registry);
        $names = array_map(static fn ($t) => $t->getName(), $toolbox->getTools());

        $this->assertSame(['a', 'b', 'c'], $names);
    }

    /* ───────── execute(): raw-array tools ───────── */

    public function testExecuteCallsRawHandlerWithFlatArguments(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();

        $registry->registerTool(
            name: 'search',
            description: 'Search',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'search: Search',
        );

        $toolbox = $this->createToolbox($registry);
        $toolCall = new ToolCall('call-1', 'search', ['query' => 'hello']);

        $result = $toolbox->execute($toolCall);

        $this->assertSame('ok', $result->getResult());
        $this->assertSame(['query' => 'hello'], $handler->lastArgs);
    }

    public function testExecuteForDynamicTool(): void
    {
        $registry = new ToolRegistry();

        $registry->addDynamicTool(
            name: 'fg_tool',
            description: 'Fg tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('dynamic result'),
        );

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-2', 'fg_tool', []));

        $this->assertSame('dynamic result', $result->getResult());
    }

    public function testExecuteThrowsToolNotFoundException(): void
    {
        $registry = new ToolRegistry();
        $toolbox = $this->createToolbox($registry);

        $this->expectException(ToolNotFoundException::class);
        $toolbox->execute(new ToolCall('call-4', 'nonexistent', []));
    }

    /* ───────── execute(): typed DTO tools through native resolution ───────── */

    public function testExecuteResolvesDtoToolThroughNativeResolver(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public ?ViewImageArgumentsDTO $seen = null;

            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                $this->seen = $arguments;

                return 'ok:'.$arguments->path;
            }
        };
        $registry->registerTool(
            name: 'view_image',
            description: 'View',
            handler: $handler,
            promptLine: 'view_image',
        );

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-dto', 'view_image', ['path' => 'img.png']));

        $this->assertSame('ok:img.png', $result->getResult());
        $this->assertInstanceOf(ViewImageArgumentsDTO::class, $handler->seen);
        $this->assertSame('img.png', $handler->seen->path);
    }

    public function testSnakeCaseProviderKeysDenormalizeToSnakeCaseDtoProperties(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public ?AgentRetrieveArgumentsDTO $seen = null;

            public function __invoke(AgentRetrieveArgumentsDTO $arguments): string
            {
                $this->seen = $arguments;

                return 'ok:'.$arguments->artifact_id;
            }
        };
        $registry->registerTool(
            name: 'agent_retrieve',
            description: 'Retrieve',
            handler: $handler,
            promptLine: 'agent_retrieve',
        );

        // Real resolver path with the app serializer stack (camel_case_to_snake_case
        // name converter) — no hand-written key mapping anywhere. Provider args
        // are flat; the resolver wraps them under the reflected parameter name.
        $toolbox = $this->createToolbox($registry, resolver: $this->createNameConverterResolver());
        $result = $toolbox->execute(new ToolCall('call-snake', 'agent_retrieve', ['artifact_id' => 'agent_abc', 'limit' => 5]));

        $this->assertSame('ok:agent_abc', $result->getResult());
        $this->assertInstanceOf(AgentRetrieveArgumentsDTO::class, $handler->seen);
        $this->assertSame('agent_abc', $handler->seen->artifact_id);
        $this->assertNull($handler->seen->agent_run_id);
        $this->assertSame(5, $handler->seen->limit);
    }

    public function testUnknownArgumentsAreIgnoredByNativeResolver(): void
    {
        // Native Symfony AI behavior: unknown keys are not resolved onto the DTO.
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'ok:'.$arguments->path;
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-unknown', 'view_image', ['path' => 'a.png', 'extra' => true]));

        $this->assertSame('ok:a.png', $result->getResult());
    }

    /* ───────── Lifecycle events ───────── */

    public function testExecuteDispatchesNativeLifecycleEventsWithNativeArgumentShapes(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('evented result');
        $registry->registerTool(name: 'evented', description: 'Evented', parametersJsonSchema: [], handler: $handler, promptLine: 'evented');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$events): void {
            $events[] = ['requested', $event->getToolCall()->getName(), $event->getDefinition()->getName()];
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $event) use (&$events, $handler): void {
            $events[] = ['arguments_resolved', $event->getTool() === $handler, $event->getArguments()];
        });
        $dispatcher->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use (&$events, $handler): void {
            $events[] = ['succeeded', $event->getTool() === $handler, $event->getArguments(), $event->getResult()->getResult()];
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-events', 'evented', ['query' => 'hello']));

        $this->assertSame('evented result', $result->getResult());
        $this->assertSame([
            ['requested', 'evented', 'evented'],
            // Native resolution nests flat provider args under the sole handler parameter name.
            ['arguments_resolved', true, ['arguments' => ['query' => 'hello']]],
            // Succeeded carries the native resolved argument shape.
            ['succeeded', true, ['arguments' => ['query' => 'hello']], 'evented result'],
        ], $events);
    }

    public function testTypedToolRequestedSeesFlatArgsAndResolvedCarriesDto(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'ok';
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$events): void {
            $events[] = ['requested', $event->getToolCall()->getArguments()];
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $event) use (&$events): void {
            $events[] = ['resolved', $event->getArguments()];
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $toolbox->execute(new ToolCall('call-typed', 'view_image', ['path' => 'img.png']));

        // ToolCallRequested sees the flat provider args before resolution.
        $this->assertSame(['requested', ['path' => 'img.png']], $events[0]);
        // ToolCallArgumentsResolved carries the resolved DTO parameter map.
        $this->assertSame('resolved', $events[1][0]);
        $this->assertArrayHasKey('arguments', $events[1][1]);
        $this->assertInstanceOf(ViewImageArgumentsDTO::class, $events[1][1]['arguments']);
        $this->assertSame('img.png', $events[1][1]['arguments']->path);
    }

    public function testToolCallRequestedCanDenyAndSkipHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(name: 'guarded', description: 'Guarded', parametersJsonSchema: [], handler: $handler, promptLine: 'guarded');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$events): void {
            $events[] = 'requested';
            $event->deny('blocked by listener');
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function () use (&$events): void {
            $events[] = 'arguments_resolved';
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-denied', 'guarded', []));

        $this->assertSame('blocked by listener', $result->getResult());
        $this->assertSame(0, $handler->calls);
        $this->assertSame(['requested'], $events);
    }

    public function testToolCallRequestedCanReplaceResultAndSkipHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(name: 'replaceable', description: 'Replaceable', parametersJsonSchema: [], handler: $handler, promptLine: 'replaceable');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event): void {
            $event->setResult(new ToolResult($event->getToolCall(), ['replaced' => true]));
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-replaced', 'replaceable', []));

        $this->assertSame(['replaced' => true], $result->getResult());
        $this->assertSame(0, $handler->calls);
    }

    public function testHandlerRuntimeExceptionDispatchesFailedEventAndIsWrapped(): void
    {
        $registry = new ToolRegistry();
        $exception = new \RuntimeException('boom');
        $handler = new class($exception) {
            public function __construct(
                private readonly \RuntimeException $exception,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                throw $this->exception;
            }
        };
        $registry->registerTool(name: 'failing', description: 'Failing', parametersJsonSchema: [], handler: $handler, promptLine: 'failing');

        $dispatcher = new EventDispatcher();
        $failedEvent = null;
        $dispatcher->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));
        $result = $toolbox->execute(new ToolCall('call-failed', 'failing', ['path' => 'x']));

        // Native Toolbox wraps non-ToolExecutionExceptionInterface throwables.
        $this->assertSame('An error occurred while executing tool "failing".', (string) $result->getResult());
        $this->assertInstanceOf(ToolCallFailed::class, $failedEvent);
        $this->assertSame($handler, $failedEvent->getTool());
        $this->assertSame('failing', $failedEvent->getDefinition()->getName());
        $this->assertSame($exception, $failedEvent->getException());
    }

    public function testHandlerToolCallExceptionSurvivesFaultTolerantToolbox(): void
    {
        $registry = new ToolRegistry();
        $exception = new ToolCallException('Something went wrong', retryable: true, hint: 'Try again with different input');
        $handler = new class($exception) {
            public function __construct(
                private readonly ToolCallException $exception,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                throw $this->exception;
            }
        };
        $registry->registerTool(name: 'failing', description: 'Failing', parametersJsonSchema: [], handler: $handler, promptLine: 'failing');

        $dispatcher = new EventDispatcher();
        $failedEvent = null;
        $dispatcher->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));

        try {
            $toolbox->execute(new ToolCall('call-failing', 'failing', ['path' => 'x']));
            $this->fail('Expected the ToolCallException to propagate unchanged.');
        } catch (ToolCallException $caught) {
            // FaultTolerantToolbox only converts ToolExecutionExceptionInterface, so
            // ToolCallException must reach ToolExecutor with full fidelity.
            $this->assertSame($exception, $caught);
            $this->assertSame('Something went wrong', $caught->getMessage());
            $this->assertTrue($caught->retryable());
            $this->assertSame('Try again with different input', $caught->hint());
        }

        $this->assertSame($exception, $failedEvent?->getException());
    }

    /* ───────── Fault tolerance for invalid arguments ───────── */

    public function testMissingMandatoryArgumentsBecomeFaultTolerantResultWithViolations(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'nope';
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        // Production wires ValidateToolCallArgumentsListener on the app dispatcher
        // (config/services.yaml) with the container validator; mirror both here.
        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                ViewImageTargetValidator::class => new ViewImageTargetValidator(
                    new ImageToolConfig(),
                    new StackToolExecutionContextAccessor(),
                ),
            ]))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        // Flat provider call with the mandatory DTO property missing: the flat
        // map is wrapped under the reflected parameter name, the native resolver
        // denormalizes the empty DTO, and the validator listener turns the
        // NotBlank violation into a deterministic fault-tolerant result.
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));
        $result = $toolbox->execute(new ToolCall('call-missing', 'view_image', []));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "path" argument is required and must be a non-empty string.', $message);
    }

    public function testDtoConstraintViolationBecomesFaultTolerantResultWithViolations(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'nope';
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        // Production wires ValidateToolCallArgumentsListener on the app dispatcher
        // (config/services.yaml) with the container validator (service-aware
        // constraint validator factory); mirror both here so the class-level
        // ViewImageTarget constraint resolves its autowired validator.
        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                ViewImageTargetValidator::class => new ViewImageTargetValidator(
                    new ImageToolConfig(),
                    new StackToolExecutionContextAccessor(),
                ),
            ]))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));
        $result = $toolbox->execute(new ToolCall('call-blank', 'view_image', ['path' => ' ']));

        // ValidateToolCallArgumentsListener rejects the DTO; the violations
        // are stringified into the model-visible result.
        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "path" argument is required and must be a non-empty string.', $message);
    }

    public function testDenormalizerFailureBecomesActionableToolCallException(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'fragile',
            description: 'Fragile',
            handler: new class {
                public function __invoke(FragileCountArgumentsDTO $arguments): string
                {
                    return 'count:'.$arguments->count;
                }
            },
            promptLine: 'fragile',
        );

        // Resolver/denormalizer failure before handler invoke: the wrapped
        // NotNormalizableValueException is translated into a non-retryable
        // ToolCallException with the actionable serializer message.
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));

        try {
            $toolbox->execute(new ToolCall('call-fragile', 'fragile', ['count' => 'abc']));
            $this->fail('Expected ToolCallException with the denormalization message.');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('The type of the "count" attribute for class "Ineersa\CodingAgent\Tests\Tool\FragileCountArgumentsDTO" must be one of "int" ("string" given).', $e->getMessage());
            $this->assertFalse($e->retryable());
            $this->assertInstanceOf(NotNormalizableValueException::class, $e->getPrevious());
        }
    }

    /* ───────── Visibility filtering (excluded/allowlist) ───────── */

    public function testExecuteThrowsToolNotFoundExceptionForExcludedTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');

        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash: Shell');
        $registry->setExcludedToolNames(['bash']);

        // Excluded tool must remain invisible to active listings
        $this->assertSame([], $registry->activeToolNames());

        $toolbox = $this->createToolbox($registry);

        try {
            $toolbox->execute(new ToolCall('call-excluded', 'bash', []));
            $this->fail('Expected ToolNotFoundException.');
        } catch (ToolNotFoundException) {
            // Handler must NOT be invoked
            $this->assertSame(0, $handler->calls);
        }
    }

    public function testExecuteThrowsToolNotFoundExceptionForAllowlistFilteredTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');

        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash: Shell');
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('ok'), promptLine: 'read: Read');

        // Only 'read' is allowed
        $registry->setAllowedToolNames(['read']);

        $this->assertSame(['read'], $registry->activeToolNames());

        $toolbox = $this->createToolbox($registry);

        // 'bash' is registered but not in the allowlist
        try {
            $toolbox->execute(new ToolCall('call-allowlisted', 'bash', []));
            $this->fail('Expected ToolNotFoundException.');
        } catch (ToolNotFoundException) {
            // Handler must NOT be invoked
            $this->assertSame(0, $handler->calls);
        }
    }

    public function testExecuteStillWorksForAllowedToolInAllowlist(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('allowlisted result');

        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $handler, promptLine: 'read: Read');
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler('should not be called'), promptLine: 'bash: Shell');

        $registry->setAllowedToolNames(['read']);

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-allowed', 'read', []));

        $this->assertSame('allowlisted result', $result->getResult());
    }

    /* ───────── Rewrite phase ───────── */

    public function testRewriteHookMutatesArgumentsBeforeNativeExecution(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['command'] = 'LLM_MODE=true '.$args['command'];

                    return $args;
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-1', 'bash', ['command' => 'castor test']));

        $this->assertSame(['command' => 'LLM_MODE=true castor test'], $handler->lastArgs);
    }

    public function testRewriteHookNullReturnLeavesArgsUnchanged(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return null; // no-op
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-2', 'bash', ['command' => 'castor test']));

        $this->assertSame(['command' => 'castor test'], $handler->lastArgs);
    }

    public function testRewriteHookEventSeesRewrittenArgs(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['command'] = 'LLM_MODE=true '.$args['command'];

                    return $args;
                }
            },
        ]);

        $dispatcher = new EventDispatcher();
        $requestedArgs = null;
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$requestedArgs): void {
            $requestedArgs = $event->getToolCall()->getArguments();
        });

        $toolbox = $this->createToolbox($registry, $dispatcher, $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-3', 'bash', ['command' => 'castor test']));

        // The native ToolCallRequested event must see the rewritten arguments.
        $this->assertSame(['command' => 'LLM_MODE=true castor test'], $requestedArgs);
    }

    public function testMultipleRewriteHooksComposeLeftToRight(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['prefix'] = ($args['prefix'] ?? '').'first|';

                    return $args;
                }
            },
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['prefix'] = ($args['prefix'] ?? '').'second';

                    return $args;
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-4', 'bash', ['command' => 'test']));

        $this->assertSame(
            ['command' => 'test', 'prefix' => 'first|second'],
            $handler->lastArgs,
        );
    }

    public function testWildcardRewriteHookAppliesToAllTools(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $wildcardHook = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                $args = $context->arguments;
                $args['injected'] = true;

                return $args;
            }
        };

        $rewriteProvider = new ExtensionHookRegistry();
        $rewriteProvider->addToolCallRewriteHook('*', $wildcardHook);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-5', 'bash', ['command' => 'test']));

        $this->assertSame(
            ['command' => 'test', 'injected' => true],
            $handler->lastArgs,
        );
    }

    public function testRewriteDoesNotRunForUnregisteredTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $handler, promptLine: 'read');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['rewritten'] = true;

                    return $args;
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-6', 'read', ['path' => 'x']));

        // Rewrite was registered for 'bash', not 'read' — args unchanged
        $this->assertSame(['path' => 'x'], $handler->lastArgs);
    }

    public function testRewriteWithoutRewriteProvider(): void
    {
        // Ensure backward compat: null provider doesn't break execution
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'tool', description: 'Tool', parametersJsonSchema: [], handler: $handler, promptLine: 'tool');

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: null);
        $toolbox->execute(new ToolCall('call-rw-7', 'tool', ['arg' => 'val']));

        $this->assertSame(['arg' => 'val'], $handler->lastArgs);
    }

    public function testRewriteOfDtoToolRewritesFlatArgumentsBeforeResolution(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public ?string $seen = null;

            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                $this->seen = $arguments->path;

                return 'ok';
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        $rewriteProvider = $this->stubRewriteProvider('view_image', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return ['path' => 'rewritten.png'];
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $result = $toolbox->execute(new ToolCall('call-rw-dto', 'view_image', ['path' => 'original.png']));

        $this->assertSame('ok', $result->getResult());
        // The rewritten flat args were wrapped and resolved into the DTO before invoke.
        $this->assertSame('rewritten.png', $handler->seen);
    }

    public function testTypedFailureDeliversRewrittenFlatArgsAndCallIdToExtensionResultHook(): void
    {
        // Behavior-level proof of the failure path: a typed DTO tool fails
        // after a rewrite hook; the extension result hook must see the exact
        // rewritten flat provider arguments and the original tool call id
        // (previously recovered via cross-event WeakMap state keyed by Tool
        // definition; now carried by the app-owned ToolCallFailedEvent).
        $seen = null;
        $resultHook = new class($seen) implements ToolResultHookInterface {
            public function __construct(private mixed &$seen)
            {
            }

            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                $this->seen = $context;

                return ToolResultDecisionDTO::keep();
            }
        };

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolResultHook($resultHook);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, '/tmp'));

        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'view_image',
            description: 'View',
            handler: new class {
                public function __invoke(ViewImageArgumentsDTO $arguments): string
                {
                    throw new ToolCallException('rejected at runtime');
                }
            },
            promptLine: 'view_image',
        );

        $rewriteProvider = $this->stubRewriteProvider('view_image', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return ['path' => 'rewritten.png'];
                }
            },
        ]);

        $toolbox = $this->createToolbox($registry, $dispatcher, $rewriteProvider);

        $this->expectException(ToolCallException::class);
        $toolbox->execute(new ToolCall('call-fail-hook', 'view_image', ['path' => 'original.png']));

        $this->assertNotNull($seen);
        $this->assertSame('call-fail-hook', $seen->toolCallId);
        $this->assertSame('view_image', $seen->toolName);
        $this->assertTrue($seen->isError);
        $this->assertSame(['path' => 'rewritten.png'], $seen->arguments);
        $this->assertSame(ToolCallException::class, $seen->details['error_type']);
        $this->assertSame('rejected at runtime', $seen->details['message']);
    }

    public function testNestedLegacyEnvelopeInputIsRejectedWithoutCompatibilityShim(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(ViewImageArgumentsDTO $arguments): string
            {
                return 'nope';
            }
        };
        $registry->registerTool(name: 'view_image', description: 'View', handler: $handler, promptLine: 'view_image');

        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                ViewImageTargetValidator::class => new ViewImageTargetValidator(
                    new ImageToolConfig(),
                    new StackToolExecutionContextAccessor(),
                ),
            ]))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        // Legacy {arguments: {...}} payloads are treated as ordinary flat input:
        // the unknown `arguments` key is ignored by native denormalization, the
        // DTO stays empty, and validation rejects it — no compatibility shim.
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));
        $result = $toolbox->execute(new ToolCall('call-legacy', 'view_image', ['arguments' => ['path' => 'img.png']]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "path" argument is required and must be a non-empty string.', $message);
    }

    /* ───────── Extension-registered tools are the same path ───────── */

    public function testExecuteForExtensionRegisteredTool(): void
    {
        // Extension tools are registered through ExtensionToolRegistryBridge which
        // calls registerTool() on the same ToolRegistry. Verify the registry
        // path works with a direct registerTool() equivalent.
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('extension result');

        $registry->registerTool(
            name: 'ext_tool',
            description: 'Extension tool',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'ext_tool: Extension tool',
        );

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-5', 'ext_tool', ['foo' => 'bar']));

        $this->assertSame('extension result', $result->getResult());
    }

    /* ───────── Private helpers ───────── */

    /* ── Snapshot caching: registry revision → native Toolbox/Tool reuse ── */

    public function testGetToolsReturnsSameToolInstancesAcrossCalls(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler('x'));
        $toolbox = $this->createToolbox($registry);

        $first = $toolbox->getTools();
        $second = $toolbox->getTools();

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        $this->assertSame($first[0], $second[0]);
        $this->assertSame($first[1], $second[1]);
    }

    public function testGetToolsStaysStableAcrossNoOpRegistryMutations(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('x');
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $handler);
        $registry->setAllowedToolNames(['read', 'mcp_x']);
        $toolbox = $this->createToolbox($registry);

        $before = $toolbox->getTools();

        // No-op mutations must not invalidate the snapshot: identical dynamic
        // re-add, unknown removal, and re-applied visibility filters.
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $handler);
        $registry->removeDynamicTool('unknown');
        $registry->setAllowedToolNames(['mcp_x', 'read']);

        $this->assertSame($before, $toolbox->getTools());
    }

    public function testGetToolsRefreshesAfterRegistryMutation(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $toolbox = $this->createToolbox($registry);

        $before = $toolbox->getTools();
        $this->assertCount(1, $before);

        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler('x'));
        $after = $toolbox->getTools();

        $this->assertCount(2, $after);
        // Metadata is memoized per definition: the unchanged permanent
        // definition keeps its metadata object; only the new dynamic
        // definition contributes a new entry.
        $this->assertSame($before[0], $after[0], 'Unchanged definitions must keep their cached metadata');
        $this->assertSame('mcp_x', $after[1]->getName());
    }

    public function testExecuteUsesReplacementDefinitionAfterDynamicReplace(): void
    {
        $registry = new ToolRegistry();
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler('old'));
        $toolbox = $this->createToolbox($registry);

        // Warm the per-definition toolbox, then replace the dynamic tool
        // with a new handler (new definition identity).
        $this->assertSame('old', $toolbox->execute(new ToolCall('c1', 'mcp_x', []))->getResult());
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler('new'));

        $this->assertSame('new', $toolbox->execute(new ToolCall('c2', 'mcp_x', []))->getResult());
    }

    public function testConsecutiveExecutesDispatchSameToolMetadataObjects(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');

        $dispatcher = new EventDispatcher();
        $metadata = [];
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$metadata): void {
            $metadata[] = $event->getDefinition();
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $toolbox->execute(new ToolCall('call-1', 'read', []));
        $toolbox->execute(new ToolCall('call-2', 'read', []));

        $this->assertCount(2, $metadata);
        $this->assertSame($metadata[0], $metadata[1], 'Both executes must share the cached native Toolbox metadata');
    }

    public function testExecuteReflectsDynamicToolAdditionWithoutStaleCache(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $toolbox = $this->createToolbox($registry);

        // Warm the snapshot, then mutate the registry.
        $toolbox->getTools();
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler('x'));

        $names = array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools());
        $this->assertSame(['read', 'mcp_x'], $names);

        $result = $toolbox->execute(new ToolCall('call-x', 'mcp_x', []));
        $this->assertSame('x', $result->getResult());
    }

    public function testExecuteReflectsDynamicToolRemovalWithoutStaleCache(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('x');
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: ['type' => 'object'], handler: $handler);
        $toolbox = $this->createToolbox($registry);

        $toolbox->execute(new ToolCall('call-x', 'mcp_x', []));
        $registry->removeDynamicTool('mcp_x');

        $this->assertCount(1, $toolbox->getTools());
        $this->expectException(ToolNotFoundException::class);
        $toolbox->execute(new ToolCall('call-x', 'mcp_x', []));
    }

    public function testGetToolsReflectsVisibilityChange(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler('r'), promptLine: 'read');
        $toolbox = $this->createToolbox($registry);

        $this->assertCount(1, $toolbox->getTools());

        $registry->setExcludedToolNames(['read']);
        $this->assertSame([], $toolbox->getTools());

        $registry->setExcludedToolNames([]);
        $this->assertCount(1, $toolbox->getTools());
    }

    public function testSameHandlerObjectRegisteredUnderTwoNames(): void
    {
        $registry = new ToolRegistry();
        $handler = new class {
            public function __invoke(array $arguments): string
            {
                return 'shared-handler:'.($arguments['which'] ?? '?');
            }
        };
        $registry->registerTool(name: 'shared_a', description: 'A', parametersJsonSchema: [], handler: $handler, promptLine: 'a');
        $registry->registerTool(name: 'shared_b', description: 'B', parametersJsonSchema: [], handler: $handler, promptLine: 'b');
        $toolbox = $this->createToolbox($registry);

        $tools = $toolbox->getTools();
        $this->assertCount(2, $tools);
        $this->assertSame(['shared_a', 'shared_b'], array_map(static fn (Tool $tool): string => $tool->getName(), $tools));

        $this->assertSame('shared-handler:a', $toolbox->execute(new ToolCall('c1', 'shared_a', ['which' => 'a']))->getResult());
        $this->assertSame('shared-handler:b', $toolbox->execute(new ToolCall('c2', 'shared_b', ['which' => 'b']))->getResult());
    }

    public function testTwoInstancesOfSameHandlerClassGetDistinctMetadata(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'inst_a', description: 'A', parametersJsonSchema: [], handler: new RegistryBackedToolboxSharedClassHandler('a'), promptLine: 'a');
        $registry->registerTool(name: 'inst_b', description: 'B', parametersJsonSchema: [], handler: new RegistryBackedToolboxSharedClassHandler('b'), promptLine: 'b');
        $toolbox = $this->createToolbox($registry);

        $tools = $toolbox->getTools();
        $this->assertCount(2, $tools);
        $this->assertSame('inst_a', $tools[0]->getName());
        $this->assertSame('inst_b', $tools[1]->getName());

        // Each name must execute through its own instance, not the first
        // class match.
        $this->assertSame('tag:a', $toolbox->execute(new ToolCall('c1', 'inst_a', []))->getResult());
        $this->assertSame('tag:b', $toolbox->execute(new ToolCall('c2', 'inst_b', []))->getResult());
    }

    private function createToolbox(
        ToolRegistry $registry,
        ?EventDispatcher $dispatcher = null,
        ?ExtensionHookRegistry $rewriteHookProvider = null,
        ?\Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface $resolver = null,
    ): RegistryBackedToolbox {
        return new RegistryBackedToolbox(
            registry: $registry,
            argumentResolver: new RawAwareToolCallArgumentResolver($resolver ?? new ToolCallArgumentResolver()),
            eventDispatcher: $dispatcher,
            rewriteHookProvider: $rewriteHookProvider,
        );
    }

    /**
     * Native resolver with the app serializer stack: camel_case_to_snake_case
     * name converter + property type extractors (same as the @serializer
     * service). createToolbox() wraps it in RawAwareToolCallArgumentResolver.
     */
    private function createNameConverterResolver(): ToolCallArgumentResolver
    {
        $propertyTypeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        return new ToolCallArgumentResolver(new Serializer([
            new DateTimeNormalizer(),
            new BackedEnumNormalizer(),
            new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
            new ArrayDenormalizer(),
        ]));
    }

    private function dummyHandler(mixed $result): object
    {
        return new class($result) {
            public function __construct(
                private readonly mixed $result,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                return $this->result;
            }
        };
    }

    private function countingHandler(mixed $result): object
    {
        return new class($result) {
            public int $calls = 0;

            public function __construct(
                private readonly mixed $result,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                ++$this->calls;

                return $this->result;
            }
        };
    }

    /**
     * Handler that records the last arguments it was called with.
     */
    private function capturingHandler(): object
    {
        return new class {
            public ?array $lastArgs = null;

            public function __invoke(array $arguments): mixed
            {
                $this->lastArgs = $arguments;

                return 'ok';
            }
        };
    }

    /**
     * @param list<ToolCallRewriteHookInterface> $hooks
     */
    private function stubRewriteProvider(string $toolName, array $hooks): ExtensionHookRegistry
    {
        $registry = new ExtensionHookRegistry();
        foreach ($hooks as $hook) {
            $registry->addToolCallRewriteHook($toolName, $hook);
        }

        return $registry;
    }
}

/**
 * Named handler double so two distinct instances share one handler class
 * (anonymous classes are always distinct classes, which would not exercise
 * the same-class metadata routing).
 */
final class RegistryBackedToolboxSharedClassHandler
{
    public function __construct(
        private readonly string $tag,
    ) {
    }

    public function __invoke(array $arguments = []): string
    {
        return 'tag:'.$this->tag;
    }
}

/**
 * DTO double with a scalar property so denormalization type failures
 * (NotNormalizableValueException) stay testable through the typed path.
 */
final class FragileCountArgumentsDTO
{
    public int $count = 0;
}
