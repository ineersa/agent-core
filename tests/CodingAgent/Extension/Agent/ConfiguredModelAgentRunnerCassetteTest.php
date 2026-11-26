<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Tests\Support\ProviderCassetteHttpClientFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;

/** Exercises the public blocking agent runner against native provider HTTP replay. */
final class ConfiguredModelAgentRunnerCassetteTest extends IsolatedKernelTestCase
{
    private const string ENDPOINT = 'https://llama-cassette.invalid/v1';
    private const string SCENARIO = 'extension-agent-tool-drain';

    public function testBlockingRunDrainsStreamAndExecutesIsolatedExtensionTool(): void
    {
        $sideEffect = [
            'called' => false,
            'arguments' => null,
        ];

        $handler = new class($sideEffect) implements ExtensionToolHandlerInterface {
            /**
             * @param array{called: bool, arguments: mixed} $sideEffect
             */
            public function __construct(private array &$sideEffect)
            {
            }

            public function __invoke(array $arguments): mixed
            {
                $this->sideEffect['called'] = true;
                $this->sideEffect['arguments'] = $arguments;

                return 'recorded';
            }
        };

        $tool = new AgentToolDTO(
            name: 'om_record_marker',
            description: 'Record a fixed marker for the extension agent test. Call this tool exactly once with the required arguments and do not answer with prose first.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'marker' => [
                        'type' => 'string',
                        'description' => 'Exact marker token to record. Must be extension-marker-v1.',
                    ],
                    'nonce' => [
                        'type' => 'string',
                        'description' => 'Exact nonce token to record. Must be 7a73737a7.',
                    ],
                ],
                'required' => ['marker', 'nonce'],
                'additionalProperties' => false,
            ],
            handler: $handler,
        );

        /** @var AgentRunnerInterface $runner */
        $runner = self::getContainer()->get(AgentRunnerInterface::class);

        $request = new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: 'extension-agent-cassette',
            instructions: 'You are a tool-calling test agent. You MUST call the tool om_record_marker exactly once. Never reply with plain text before the tool call. Use exactly these arguments: marker="extension-marker-v1" and nonce="7a73737a7".',
            input: 'Call om_record_marker now with marker=extension-marker-v1 and nonce=7a73737a7. Do not write any other text.',
            tools: [$tool],
            correlationId: 'extension-agent-proof',
        );

        try {
            $runner->run($request);
        } catch (\Throwable $e) {
            $this->fail(\sprintf(
                'agent()->run() failed for model %s (%s): %s',
                $request->model,
                $e::class,
                $e->getMessage(),
            ));
        }

        $this->assertTrue(
            $sideEffect['called'],
            'Expected blocking run() to complete only after the isolated extension tool handler side effect occurred.'
        );
        $this->assertIsArray($sideEffect['arguments']);
        $this->assertSame('extension-marker-v1', $sideEffect['arguments']['marker'] ?? null);
        $this->assertSame('7a73737a7', $sideEffect['arguments']['nonce'] ?? null);
    }

    protected static function configureBootedClassContainer(): void
    {
        $capture = getenv('HATFIELD_PRIVATE_CASSETTE_DIR');
        $client = ProviderCassetteHttpClientFactory::offline(self::SCENARIO);
        if (false !== $capture && '' !== $capture) {
            $provider = self::provider();
            $client = new CassetteHttpClient(
                new HttpCassette($capture.'/'.self::SCENARIO.'.json', [$provider['base_url'] => self::ENDPOINT]),
                HttpClient::create(),
                record: true,
            );
        }
        self::getContainer()->set('Ineersa\CodingAgent\Infrastructure\SymfonyAi\llm.http_client', $client);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        $target = $classCwd.'/.hatfield/settings.yaml';
        self::assertSame($classCwd.'/.hatfield', realpath(\dirname($target)));
        self::assertSame($target, realpath($target));
        self::assertFalse(is_link($target));
        file_put_contents($target, Yaml::dump([
            'ai' => [
                'default_model' => 'llama_cpp/flash',
                'default_reasoning' => 'off',
                'http' => ['max_retries' => 0],
                'providers' => ['llama_cpp' => self::provider()],
            ],
        ], 8, 2));
    }

    /** @return array<string, mixed> */
    private static function provider(): array
    {
        $capture = getenv('HATFIELD_PRIVATE_CASSETTE_DIR');
        if (false !== $capture && '' !== $capture) {
            return Yaml::parseFile($capture.'/provider.yaml');
        }

        return [
            'type' => 'generic',
            'enabled' => true,
            'base_url' => self::ENDPOINT,
            'api' => 'openai-completions',
            'completions_path' => '/chat/completions',
            'supports_completions' => true,
            'supports_thinking_levels' => true,
            'compatibility' => ['thinking_format' => 'llama_cpp'],
            'models' => [
                'flash' => [
                    'name' => 'flash',
                    'context_window' => 262144,
                    'max_tokens' => 65536,
                    'input' => ['text', 'image'],
                    'tool_calling' => true,
                    'reasoning' => true,
                    'thinking_level_map' => ['off' => 'none'],
                    'compatibility' => ['thinking_format' => 'mistral', 'supports_reasoning_effort' => true],
                ],
            ],
        ];
    }
}
