<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Extension\Agent\IsolatedAgentToolbox;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

final class IsolatedAgentToolboxTest extends TestCase
{
    public function testValidCallInvokesHandler(): void
    {
        $handler = new class implements ExtensionToolHandlerInterface {
            public function __invoke(array $arguments): mixed
            {
                return 'ok:'.($arguments['path'] ?? '');
            }
        };

        $toolbox = new IsolatedAgentToolbox([
            new AgentToolDTO(
                name: 'ext_read',
                description: 'Read',
                parametersJsonSchema: [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                handler: $handler,
            ),
        ]);

        $result = $toolbox->execute(new ToolCall('c1', 'ext_read', ['path' => 'a.txt']));
        $this->assertSame('ok:a.txt', $result->getResult());
    }

    public function testInvalidArgumentsBecomeFaultTolerantResult(): void
    {
        $handler = new class implements ExtensionToolHandlerInterface {
            public int $calls = 0;

            public function __invoke(array $arguments): mixed
            {
                ++$this->calls;

                return 'nope';
            }
        };

        $inner = new IsolatedAgentToolbox([
            new AgentToolDTO(
                name: 'ext_read',
                description: 'Read',
                parametersJsonSchema: [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                handler: $handler,
            ),
        ]);
        $toolbox = IsolatedAgentToolbox::faultTolerant($inner);

        $result = $toolbox->execute(new ToolCall('c2', 'ext_read', []));
        $this->assertSame(0, $handler->calls);
        $this->assertIsString($result->getResult());
        $this->assertStringContainsString('Invalid arguments for tool "ext_read"', (string) $result->getResult());
    }

    public function testMalformedSchemaRejectedAtConstruction(): void
    {
        $handler = new class implements ExtensionToolHandlerInterface {
            public function __invoke(array $arguments): mixed
            {
                return 'x';
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unusable parameters JSON Schema');
        new IsolatedAgentToolbox([
            new AgentToolDTO(
                name: 'bad',
                description: 'Bad',
                parametersJsonSchema: ['type' => ['nope', []]],
                handler: $handler,
            ),
        ]);
    }
}
