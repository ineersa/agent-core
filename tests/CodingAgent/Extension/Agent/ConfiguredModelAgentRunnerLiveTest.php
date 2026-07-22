<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Live smoke for the public ExtensionApi blocking agent runner.
 *
 * Thesis: agent()->run() returns only after the configured provider stream is
 * fully drained and native AgentProcessor has executed an isolated
 * extension-only tool call. Unique first prompt avoids llama-proxy cache collisions.
 */
#[Group('llm-real')]
final class ConfiguredModelAgentRunnerLiveTest extends IsolatedKernelTestCase
{
    public function testBlockingRunDrainsStreamAndExecutesIsolatedExtensionTool(): void
    {
        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

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
            description: 'Record a fixed marker for the observational-memory live proof. Call this tool exactly once with the required arguments and do not answer with prose first.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'marker' => [
                        'type' => 'string',
                        'description' => 'Exact marker token to record. Must be om-live-marker-v1.',
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
            model: 'llama_cpp_test/test',
            sessionId: 'om-agent-live-'.bin2hex(random_bytes(4)),
            instructions: 'You are a tool-calling test agent. You MUST call the tool om_record_marker exactly once. Never reply with plain text before the tool call. Use exactly these arguments: marker="om-live-marker-v1" and nonce="7a73737a7".',
            input: '[llm-real:extension-agent-tool-drain-v1] Call om_record_marker now with marker=om-live-marker-v1 and nonce=7a73737a7. Do not write any other text.',
            tools: [$tool],
            correlationId: 'om-agent-live-proof',
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
        $this->assertSame('om-live-marker-v1', $sideEffect['arguments']['marker'] ?? null);
        $this->assertSame('7a73737a7', $sideEffect['arguments']['nonce'] ?? null);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        $settingsPath = getcwd().'/.hatfield/settings.yaml';
        $settings = [];
        if (is_readable($settingsPath)) {
            $parsed = Yaml::parseFile($settingsPath);
            $settings = \is_array($parsed) ? $parsed : [];
        }

        $provider = $settings['ai']['providers']['llama_cpp_test']
            ?? $settings['ai']['providers']['llama_cpp']
            ?? null;
        if (!\is_array($provider)) {
            return;
        }

        $projectSettings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => (string) ($provider['type'] ?? 'generic'),
                        'enabled' => true,
                        'base_url' => (string) ($provider['base_url'] ?? 'http://127.0.0.1:9052/v1'),
                        'api' => (string) ($provider['api'] ?? 'openai-completions'),
                        'api_key' => (string) ($provider['api_key'] ?? 'dummy'),
                        'completions_path' => (string) ($provider['completions_path'] ?? '/chat/completions'),
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $target = $classCwd.'/.hatfield/settings.yaml';
        if (!is_dir(\dirname($target))) {
            mkdir(\dirname($target), 0o755, true);
        }
        file_put_contents($target, Yaml::dump($projectSettings, 8, 2));
    }
}
