<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Providers\ProvidersUpdateCommand;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

final class ProvidersUpdateCommandTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $catalogPath;
    private string $userCatalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_update');
        $this->homeDir = $this->tmpDir.'/home';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';
        $this->userCatalogPath = $this->homeDir.'/.hatfield/ai-catalog.yaml';

        file_put_contents($this->catalogPath, <<<'YAML'
version: 3
providers:
    zai:
        label: 'Z.ai'
        kind: apikey
        type: generic
        enabled: false
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        completions_path: /chat/completions
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
                thinking_level_map: { minimal: enabled }
                cost: { input: 0.5, output: 1.0 }
YAML);

        file_put_contents($this->userCatalogPath, <<<'YAML'
version: 1
providers:
    zai:
        label: 'Old Z.ai'
        kind: apikey
        type: generic
        enabled: true
        base_url: https://user-should-lose.example
        api: openai-completions
        completions_path: /old
        models:
            glm-5.3:
                name: Old GLM
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
                thinking_level_map: { minimal: enabled }
                cost: { input: 0.5, output: 1.0 }
            user-only-model:
                name: User Only
                context_window: 8192
                max_tokens: 2048
                input: [text]
                tool_calling: true
                reasoning: false
                thinking_level_map: { off: none, minimal: null, low: null, medium: null, high: null, xhigh: null }
                cost: { input: 0, output: 0 }
YAML);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testRebaseAndSyncUpdatesMetadataWithoutAddingNewUpstreamIds(): void
    {
        $before = (string) file_get_contents($this->userCatalogPath);

        $payload = json_encode([
            'zai' => [
                'api' => 'https://attacker.example',
                'base_url' => 'https://attacker.example',
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 2000000, 'output' => 64000],
                        'modalities' => ['input' => ['text', 'audio', 'image']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 1.1, 'output' => 2.2],
                        'api' => 'https://attacker.example/model',
                        'base_url' => 'https://attacker.example',
                    ],
                    'glm-future' => [
                        'name' => 'GLM Future',
                        'limit' => ['context' => 500000, 'output' => 32000],
                        'modalities' => ['input' => ['text']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 3.0, 'output' => 4.0],
                    ],
                ],
            ],
            'anthropic' => ['models' => []],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(static function (string $method, string $url) use ($payload): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('models.dev/api.json', $url);

            return new MockResponse($payload, ['http_code' => 200]);
        });

        $output = new BufferedOutput();
        $this->assertSame(Command::SUCCESS, $this->runCommand($client, $output));
        $display = $output->fetch();

        $after = Yaml::parseFile($this->userCatalogPath);
        $this->assertIsArray($after);
        $this->assertSame(3, $after['version']);
        $this->assertNotSame($before, (string) file_get_contents($this->userCatalogPath));

        $zai = $after['providers']['zai'];
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $zai['base_url']);
        $this->assertSame('openai-completions', $zai['api']);
        $this->assertSame('/chat/completions', $zai['completions_path']);
        $this->assertFalse($zai['enabled']);
        $this->assertSame('Z.ai', $zai['label']);
        $this->assertArrayNotHasKey('https://attacker.example', $zai);

        $this->assertSame(['glm-5.3', 'user-only-model'], array_keys($zai['models']));
        $this->assertSame(8192, $zai['models']['user-only-model']['context_window']);

        $this->assertSame(2000000, $zai['models']['glm-5.3']['context_window']);
        $this->assertSame(64000, $zai['models']['glm-5.3']['max_tokens']);
        $this->assertSame(['text', 'image'], $zai['models']['glm-5.3']['input']);
        $this->assertSame(1.1, $zai['models']['glm-5.3']['cost']['input']);
        $this->assertSame(['minimal' => 'enabled'], $zai['models']['glm-5.3']['thinking_level_map']);

        $this->assertArrayNotHasKey('glm-future', $zai['models']);
        $this->assertArrayNotHasKey('api', $zai['models']['glm-5.3']);
        $this->assertArrayNotHasKey('base_url', $zai['models']['glm-5.3']);

        $displayNormalized = (string) preg_replace('/\s+/', ' ', $display);
        $this->assertStringContainsString('1 metadata refreshes, 1 new models available upstream (not added)', $displayNormalized);
        $this->assertStringContainsString('available upstream (not added): zai: glm-future', $displayNormalized);
        $this->assertStringNotContainsString('added:', $displayNormalized);
    }

    public function testNetworkErrorLeavesUserCatalogUntouched(): void
    {
        $before = (string) file_get_contents($this->userCatalogPath);
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'network down', 'http_code' => 0]),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runCommand($client));
        $this->assertSame($before, (string) file_get_contents($this->userCatalogPath));
    }

    public function testHttpErrorLeavesUserCatalogUntouched(): void
    {
        $before = (string) file_get_contents($this->userCatalogPath);
        $client = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 503]),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runCommand($client));
        $this->assertSame($before, (string) file_get_contents($this->userCatalogPath));
    }

    public function testUnchangedUpstreamStillRebasesVersionAndConnectionFields(): void
    {
        $payload = json_encode([
            'zai' => [
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 1000000, 'output' => 131072],
                        'modalities' => ['input' => ['text']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 0.5, 'output' => 1.0],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient([new MockResponse($payload, ['http_code' => 200])]);
        $this->assertSame(Command::SUCCESS, $this->runCommand($client));

        $after = Yaml::parseFile($this->userCatalogPath);
        $this->assertSame(3, $after['version']);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $after['providers']['zai']['base_url']);
        $this->assertArrayHasKey('user-only-model', $after['providers']['zai']['models']);
    }

    public function testUnmappedProviderWarnsAndDoesNotCrash(): void
    {
        $bundled = Yaml::parseFile($this->catalogPath);
        $this->assertIsArray($bundled);
        $bundled['providers']['my-custom'] = [
            'label' => 'My Custom',
            'kind' => 'custom',
            'type' => 'generic',
            'enabled' => false,
            'base_url' => 'http://127.0.0.1:9',
            'api' => 'openai-completions',
            'models' => [
                'custom-1' => [
                    'name' => 'Custom 1',
                    'context_window' => 4096,
                    'max_tokens' => 1024,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'cost' => ['input' => 0, 'output' => 0],
                ],
            ],
        ];
        file_put_contents($this->catalogPath, Yaml::dump($bundled, 6, 4));

        $payload = json_encode([
            'zai' => [
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 2000000, 'output' => 64000],
                        'modalities' => ['input' => ['text']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 1.1, 'output' => 2.2],
                    ],
                ],
            ],
            'my-custom' => [
                'api' => 'https://attacker.example',
                'models' => [
                    'custom-1' => [
                        'limit' => ['context' => 1, 'output' => 1],
                        'cost' => ['input' => 99, 'output' => 99],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient([new MockResponse($payload, ['http_code' => 200])]);
        $output = new BufferedOutput();
        $this->assertSame(Command::SUCCESS, $this->runCommand($client, $output));
        $display = $output->fetch();

        $this->assertStringContainsString('skipped (no upstream mapping): my-custom', $display);

        $after = Yaml::parseFile($this->userCatalogPath);
        $this->assertIsArray($after);
        $this->assertSame(2000000, $after['providers']['zai']['models']['glm-5.3']['context_window']);
        $this->assertSame(4096, $after['providers']['my-custom']['models']['custom-1']['context_window']);
        $this->assertSame(0, $after['providers']['my-custom']['models']['custom-1']['cost']['input']);
        $this->assertSame('http://127.0.0.1:9', $after['providers']['my-custom']['base_url']);
    }

    public function testUserOnlyProviderSurvivesRebaseAndSyncWholesale(): void
    {
        $user = Yaml::parseFile($this->userCatalogPath);
        $this->assertIsArray($user);
        $user['providers']['my-local-llm'] = [
            'label' => 'My Local LLM',
            'kind' => 'custom',
            'type' => 'generic',
            'enabled' => true,
            'base_url' => 'http://127.0.0.1:8080',
            'api' => 'openai-completions',
            'completions_path' => '/v1/chat/completions',
            'models' => [
                'local-7b' => [
                    'name' => 'Local 7B',
                    'context_window' => 8192,
                    'max_tokens' => 2048,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'thinking_level_map' => [
                        'off' => 'none',
                        'minimal' => null,
                        'low' => null,
                        'medium' => null,
                        'high' => null,
                        'xhigh' => null,
                    ],
                    'cost' => ['input' => 0, 'output' => 0],
                ],
            ],
        ];
        file_put_contents($this->userCatalogPath, Yaml::dump($user, 6, 4));

        $payload = json_encode([
            'zai' => [
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 1000000, 'output' => 131072],
                        'modalities' => ['input' => ['text']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 0.5, 'output' => 1.0],
                    ],
                ],
            ],
            'my-local-llm' => [
                'api' => 'https://attacker.example',
                'base_url' => 'https://attacker.example',
                'models' => [
                    'local-7b' => [
                        'limit' => ['context' => 999, 'output' => 1],
                        'cost' => ['input' => 99, 'output' => 99],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient([new MockResponse($payload, ['http_code' => 200])]);
        $this->assertSame(Command::SUCCESS, $this->runCommand($client));

        $after = Yaml::parseFile($this->userCatalogPath);
        $this->assertIsArray($after);
        $this->assertArrayHasKey('my-local-llm', $after['providers']);
        $local = $after['providers']['my-local-llm'];
        $this->assertSame('http://127.0.0.1:8080', $local['base_url']);
        $this->assertSame('openai-completions', $local['api']);
        $this->assertTrue($local['enabled']);
        $this->assertSame('My Local LLM', $local['label']);
        $this->assertSame(8192, $local['models']['local-7b']['context_window']);
        $this->assertSame(0, $local['models']['local-7b']['cost']['input']);
        $this->assertArrayNotHasKey('https://attacker.example', $local);
    }

    private function runCommand(MockHttpClient $client, ?BufferedOutput $output = null): int
    {
        $command = new ProvidersUpdateCommand(
            httpClient: $client,
            aiCatalog: new AiCatalog($this->catalogPath, $this->homeDir),
        );

        $io = new SymfonyStyle(new ArrayInput([]), $output ?? new BufferedOutput());

        return $command($io);
    }
}
