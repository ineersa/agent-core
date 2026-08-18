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

final class ProvidersUpdateCommandTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_update');
        $this->homeDir = $this->tmpDir.'/home';
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';

        file_put_contents($this->catalogPath, <<<'YAML'
providers:
    zai:
        type: generic
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
YAML);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testWritesFilteredCacheOn200(): void
    {
        $payload = json_encode([
            'zai' => [
                'api' => 'https://should-stay-in-cache-object-but-not-applied',
                'models' => [
                    'glm-5.3' => ['limit' => ['context' => 1]],
                    'glm-future' => ['limit' => ['context' => 2]],
                ],
            ],
            'anthropic' => ['models' => []],
        ], \JSON_THROW_ON_ERROR);

        $output = new BufferedOutput();
        $client = new MockHttpClient(static function (string $method, string $url) use ($payload): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('models.dev/api.json', $url);

            return new MockResponse($payload, ['http_code' => 200]);
        });

        $this->assertSame(Command::SUCCESS, $this->runCommand($client, $output));

        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        $this->assertFileExists($cachePath);
        $this->assertSame('0600', substr(\sprintf('%o', fileperms($cachePath)), -4));
        $decoded = json_decode((string) file_get_contents($cachePath), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['zai'], array_keys($decoded));
        $this->assertArrayHasKey('glm-future', $decoded['zai']['models']);
        $this->assertStringContainsString('glm-future', $output->fetch());
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/cache/models-dev.etag');
    }

    public function testNetworkErrorKeepsExistingAndExitsZero(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/cache');
        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        file_put_contents($cachePath, "{\"kept\":true}\n");

        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'network down', 'http_code' => 0]),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runCommand($client));
        $this->assertSame("{\"kept\":true}\n", (string) file_get_contents($cachePath));
    }

    public function testHttpErrorKeepsExistingAndExitsZero(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/cache');
        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        file_put_contents($cachePath, "{\"kept\":true}\n");

        $client = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 503]),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runCommand($client));
        $this->assertSame("{\"kept\":true}\n", (string) file_get_contents($cachePath));
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
