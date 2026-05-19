<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class HomeSettingsWriterTest extends TestCase
{
    private string $tmpDir;
    private HomeSettingsWriter $writer;
    private string $file;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/hatfield_writer_' . \bin2hex(\random_bytes(8));
        \mkdir($this->tmpDir . '/.hatfield', 0o755, true);
        $this->file = $this->tmpDir . '/.hatfield/settings.yaml';
        $pathResolver = new SettingsPathResolver('/app', $this->tmpDir);
        $this->writer = new HomeSettingsWriter($pathResolver);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    private function write(string $content): void
    {
        \file_put_contents($this->file, $content);
    }

    private function read(): string
    {
        return (string) \file_get_contents($this->file);
    }

    /** @return array<string, mixed> */
    private function parse(): array
    {
        return Yaml::parseFile($this->file) ?? [];
    }

    // ── writeDefaultModel ──────────────────────────────────────────────

    public function testReplacesActiveModel(): void
    {
        $this->write("ai:\n    default_model: old\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $p = $this->parse();
        self::assertSame('zai/glm-5.1', $p['ai']['default_model'] ?? null);
        self::assertSame('medium', $p['ai']['default_reasoning'] ?? null);
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
        self::assertStringContainsString('# default_model: old', $result);
        // A new active key is inserted
        self::assertStringContainsString('default_model: deepseek/deepseek-v4-pro', $result);
        // The active key is not prefixed with #
        self::assertStringNotContainsString('# default_model: deepseek', $result);

        $p = $this->parse();
        self::assertSame('deepseek/deepseek-v4-pro', $p['ai']['default_model'] ?? null);
    }

    public function testInsertsModelWhenAiSectionExists(): void
    {
        $this->write("ai:\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $p = $this->parse();
        self::assertSame('zai/glm-5.1', $p['ai']['default_model'] ?? null);
    }

    public function testAppendsAiSectionForModel(): void
    {
        $this->write("tui:\n    theme: cyberpunk\n");
        $this->writer->writeDefaultModel('llama_cpp/flash');

        $p = $this->parse();
        self::assertSame('llama_cpp/flash', $p['ai']['default_model'] ?? null);
        self::assertSame('cyberpunk', $p['tui']['theme'] ?? null);
    }

    public function testPreservesCommentsOnModelWrite(): void
    {
        $this->write("# my settings\nai:\n    # model note\n    default_reasoning: medium\n    # end note\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        $result = $this->read();
        self::assertStringContainsString('# my settings', $result);
        self::assertStringContainsString('# model note', $result);
        self::assertStringContainsString('# end note', $result);
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
        self::assertStringContainsString('#   default_reasoning: low', $result);
        // A new active key is inserted
        self::assertStringContainsString('    default_reasoning: xhigh', $result);

        $p = $this->parse();
        self::assertSame('xhigh', $p['ai']['default_reasoning'] ?? null);
    }

    public function testInsertsReasoningWhenAbsent(): void
    {
        $this->write("ai:\n    default_model: deepseek/deepseek-v4-pro\n");
        $this->writer->writeDefaultReasoning('minimal');

        $p = $this->parse();
        self::assertSame('minimal', $p['ai']['default_reasoning'] ?? null);
    }

    // ── writeFavoriteModels ────────────────────────────────────────────

    public function testWritesFavoriteModelsAsFlowSequence(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeFavoriteModels(['zai/glm-5.1', 'llama_cpp/flash']);

        $p = $this->parse();
        self::assertSame(['zai/glm-5.1', 'llama_cpp/flash'], $p['ai']['favorite_models'] ?? null);
        self::assertStringContainsString('favorite_models: [zai/glm-5.1, llama_cpp/flash]', $this->read());
    }

    public function testWritesEmptyFavoriteModels(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeFavoriteModels([]);

        $p = $this->parse();
        self::assertSame([], $p['ai']['favorite_models'] ?? null);
        self::assertStringContainsString('favorite_models: []', $this->read());
    }

    public function testReplacesActiveFavoriteModels(): void
    {
        $this->write("ai:\n    favorite_models: [old/model]\n");
        $this->writer->writeFavoriteModels(['new/model']);

        $p = $this->parse();
        self::assertSame(['new/model'], $p['ai']['favorite_models'] ?? null);
    }

    public function testDoesNotUncommentFavoriteModels(): void
    {
        // Commented line should survive; a new active key is inserted.
        $this->write("ai:\n#    favorite_models: [old/model]\n");
        $this->writer->writeFavoriteModels(['new/model']);

        $result = $this->read();
        self::assertStringContainsString('#    favorite_models: [old/model]', $result);
        self::assertStringContainsString('favorite_models: [new/model]', $result);

        $p = $this->parse();
        self::assertSame(['new/model'], $p['ai']['favorite_models'] ?? null);
    }

    public function testInsertsFavoriteModelsWhenAiSectionExists(): void
    {
        $this->write("ai:\n    default_model: zai/glm-5.1\n");
        $this->writer->writeFavoriteModels(['deepseek/deepseek-v4-pro']);

        $p = $this->parse();
        self::assertSame(['deepseek/deepseek-v4-pro'], $p['ai']['favorite_models'] ?? null);
    }

    public function testAppendsAiSectionForFavoriteModels(): void
    {
        $this->write("tui:\n    theme: cyberpunk\n");
        $this->writer->writeFavoriteModels(['zai/glm-5.1']);

        $p = $this->parse();
        self::assertSame(['zai/glm-5.1'], $p['ai']['favorite_models'] ?? null);
        self::assertSame('cyberpunk', $p['tui']['theme'] ?? null);
    }

    // ── YAML quoting ───────────────────────────────────────────────────

    public function testQuotesColonAndHash(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('model:with:colons#hash');

        $result = $this->read();
        self::assertStringContainsString("default_model: 'model:with:colons#hash'", $result);
    }

    public function testDoesNotQuoteNormalValues(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('zai/glm-5.1');

        self::assertStringNotContainsString("'zai/glm-5.1'", $this->read());
    }

    public function testQuotesEmptyValue(): void
    {
        $this->write("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel('');

        self::assertStringContainsString("default_model: ''", $this->read());
    }

    // ── Error ──────────────────────────────────────────────────────────

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read');

        $writer = new HomeSettingsWriter(new SettingsPathResolver('/app', '/nonexistent'));
        $writer->writeDefaultModel('x');
    }

    // ── Helper ─────────────────────────────────────────────────────────

    private function rmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;

            if (\is_dir($path)) {
                $this->rmdir($path);
            } else {
                \chmod($path, 0o644);
                \unlink($path);
            }
        }

        \rmdir($dir);
    }
}
