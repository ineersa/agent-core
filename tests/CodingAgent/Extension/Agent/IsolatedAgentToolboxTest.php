<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Extension\Agent\IsolatedAgentToolbox;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Platform\Result\ToolCall;

final class IsolatedAgentToolboxTest extends TestCase
{
    public function testGetToolsExposesAgentToolMetadata(): void
    {
        $toolbox = $this->createToolbox([
            new AgentToolDTO(
                name: 'ext_read',
                description: 'Read',
                parametersJsonSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
                handler: new class implements ExtensionToolHandlerInterface {
                    public function __invoke(array $arguments): mixed
                    {
                        return 'ok';
                    }
                },
            ),
        ]);

        $tools = $toolbox->getTools();
        $this->assertCount(1, $tools);
        $this->assertSame('ext_read', $tools[0]->getName());
        $this->assertSame(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $tools[0]->getParameters());
    }

    public function testValidCallInvokesHandlerWithFlatArguments(): void
    {
        $handler = new class implements ExtensionToolHandlerInterface {
            public ?array $seen = null;

            public function __invoke(array $arguments): mixed
            {
                $this->seen = $arguments;

                return 'ok:'.($arguments['path'] ?? '');
            }
        };

        $toolbox = $this->createToolbox([
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
        // Raw-array path passes the flat provider arguments through verbatim.
        $this->assertSame(['path' => 'a.txt'], $handler->seen);
    }

    public function testUnknownToolThrowsToolNotFoundException(): void
    {
        $toolbox = $this->createToolbox([]);

        $this->expectException(ToolNotFoundException::class);
        $toolbox->execute(new ToolCall('c-unknown', 'nope', []));
    }

    public function testHandlerFailureIsWrappedForFaultTolerantToolbox(): void
    {
        $handler = new class implements ExtensionToolHandlerInterface {
            public function __invoke(array $arguments): mixed
            {
                throw new \RuntimeException('isolated boom');
            }
        };

        $inner = $this->createToolbox([
            new AgentToolDTO(
                name: 'ext_read',
                description: 'Read',
                parametersJsonSchema: [],
                handler: $handler,
            ),
        ]);
        $toolbox = new FaultTolerantToolbox($inner);

        $result = $toolbox->execute(new ToolCall('c2', 'ext_read', ['path' => 'a.txt']));

        $this->assertSame('An error occurred while executing tool "ext_read".', (string) $result->getResult());
    }

    public function testRawArgumentsArePassedThroughWithoutValidation(): void
    {
        // Dynamic extension-agent tools have raw-array handlers; missing or
        // unknown arguments are delegated to the extension handler, not
        // validated or invented here.
        $handler = new class implements ExtensionToolHandlerInterface {
            public int $calls = 0;

            public function __invoke(array $arguments): mixed
            {
                ++$this->calls;

                return 'called:'.json_encode($arguments);
            }
        };

        $toolbox = $this->createToolbox([
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

        $result = $toolbox->execute(new ToolCall('c3', 'ext_read', ['extra' => true]));
        $this->assertSame(1, $handler->calls);
        $this->assertSame('called:{"extra":true}', $result->getResult());
    }

    private function createToolbox(array $tools): IsolatedAgentToolbox
    {
        return new IsolatedAgentToolbox(
            $tools,
            new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
        );
    }
}
