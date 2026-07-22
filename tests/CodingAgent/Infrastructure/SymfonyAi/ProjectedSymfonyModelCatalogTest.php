<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;

class ProjectedSymfonyModelCatalogTest extends TestCase
{
    public function testRegisteredModelReturnsCompletionsModel(): void
    {
        $catalog = $this->createCatalog();

        $model = $catalog->getModel('deepseek-v4-pro');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame('deepseek-v4-pro', $model->getName());
    }

    public function testUnknownModelThrowsModelNotFoundException(): void
    {
        $catalog = $this->createCatalog();

        $this->expectException(ModelNotFoundException::class);
        $catalog->getModel('nonexistent-model');
    }

    public function testBaselineCapabilitiesPresent(): void
    {
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('deepseek-v4-pro');

        // All projected models get these baseline capabilities.
        $this->assertTrue($model->supports(Capability::INPUT_MESSAGES));
        $this->assertTrue($model->supports(Capability::OUTPUT_TEXT));
        $this->assertTrue($model->supports(Capability::OUTPUT_STREAMING));
    }

    public function testToolCallingCapabilityWhenEnabled(): void
    {
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('deepseek-v4-pro');

        $this->assertTrue($model->supports(Capability::TOOL_CALLING));
    }

    public function testToolCallingCapabilityWhenDisabled(): void
    {
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('flash');

        $this->assertFalse($model->supports(Capability::TOOL_CALLING));
    }

    public function testThinkingCapabilityWhenReasoningEnabled(): void
    {
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('deepseek-v4-pro');

        $this->assertTrue($model->supports(Capability::THINKING));
    }

    public function testThinkingCapabilityWhenReasoningDisabled(): void
    {
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('deepseek-v4-flash');

        $this->assertFalse($model->supports(Capability::THINKING));
    }

    public function testGetModelsReturnsAllRegistered(): void
    {
        $catalog = $this->createCatalog();

        $all = $catalog->getModels();

        $this->assertCount(4, $all);
        $this->assertArrayHasKey('deepseek-v4-pro', $all);
        $this->assertArrayHasKey('deepseek-v4-flash', $all);
        $this->assertArrayHasKey('flash', $all);
        $this->assertArrayHasKey('glm-5.1', $all);
    }

    public function testEachRegisteredModelUsesCompletionsModelClass(): void
    {
        $catalog = $this->createCatalog();

        foreach ($catalog->getModels() as $modelEntry) {
            $this->assertSame(CompletionsModel::class, $modelEntry['class']);
        }
    }

    public function testEachModelCapabilitiesAreListOfCapabilityEnums(): void
    {
        $catalog = $this->createCatalog();

        foreach ($catalog->getModels() as $modelEntry) {
            $capabilities = $modelEntry['capabilities'];
            $this->assertIsArray($capabilities);
            foreach ($capabilities as $cap) {
                $this->assertInstanceOf(Capability::class, $cap);
            }
        }
    }

    public function testEmptyCatalog(): void
    {
        $catalog = new ProjectedSymfonyModelCatalog([]);

        $this->assertSame([], $catalog->getModels());

        $this->expectException(ModelNotFoundException::class);
        $catalog->getModel('any-model');
    }

    public function testModelWithAllFeaturesEnabled(): void
    {
        $def = new AiModelDefinition(
            id: 'full-model',
            toolCalling: true,
            reasoning: true,
        );

        $catalog = new ProjectedSymfonyModelCatalog(['full-model' => $def]);
        $model = $catalog->getModel('full-model');

        $this->assertTrue($model->supports(Capability::INPUT_MESSAGES));
        $this->assertTrue($model->supports(Capability::OUTPUT_TEXT));
        $this->assertTrue($model->supports(Capability::OUTPUT_STREAMING));
        $this->assertTrue($model->supports(Capability::TOOL_CALLING));
        $this->assertTrue($model->supports(Capability::THINKING));
    }

    public function testModelWithAllFeaturesDisabled(): void
    {
        $def = new AiModelDefinition(
            id: 'minimal-model',
            toolCalling: false,
            reasoning: false,
        );

        $catalog = new ProjectedSymfonyModelCatalog(['minimal-model' => $def]);
        $model = $catalog->getModel('minimal-model');

        $this->assertTrue($model->supports(Capability::INPUT_MESSAGES));
        $this->assertTrue($model->supports(Capability::OUTPUT_TEXT));
        $this->assertTrue($model->supports(Capability::OUTPUT_STREAMING));
        $this->assertFalse($model->supports(Capability::TOOL_CALLING));
        $this->assertFalse($model->supports(Capability::THINKING));
    }

    public function testZaiStreamingToolModel(): void
    {
        // z.ai glm-5.1: tool_calling + reasoning both true
        $catalog = $this->createCatalog();
        $model = $catalog->getModel('glm-5.1');

        $this->assertTrue($model->supports(Capability::TOOL_CALLING));
        $this->assertTrue($model->supports(Capability::THINKING));
        $this->assertTrue($model->supports(Capability::OUTPUT_STREAMING));
    }

    // ── Provider-qualified model names ────────────────────

    /**
     * The catalog must accept "provider/model" qualified names
     * by stripping the provider prefix and looking up the bare name.
     * This handles the case where a provider-qualified name reaches
     * the catalog without being pre-stripped by upstream components.
     */
    public function testProviderQualifiedModelNameIsResolvedToBareModel(): void
    {
        $catalog = $this->createCatalog();

        $model = $catalog->getModel('llama_cpp/flash');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        // The resolved model should use the bare name after catalog lookup.
        // AbstractModelCatalog::getModel() returns a model whose name
        // comes from the original $modelName param, which might be
        // "llama_cpp/flash".  The key assertion is: no exception.
        $this->assertNotEmpty($model->getName());
    }

    public function testProviderQualifiedModelNameWithUnknownBareModelFails(): void
    {
        $catalog = $this->createCatalog();

        $this->expectException(ModelNotFoundException::class);
        $catalog->getModel('llama_cpp/unknown-model');
    }

    public function testSizeVariantsWorkWithProviderPrefix(): void
    {
        // "deepseek/deepseek-v4-pro:23b" — the ":" size variant should
        // still work when preceded by a provider prefix.
        $catalog = $this->createCatalog();

        $model = $catalog->getModel('deepseek/deepseek-v4-pro:23b');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertNotEmpty($model->getName());
    }

    public function testCustomModelClassProducesCodexModel(): void
    {
        $def = new AiModelDefinition(
            id: 'gpt-5.5',
            toolCalling: true,
            reasoning: true,
        );

        $catalog = new ProjectedSymfonyModelCatalog(
            ['gpt-5.5' => $def],
            CodexModel::class,
        );

        $model = $catalog->getModel('gpt-5.5');

        $this->assertInstanceOf(CodexModel::class, $model);
        $this->assertSame('gpt-5.5', $model->getName());
        $this->assertTrue($model->supports(Capability::TOOL_CALLING));
        $this->assertTrue($model->supports(Capability::THINKING));
    }

    // — helpers —

    private function createCatalog(): ProjectedSymfonyModelCatalog
    {
        return new ProjectedSymfonyModelCatalog([
            'deepseek-v4-pro' => new AiModelDefinition(
                id: 'deepseek-v4-pro',
                name: 'DeepSeek V4 Pro',
                contextWindow: 1_000_000,
                maxTokens: 131_072,
                input: ['text'],
                toolCalling: true,
                reasoning: true,
                thinkingLevelMap: [
                    'minimal' => 'low',
                    'low' => 'low',
                    'medium' => 'medium',
                    'high' => 'high',
                    'xhigh' => 'high',
                ],
            ),
            'deepseek-v4-flash' => new AiModelDefinition(
                id: 'deepseek-v4-flash',
                name: 'DeepSeek V4 Flash',
                contextWindow: 256_000,
                maxTokens: 8_192,
                input: ['text'],
                toolCalling: true,
                reasoning: false,
            ),
            'flash' => new AiModelDefinition(
                id: 'flash',
                name: 'flash',
                contextWindow: 200_000,
                maxTokens: 65_536,
                input: ['text', 'image'],
                toolCalling: false,
                reasoning: false,
            ),
            'glm-5.1' => new AiModelDefinition(
                id: 'glm-5.1',
                name: 'GLM 5.1',
                contextWindow: 200_000,
                maxTokens: 131_072,
                input: ['text'],
                toolCalling: true,
                reasoning: true,
                thinkingLevelMap: [
                    'minimal' => 'enabled',
                    'low' => 'enabled',
                    'medium' => 'enabled',
                    'high' => 'enabled',
                    'xhigh' => 'enabled',
                ],
            ),
        ]);
    }
}
