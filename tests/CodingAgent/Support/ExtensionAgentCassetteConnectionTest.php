<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ExtensionAgentCassetteConnectionTest extends TestCase
{
    public function testCatalogModelAndEndpointMergeWithUserEnablementAndCredentials(): void
    {
        $this->withCatalog('/chat/completions', static function (string $home): void {
            $connection = ExtensionAgentCassetteConnection::load(ProjectDir::get(), $home.'/project', $home);
            self::assertSame(['base_url' => 'https://fixture.invalid/v1', 'api_key' => 'env:CASSETTE_FIXTURE_KEY'], $connection);

            // The catalog advertises reasoning=false, but recording must send the replay scenario's request.
            $record = ExtensionAgentCassetteConnection::scenarioProvider($connection);
            $replay = ExtensionAgentCassetteConnection::scenarioProvider([]);
            self::assertTrue($record['models']['flash']['reasoning']);
            self::assertSame($connection, array_intersect_key($record, $connection));
            self::assertSame(array_diff_key($replay, $connection), array_diff_key($record, $connection));
        });
    }

    public function testDivergentCompletionsPathIsRejectedInsteadOfRecordingWrongRoute(): void
    {
        $this->withCatalog('/custom/chat', function (string $home): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('completions_path: /chat/completions');
            ExtensionAgentCassetteConnection::load(ProjectDir::get(), $home.'/project', $home);
        });
    }

    /** @param callable(string): void $assert */
    private function withCatalog(string $path, callable $assert): void
    {
        $home = TestDirectoryIsolation::createProjectTempDir('cassette-catalog');
        try {
            $directory = TestDirectoryIsolation::allocateFixtureDirectory($home, '.hatfield');
            try {
                $this->assertSame($home.'/.hatfield', realpath($directory));
                $files = [
                    'settings.yaml' => ['ai' => ['providers' => ['llama_cpp' => ['enabled' => true, 'api_key' => 'env:CASSETTE_FIXTURE_KEY']]]],
                    'ai-catalog.yaml' => ['version' => 1, 'providers' => ['llama_cpp' => [
                        'type' => 'generic',
                        'enabled' => false,
                        'base_url' => 'https://fixture.invalid/v1/',
                        'completions_path' => $path,
                        'models' => ['flash' => ['name' => 'Fixture Display Name', 'reasoning' => false]],
                    ]]],
                ];
                foreach ($files as $name => $data) {
                    $target = $directory.'/'.$name;
                    $this->assertFileDoesNotExist($target);
                    $this->assertFalse(is_link($target));
                    file_put_contents($target, Yaml::dump($data, 10));
                }
                $assert($home);
            } finally {
                TestDirectoryIsolation::removeDirectory($directory);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($home);
        }
    }
}
