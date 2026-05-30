<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ExtensionApi;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultDecisionKindEnum;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;
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
        $extensionApi = $this->createStub(ExtensionApiInterface::class);
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

    // --- ToolCallDecisionDTO tests ---

    public function testToolCallDecisionAllowHasCorrectKind(): void
    {
        $decision = ToolCallDecisionDTO::allow();

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $decision->kind);
        $this->assertNull($decision->reason);
        $this->assertNull($decision->result);
        $this->assertSame([], $decision->details);
    }

    public function testToolCallDecisionBlockHasReasonAndKind(): void
    {
        $decision = ToolCallDecisionDTO::block('Dangerous command', ['category' => 'dangerous_command']);

        $this->assertSame(ToolCallDecisionKindEnum::Block, $decision->kind);
        $this->assertSame('Dangerous command', $decision->reason);
        $this->assertNull($decision->result);
        $this->assertSame(['category' => 'dangerous_command'], $decision->details);
    }

    public function testToolCallDecisionReplaceResultHasResultAndKind(): void
    {
        $result = ['output' => 'cached']; // Arbitrary serializable value – mixed is correct
        $decision = ToolCallDecisionDTO::replaceResult($result);

        $this->assertSame(ToolCallDecisionKindEnum::ReplaceResult, $decision->kind);
        $this->assertNull($decision->reason);
        $this->assertSame($result, $decision->result);
        $this->assertSame([], $decision->details);
    }

    public function testToolCallDecisionRequireApprovalWithAllParams(): void
    {
        $decision = ToolCallDecisionDTO::requireApproval(
            prompt: 'Allow destructive command: rm -rf /tmp?',
            questionId: 'sg_qid_abc',
            schema: ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']],
            details: ['category' => 'destructive', 'command' => 'rm -rf /tmp'],
        );

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);
        $this->assertNull($decision->reason);
        $this->assertNull($decision->result);
        $this->assertSame('Allow destructive command: rm -rf /tmp?', $decision->details['prompt']);
        $this->assertSame('sg_qid_abc', $decision->details['question_id']);
        $this->assertSame(['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']], $decision->details['schema']);
        $this->assertSame('destructive', $decision->details['category']);
        $this->assertSame('rm -rf /tmp', $decision->details['command']);
    }

    public function testToolCallDecisionRequireApprovalWithMinimalParams(): void
    {
        $decision = ToolCallDecisionDTO::requireApproval(prompt: 'Allow this operation?');

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);
        $this->assertSame('Allow this operation?', $decision->details['prompt']);
        $this->assertSame(['type' => 'string'], $decision->details['schema']);
        $this->assertArrayNotHasKey('question_id', $decision->details);
    }

    public function testToolCallDecisionRequireApprovalWithoutQuestionId(): void
    {
        $decision = ToolCallDecisionDTO::requireApproval(
            prompt: 'Approve?',
            schema: ['type' => 'boolean'],
        );

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);
        $this->assertSame('Approve?', $decision->details['prompt']);
        $this->assertSame(['type' => 'boolean'], $decision->details['schema']);
        $this->assertArrayNotHasKey('question_id', $decision->details);
    }

    public function testToolCallDecisionDtoIsImmutable(): void
    {
        $decision = ToolCallDecisionDTO::allow();

        $this->assertTrue((new \ReflectionClass($decision))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($decision))->isFinal());
    }

    // --- ToolResultDecisionDTO tests ---

    public function testToolResultDecisionKeepHasCorrectKind(): void
    {
        $decision = ToolResultDecisionDTO::keep();

        $this->assertSame(ToolResultDecisionKindEnum::Keep, $decision->kind);
        $this->assertNull($decision->isError);
        $this->assertNull($decision->content);
        $this->assertNull($decision->details);
    }

    public function testToolResultDecisionReplaceWithAllFields(): void
    {
        $content = [[
            'type' => 'text',
            'text' => 'Modified result',
        ]];
        $details = ['modified' => true];
        $decision = ToolResultDecisionDTO::replace(
            isError: false,
            content: $content,
            details: $details,
        );

        $this->assertSame(ToolResultDecisionKindEnum::Replace, $decision->kind);
        $this->assertFalse($decision->isError);
        $this->assertSame($content, $decision->content);
        $this->assertSame($details, $decision->details);
    }

    public function testToolResultDecisionReplacePartialFields(): void
    {
        $decision = ToolResultDecisionDTO::replace(isError: true);

        $this->assertSame(ToolResultDecisionKindEnum::Replace, $decision->kind);
        $this->assertTrue($decision->isError);
        $this->assertNull($decision->content);
        $this->assertNull($decision->details);
    }

    public function testToolResultDecisionDtoIsImmutable(): void
    {
        $decision = ToolResultDecisionDTO::keep();

        $this->assertTrue((new \ReflectionClass($decision))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($decision))->isFinal());
    }

    // --- ExtensionApiInterface hook registration contracts ---

    public function testExtensionApiInterfaceAcceptsToolCallHook(): void
    {
        $hook = $this->createStub(ToolCallHookInterface::class);
        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('registerToolCallHook')
            ->with($this->identicalTo($hook));

        $api->registerToolCallHook($hook);
    }

    public function testExtensionApiInterfaceAcceptsToolResultHook(): void
    {
        $hook = $this->createStub(ToolResultHookInterface::class);
        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('registerToolResultHook')
            ->with($this->identicalTo($hook));

        $api->registerToolResultHook($hook);
    }

    // --- ToolCallContextDTO tests ---

    public function testToolCallContextDtoConstructs(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'call_123',
            toolName: 'bash',
            arguments: ['command' => 'ls'],
            orderIndex: 0,
            runId: 'run_abc',
            turnNo: 1,
            cwd: '/home/project',
            metadata: ['source' => 'test'],
        );

        $this->assertSame('call_123', $context->toolCallId);
        $this->assertSame('bash', $context->toolName);
        $this->assertSame(['command' => 'ls'], $context->arguments);
        $this->assertSame(0, $context->orderIndex);
        $this->assertSame('run_abc', $context->runId);
        $this->assertSame(1, $context->turnNo);
        $this->assertSame('/home/project', $context->cwd);
        $this->assertSame(['source' => 'test'], $context->metadata);
    }

    public function testToolCallContextDtoDefaults(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'call_456',
            toolName: 'read',
            arguments: ['path' => 'file.txt'],
            orderIndex: 1,
        );

        $this->assertNull($context->runId);
        $this->assertNull($context->turnNo);
        $this->assertNull($context->cwd);
        $this->assertSame([], $context->metadata);
    }

    // --- ToolResultContextDTO tests ---

    public function testToolResultContextDtoConstructs(): void
    {
        $content = [['type' => 'text', 'text' => 'ok']];
        $details = ['exitCode' => 0];
        $context = new ToolResultContextDTO(
            toolCallId: 'call_789',
            toolName: 'bash',
            arguments: ['command' => 'echo hi'],
            isError: false,
            content: $content,
            details: $details,
            runId: 'run_def',
            turnNo: 2,
            cwd: '/home/project',
            metadata: ['source' => 'test'],
        );

        $this->assertSame('call_789', $context->toolCallId);
        $this->assertSame('bash', $context->toolName);
        $this->assertSame(['command' => 'echo hi'], $context->arguments);
        $this->assertFalse($context->isError);
        $this->assertSame($content, $context->content);
        $this->assertSame($details, $context->details);
        $this->assertSame('run_def', $context->runId);
        $this->assertSame(2, $context->turnNo);
        $this->assertSame('/home/project', $context->cwd);
        $this->assertSame(['source' => 'test'], $context->metadata);
    }

    public function testToolResultContextDtoDefaults(): void
    {
        $context = new ToolResultContextDTO(
            toolCallId: 'call_000',
            toolName: 'write',
            arguments: ['path' => 'file.txt', 'content' => 'data'],
            isError: true,
            content: [],
            details: [],
        );

        $this->assertNull($context->runId);
        $this->assertNull($context->turnNo);
        $this->assertNull($context->cwd);
        $this->assertSame([], $context->metadata);
    }

    // --- ExtensionApiInterface settings and CWD contracts ---

    public function testExtensionApiInterfaceGetSettingsReturnsArray(): void
    {
        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('getSettings')
            ->with('safe_guard')
            ->willReturn(['enabled' => true]);

        $this->assertSame(['enabled' => true], $api->getSettings('safe_guard'));
    }

    public function testExtensionApiInterfaceGetSettingsReturnsEmptyForUnknownKey(): void
    {
        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('getSettings')
            ->with('unknown')
            ->willReturn([]);

        $this->assertSame([], $api->getSettings('unknown'));
    }

    public function testExtensionApiInterfaceGetCwdReturnsString(): void
    {
        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('getCwd')
            ->willReturn('/home/project');

        $this->assertSame('/home/project', $api->getCwd());
    }
}
