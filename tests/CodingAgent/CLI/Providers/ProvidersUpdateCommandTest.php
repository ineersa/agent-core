<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Providers\ProvidersUpdateCommand;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
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
    private string $appRoot;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_update');
        $this->homeDir = $this->tmpDir.'/home';
        $this->appRoot = $this->tmpDir.'/app';
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        TestDirectoryIsolation::ensureDirectory($this->appRoot.'/config');

        file_put_contents($this->appRoot.'/config/ai-catalog.yaml', <<<'YAML'
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
        file_put_contents($this->appRoot.'/config/models-dev.snapshot.json', "{}\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testWritesFilteredCacheAndEtagOn200(): void
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

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($payload): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('models.dev/api.json', $url);
            self::assertArrayNotHasKey('if-none-match', array_change_key_case($options['headers'] ?? [], \CASE_LOWER));

            return new MockResponse($payload, [
                'http_code' => 200,
                'response_headers' => ['etag' => '"abc123"'],
            ]);
        });

        $exit = $this->runCommand($client);
        $this->assertSame(Command::SUCCESS, $exit);

        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        $this->assertFileExists($cachePath);
        $this->assertSame('0600', substr(\sprintf('%o', fileperms($cachePath)), -4));
        $decoded = json_decode((string) file_get_contents($cachePath), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['zai'], array_keys($decoded));
        $this->assertArrayHasKey('glm-future', $decoded['zai']['models']);
        $this->assertSame('"abc123"', trim((string) file_get_contents($this->homeDir.'/.hatfield/cache/models-dev.etag')));
    }

    public function test304KeepsExistingCache(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/cache');
        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        $etagPath = $this->homeDir.'/.hatfield/cache/models-dev.etag';
        file_put_contents($cachePath, "{\"zai\":{\"models\":{}}}\n");
        file_put_contents($etagPath, "\"old\"\n");
        $before = (string) file_get_contents($cachePath);

        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $headers = array_change_key_case($options['headers'] ?? [], \CASE_LOWER);
            self::assertSame('"old"', $headers['if-none-match'] ?? null);

            return new MockResponse('', ['http_code' => 304]);
        });

        $this->assertSame(Command::SUCCESS, $this->runCommand($client));
        $this->assertSame($before, (string) file_get_contents($cachePath));
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

    public function testRefreshSnapshotWritesRepoFile(): void
    {
        $payload = json_encode([
            'deepseek' => ['models' => ['deepseek-v4-flash' => []]],
            'other' => ['models' => []],
        ], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($payload, ['http_code' => 200, 'response_headers' => ['etag' => '"snap"']]),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runCommand($client, refreshSnapshot: true));
        $snapshot = $this->appRoot.'/config/models-dev.snapshot.json';
        $decoded = json_decode((string) file_get_contents($snapshot), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['deepseek'], array_keys($decoded));
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/cache/models-dev.etag');
    }

    private function runCommand(MockHttpClient $client, bool $refreshSnapshot = false): int
    {
        $command = new ProvidersUpdateCommand(
            httpClient: $client,
            pathResolver: new SettingsPathResolver(appRoot: $this->appRoot, homeDir: $this->homeDir),
            resources: new AppResourceLocator($this->appRoot),
        );

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        return $command($io, refreshSnapshot: $refreshSnapshot);
    }
}
