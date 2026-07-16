<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    // ── writeDefaultModel ──────────────────────────────────────────────

    public function testReplacesActiveModel(): void
    {
        $this->write("ai:\n    default_model: old\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $p = $this->parse();
        $this->assertSame('zai/glm-5.1', $p['ai']['default_model'] ?? null);
        $this->assertSame('medium', $p['ai']['default_reasoning'] ?? null);
    }

    public function testDoesNotUncommentModelKey(): void
    {
        // Old behaviour silently uncommented commented keys.
        // Now: only active keys are replaced; commented keys are left
        // untouched and a fresh active key is inserted below the ai: section.
        $this->write("ai:\n# default_model: old\n");
        $this->writer->writeDefaultModel('deepseek/deepseek-v4-pro');

        $result = $this->read();
        // The commented line survives
        $this->assertStringContainsString('# default_model: old', $result);
        // A new active key is inserted
        $this->assertStringContainsString('default_model: deepseek/deepseek-v4-pro', $result);
        // The active key is not prefixed with #
        $this->assertStringNotContainsString('# default_model: deepseek', $result);

        $p = $this->parse();
        $this->assertSame('deepseek/deepseek-v4-pro', $p['ai']['default_model'] ?? null);
    }

    public function testInsertsModelWhenAiSectionExists(): void
    {
        $this->write("ai:\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $p = $this->parse();
        $this->assertSame('zai/glm-5.1', $p['ai']['default_model'] ?? null);
    }

    public function testAppendsAiSectionForModel(): void
    {
        $this->write("tui:\n    theme: cyberpunk\n");
        $this->writer->writeDefaultModel('llama_cpp/flash');

        $p = $this->parse();
        $this->assertSame('llama_cpp/flash', $p['ai']['default_model'] ?? null);
        $this->assertSame('cyberpunk', $p['tui']['theme'] ?? null);
    }

    public function testPreservesCommentsOnModelWrite(): void
    {
        $this->write("# my settings\nai:\n    # model note\n    default_reasoning: medium\n    # end note\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $result = $this->read();
        $this->assertStringContainsString('# my settings', $result);
        $this->assertStringContainsString('# model note', $result);
        $this->assertStringContainsString('# end note', $result);
    }

    // ── writeDefaultReasoning ──────────────────────────────────────────

    public function testReplacesActiveReasoningOnly(): void
    {
        // When a key exists only as a comment, the writer now inserts a
        // fresh active key instead of uncommenting.
        $this->write("ai:\n    default_model: deepseek/deepseek-v4-pro\n#   default_reasoning: low\n");
        $this->writer->writeDefaultReasoning('xhigh');

        $result = $this->read();
        // Commented line survives
        $this->assertStringContainsString('#   default_reasoning: low', $result);
        // A new active key is inserted
        $this->assertStringContainsString('    default_reasoning: xhigh', $result);

        $p = $this->parse();
        $this->assertSame('xhigh', $p['ai']['default_reasoning'] ?? null);
    }

    public function testInsertsReasoningWhenAbsent(): void
    {
        $this->write("ai:\n    default_model: deepseek/deepseek-v4-pro\n");
        $this->writer->writeDefaultReasoning('minimal');

        $p = $this->parse();
        $this->assertSame('minimal', $p['ai']['default_reasoning'] ?? null);
    }

    // ── writeFavoriteModels ────────────────────────────────────────────

    public function testWritesFavoriteModelsAsFlowSequence(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeFavoriteModels(['zai/glm-5.1', 'llama_cpp/flash']);

        $p = $this->parse();
        $this->assertSame(['zai/glm-5.1', 'llama_cpp/flash'], $p['ai']['favorite_models'] ?? null);
        $this->assertStringContainsString('favorite_models: [zai/glm-5.1, llama_cpp/flash]', $this->read());
    }

    public function testWritesEmptyFavoriteModels(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeFavoriteModels([]);

        $p = $this->parse();
        $this->assertSame([], $p['ai']['favorite_models'] ?? null);
        $this->assertStringContainsString('favorite_models: []', $this->read());
    }

    public function testReplacesActiveFavoriteModels(): void
    {
        $this->write("ai:\n    favorite_models: [old/model]\n");
        $this->writer->writeFavoriteModels(['new/model']);

        $p = $this->parse();
        $this->assertSame(['new/model'], $p['ai']['favorite_models'] ?? null);
    }

    public function testDoesNotUncommentFavoriteModels(): void
    {
        // Commented line should survive; a new active key is inserted.
        $this->write("ai:\n#    favorite_models: [old/model]\n");
        $this->writer->writeFavoriteModels(['new/model']);

        $result = $this->read();
        $this->assertStringContainsString('#    favorite_models: [old/model]', $result);
        $this->assertStringContainsString('favorite_models: [new/model]', $result);

        $p = $this->parse();
        $this->assertSame(['new/model'], $p['ai']['favorite_models'] ?? null);
    }

    public function testInsertsFavoriteModelsWhenAiSectionExists(): void
    {
        $this->write("ai:\n    default_model: zai/glm-5.1\n");
        $this->writer->writeFavoriteModels(['deepseek/deepseek-v4-pro']);

        $p = $this->parse();
        $this->assertSame(['deepseek/deepseek-v4-pro'], $p['ai']['favorite_models'] ?? null);
    }

    public function testAppendsAiSectionForFavoriteModels(): void
    {
        $this->write("tui:\n    theme: cyberpunk\n");
        $this->writer->writeFavoriteModels(['zai/glm-5.1']);

        $p = $this->parse();
        $this->assertSame(['zai/glm-5.1'], $p['ai']['favorite_models'] ?? null);
        $this->assertSame('cyberpunk', $p['tui']['theme'] ?? null);
    }

    // ── YAML quoting ───────────────────────────────────────────────────

    public function testQuotesColonAndHash(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('model:with:colons#hash');

        $result = $this->read();
        $this->assertStringContainsString("default_model: 'model:with:colons#hash'", $result);
    }

    public function testDoesNotQuoteNormalValues(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $this->assertStringNotContainsString("'zai/glm-5.1'", $this->read());
    }

    public function testQuotesEmptyValue(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('');

        $this->assertStringContainsString("default_model: ''", $this->read());
    }

    // ── Error ──────────────────────────────────────────────────────────

    public function testCreatesSparseHomeFileOnFirstScalarWrite(): void
    {
        $this->assertFileDoesNotExist($this->file);

        $this->writer->writeDefaultModel('zai/glm-5.1');

        $this->assertFileExists($this->file);
        $this->assertSame("ai:\n    default_model: zai/glm-5.1\n", $this->read());

        $p = $this->parse();
        $this->assertSame('zai/glm-5.1', $p['ai']['default_model'] ?? null);
    }

    public function testCreatesSparseHomeFileOnFirstListWrite(): void
    {
        $this->assertFileDoesNotExist($this->file);

        $this->writer->writeFavoriteModels(['zai/glm-5.1', 'deepseek/deepseek-v4-pro']);

        $this->assertFileExists($this->file);
        $this->assertSame(
            "ai:\n    favorite_models: [zai/glm-5.1, deepseek/deepseek-v4-pro]\n",
            $this->read(),
        );
        $p = $this->parse();
        $this->assertSame(['zai/glm-5.1', 'deepseek/deepseek-v4-pro'], $p['ai']['favorite_models'] ?? null);
    }

    public function testThrowsWhenExistingHomeFileIsUnreadable(): void
    {
        mkdir(\dirname($this->file), 0o755, true);
        file_put_contents($this->file, 'ai:
    default_model: old
');
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

    private function write(string $content): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($this->file, $content);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->file);
    }

    /** @return array<string, mixed> */
    private function parse(): array
    {
        return Yaml::parseFile($this->file) ?? [];
    }
}
