<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests for sparse home AI settings mutations via YAML round-trip.
 *
 * Thesis: writers replace or insert one ai.* override, preserve unrelated
 * parsed keys, create a sparse document on first write, and fail clearly on
 * unreadable files, uncreatable directories, or malformed root/ai structure.
 */
class HomeSettingsWriterTest extends TestCase
{
    private string $tmpDir;
    private HomeSettingsWriter $writer;
    private string $file;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('hatfield_writer');
        $this->file = $this->tmpDir.'/.hatfield/settings.yaml';
        $pathResolver = new SettingsPathResolver('/app', $this->tmpDir);
        $this->writer = new HomeSettingsWriter($pathResolver);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testReplacesExistingAiValue(): void
    {
        $this->write([
            'ai' => [
                'default_model' => 'old',
                'default_reasoning' => 'medium',
            ],
        ]);

        $this->writer->writeDefaultModel('zai/glm-5.1');

        $parsed = $this->parse();
        $this->assertSame('zai/glm-5.1', $parsed['ai']['default_model'] ?? null);
        $this->assertSame('medium', $parsed['ai']['default_reasoning'] ?? null);
    }

    public function testPreservesUnrelatedSettings(): void
    {
        $this->write([
            'tui' => ['theme' => 'cyberpunk'],
            'logging' => ['level' => 'debug'],
            'ai' => ['default_reasoning' => 'medium'],
        ]);

        $this->writer->writeDefaultModel('llama_cpp/flash');

        $parsed = $this->parse();
        $this->assertSame('llama_cpp/flash', $parsed['ai']['default_model'] ?? null);
        $this->assertSame('medium', $parsed['ai']['default_reasoning'] ?? null);
        $this->assertSame('cyberpunk', $parsed['tui']['theme'] ?? null);
        $this->assertSame('debug', $parsed['logging']['level'] ?? null);
    }

    public function testInsertsAiSectionWhenAbsent(): void
    {
        $this->write([
            'tui' => ['theme' => 'nord'],
        ]);

        $this->writer->writeDefaultReasoning('high');

        $parsed = $this->parse();
        $this->assertSame('high', $parsed['ai']['default_reasoning'] ?? null);
        $this->assertSame('nord', $parsed['tui']['theme'] ?? null);
    }

    public function testFavoriteModelsListAndEmptyListSurviveYamlRoundTrip(): void
    {
        $this->write([
            'ai' => ['default_model' => 'old'],
        ]);

        $this->writer->writeFavoriteModels(['zai/glm-5.1', 'llama_cpp/flash']);
        $parsed = $this->parse();
        $this->assertSame(['zai/glm-5.1', 'llama_cpp/flash'], $parsed['ai']['favorite_models'] ?? null);

        $this->writer->writeFavoriteModels([]);
        $parsed = $this->parse();
        $this->assertSame([], $parsed['ai']['favorite_models'] ?? null);
        $this->assertIsArray($parsed['ai']['favorite_models']);
    }

    public function testMissingFileCreatesSparseAiDocument(): void
    {
        $this->assertFileDoesNotExist($this->file);

        $this->writer->writeDefaultModel('zai/glm-5.1');

        $this->assertFileExists($this->file);
        $parsed = $this->parse();
        $this->assertSame(['ai' => ['default_model' => 'zai/glm-5.1']], $parsed);
    }

    public function testThrowsWhenRootDocumentIsNotAMapping(): void
    {
        TestDirectoryIsolation::ensureDirectory(\dirname($this->file));
        file_put_contents($this->file, "just a scalar\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('root document must be a mapping');

        $this->writer->writeDefaultModel('x');
    }

    public function testThrowsWhenAiValueIsNotAMapping(): void
    {
        $this->write(['ai' => 'broken']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('key "ai" must be a mapping');

        $this->writer->writeDefaultModel('x');
    }

    public function testThrowsWhenExistingHomeFileIsUnreadable(): void
    {
        TestDirectoryIsolation::ensureDirectory(\dirname($this->file));
        file_put_contents($this->file, "ai:\n    default_model: old\n");
        chmod($this->file, 0o000);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot read');
            $this->writer->writeDefaultModel('new/model');
        } finally {
            chmod($this->file, 0o644);
        }
    }

    public function testThrowsWhenHomeDirectoryCannotBeCreated(): void
    {
        $blockedHome = $this->tmpDir.'/blocked-home';
        file_put_contents($blockedHome, 'not-a-directory');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create home settings directory');

        $writer = new HomeSettingsWriter(new SettingsPathResolver('/app', $blockedHome));
        $writer->writeDefaultModel('x');
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        TestDirectoryIsolation::ensureDirectory(\dirname($this->file));
        file_put_contents($this->file, Yaml::dump($data, 4, 4));
    }

    /** @return array<string, mixed> */
    private function parse(): array
    {
        $parsed = Yaml::parseFile($this->file);

        return \is_array($parsed) ? $parsed : [];
    }
}
