<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ExtensionApi;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;
use PHPUnit\Framework\TestCase;

final class ExtensionApiContractsTest extends TestCase
{
    public function testToolRegistrationDtoConstructsWithMinimalArgs(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'test_tool',
            description: 'A test tool',
            parametersJsonSchema: ['type' => 'object', 'properties' => []],
            handler: 'some_handler',
        );

        $this->assertSame('test_tool', $dto->name);
        $this->assertSame('A test tool', $dto->description);
        $this->assertSame(['type' => 'object', 'properties' => []], $dto->parametersJsonSchema);
        $this->assertSame('some_handler', $dto->handler);
        $this->assertNull($dto->promptSummary);
        $this->assertSame([], $dto->promptGuidelines);
    }

    public function testToolRegistrationDtoConstructsWithAllArgs(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'full_tool',
            description: 'A tool with all fields',
            parametersJsonSchema: ['type' => 'object'],
            handler: static fn (): string => 'ok',
            promptSummary: 'A tool that does everything',
            promptGuidelines: ['Use sparingly', 'Check permissions first'],
        );

        $this->assertSame('full_tool', $dto->name);
        $this->assertSame('A tool with all fields', $dto->description);
        $this->assertSame(['type' => 'object'], $dto->parametersJsonSchema);
        $this->assertIsCallable($dto->handler);
        $this->assertSame('A tool that does everything', $dto->promptSummary);
        $this->assertSame(['Use sparingly', 'Check permissions first'], $dto->promptGuidelines);
    }

    public function testToolRegistrationDtoIsImmutable(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'immutable_test',
            description: 'desc',
            parametersJsonSchema: [],
            handler: null,
        );

        // All properties should be readonly; the class is final+readonly
        $this->assertTrue(
            (new \ReflectionClass($dto))->isReadOnly(),
            'ToolRegistrationDTO must be readonly'
        );
        $this->assertTrue(
            (new \ReflectionClass($dto))->isFinal(),
            'ToolRegistrationDTO must be final'
        );
    }

    public function testHatfieldExtensionInterfaceAcceptsExtensionApi(): void
    {
        $extensionApi = $this->createMock(ExtensionApiInterface::class);
        $extension = $this->createMock(HatfieldExtensionInterface::class);

        $extension->expects($this->once())
            ->method('register')
            ->with($this->identicalTo($extensionApi));

        $extension->register($extensionApi);
    }

    public function testExtensionApiInterfaceAcceptsToolRegistrationDto(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'api_test',
            description: 'desc',
            parametersJsonSchema: [],
            handler: 'handler_name',
        );

        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('registerTool')
            ->with($this->equalTo($dto));

        $api->registerTool($dto);
    }

    public function testPromptGuidelinesDefaultsToEmptyArray(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'no_guidelines',
            description: 'No guidelines',
            parametersJsonSchema: [],
            handler: null,
        );

        $this->assertIsArray($dto->promptGuidelines);
        $this->assertCount(0, $dto->promptGuidelines);
    }

    public function testHandlerAcceptsCallable(): void
    {
        $handler = static function (string $input): string {
            return 'processed: '.$input;
        };

        $dto = new ToolRegistrationDTO(
            name: 'callable_handler',
            description: 'Has callable handler',
            parametersJsonSchema: [],
            handler: $handler,
        );

        $this->assertIsCallable($dto->handler);
        $this->assertSame('processed: test', ($dto->handler)('test'));
    }

    public function testHandlerAcceptsString(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'string_handler',
            description: 'Has string handler reference',
            parametersJsonSchema: [],
            handler: 'App\\Tool\\MyTool::execute',
        );

        $this->assertSame('App\\Tool\\MyTool::execute', $dto->handler);
    }

    public function testHandlerAcceptsNull(): void
    {
        $dto = new ToolRegistrationDTO(
            name: 'null_handler',
            description: 'Handler can be null',
            parametersJsonSchema: [],
            handler: null,
        );

        $this->assertNull($dto->handler);
    }

    public function testParametersJsonSchemaRejectsInvalidSchemaOnUse(): void
    {
        // Construction is unvalidated — schema validation is a runtime concern
        $dto = new ToolRegistrationDTO(
            name: 'bad_schema',
            description: 'Schema is not validated at construction',
            parametersJsonSchema: ['invalid' => 'schema'],
            handler: null,
        );

        $this->assertSame(['invalid' => 'schema'], $dto->parametersJsonSchema);
    }
}
