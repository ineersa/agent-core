<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\BuiltInToolRegistrar;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BuiltInToolRegistrar.
 *
 * Covers tagged provider collection and permanent tool registration.
 */
final class BuiltInToolRegistrarTest extends TestCase
{
    public function testRegistersEmptyProviders(): void
    {
        $registry = new ToolRegistry();
        $registrar = new BuiltInToolRegistrar(
            $registry,
            [], // no providers
        );

        $registrar->registerTools();

        self::assertSame([], $registry->activeToolNames());
    }

    public function testRegistersSingleProvider(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler();

        $provider = $this->createProvider('read', 'Read tool', $handler, 'read: Read', ['G1']);

        $registrar = new BuiltInToolRegistrar($registry, [$provider]);
        $registrar->registerTools();

        self::assertSame(['read'], $registry->activeToolNames());

        $def = $registry->toolDefinition('read');
        self::assertNotNull($def);
        self::assertSame($handler, $def->handler);
        self::assertSame('Read tool', $def->description);
        self::assertSame('read: Read', $def->promptLine);
        self::assertSame(['G1'], $def->promptGuidelines);
    }

    public function testRegistersMultipleProvidersInOrder(): void
    {
        $registry = new ToolRegistry();

        $providers = [
            $this->createProvider('a', 'A', $this->dummyHandler(), 'a: A'),
            $this->createProvider('b', 'B', $this->dummyHandler(), 'b: B'),
            $this->createProvider('c', 'C', $this->dummyHandler(), 'c: C'),
        ];

        $registrar = new BuiltInToolRegistrar($registry, $providers);
        $registrar->registerTools();

        self::assertSame(['a', 'b', 'c'], $registry->activeToolNames());
    }

    public function testRegistersMultipleCallsAreIdempotent(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler();

        $provider = $this->createProvider('tool', 'Tool', $handler, 'tool: Tool', ['G1']);

        $registrar = new BuiltInToolRegistrar($registry, [$provider]);
        $registrar->registerTools();
        $registrar->registerTools(); // second call should be a no-op

        self::assertCount(1, $registry->activeToolNames());
        self::assertCount(1, $registry->permanentToolLines());
    }

    public function testProviderWithGuidelines(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler();

        $provider = $this->createProvider('tool', 'Tool', $handler, 'tool: Tool', ['GL1', 'GL2']);

        $registrar = new BuiltInToolRegistrar($registry, [$provider]);
        $registrar->registerTools();

        self::assertSame(['GL1', 'GL2'], $registry->permanentGuidelines());
    }

    public function testProviderWithoutGuidelines(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler();

        $provider = $this->createProvider('tool', 'Tool', $handler, 'tool: Tool');

        $registrar = new BuiltInToolRegistrar($registry, [$provider]);
        $registrar->registerTools();

        self::assertSame([], $registry->permanentGuidelines());
    }

    /* ───────── Private helpers ───────── */

    private function createProvider(
        string $name,
        string $description,
        ToolHandlerInterface $handler,
        string $promptLine,
        array $promptGuidelines = [],
    ): HatfieldToolProviderInterface {
        $def = new ToolDefinitionDTO(
            name: $name,
            description: $description,
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: $promptLine,
            promptGuidelines: $promptGuidelines,
        );

        return new class($def) implements HatfieldToolProviderInterface {
            public function __construct(
                private readonly ToolDefinitionDTO $definition,
            ) {
            }

            public function definition(): ToolDefinitionDTO
            {
                return $this->definition;
            }
        };
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'handler result';
            }
        };
    }
}
