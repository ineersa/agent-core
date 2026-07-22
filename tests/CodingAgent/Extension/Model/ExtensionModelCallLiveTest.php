<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Model;

use Ineersa\CodingAgent\Extension\Model\ExtensionModelCaller;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Model\ModelCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Live smoke for the extension blocking model-call path.
 *
 * Thesis: ExtensionModelCaller can resolve llama_cpp_test/test through the
 * production catalog + lazy platform factory and return one completed response
 * without ambient tools. Unique first prompt avoids llama-proxy cache collisions.
 */
#[Group('llm-real')]
final class ExtensionModelCallLiveTest extends IsolatedKernelTestCase
{
    public function testBlockingCallModelReturnsCompletedResponse(): void
    {
        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

        /** @var ExtensionModelCaller $caller */
        $caller = self::getContainer()->get(ExtensionModelCaller::class);

        try {
            $result = $caller->call(
                'llama_cpp_test/test',
                [[
                    'role' => 'user',
                    'content' => '[llm-real:extension-call-model-v1] Respond with exactly one word: hello.',
                ]],
            );
        } catch (ModelCallException $e) {
            $this->fail(\sprintf(
                'callModel failed with public error %s for model %s: %s',
                $e->errorCode,
                (string) $e->model,
                $e->getMessage(),
            ));
        }

        $this->assertSame('llama_cpp_test/test', $result->model);
        $this->assertNotSame('', trim($result->content));
        $this->assertIsArray($result->toolCalls);
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
