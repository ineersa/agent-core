<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use PHPUnit\Framework\TestCase;

class HatfieldModelCatalogTest extends TestCase
{
    public function testGetProvider(): void
    {
        $catalog = $this->createCatalog();

        $this->assertNotNull($catalog->getProvider('deepseek'));
        $this->assertNotNull($catalog->getProvider('llama_cpp'));
        $this->assertNotNull($catalog->getProvider('zai'));
        $this->assertNull($catalog->getProvider('nonexistent'));
    }

    public function testGetModelByString(): void
    {
        $catalog = $this->createCatalog();

        $model = $catalog->getModel('deepseek/deepseek-v4-pro');
        $this->assertNotNull($model);
        $this->assertSame('deepseek-v4-pro', $model->id);
        $this->assertSame('DeepSeek V4 Pro', $model->name);
    }

    public function testGetModelByReference(): void
    {
        $catalog = $this->createCatalog();
        $ref = AiModelReference::parse('zai/glm-5.1');

        $model = $catalog->getModel($ref);
        $this->assertNotNull($model);
        $this->assertSame('GLM 5.1', $model->name);
    }

    public function testGetModelReturnsNullForUnknownModel(): void
    {
        $catalog = $this->createCatalog();

        $this->assertNull($catalog->getModel('deepseek/nonexistent'));
    }

    public function testGetModelReturnsNullForUnknownProvider(): void
    {
        $catalog = $this->createCatalog();

        $this->assertNull($catalog->getModel('unknown/any-model'));
    }

    public function testGetModelReturnsNullForDisabledProvider(): void
    {
        $catalog = $this->createCatalog();

        // Disabled provider's models are not available
        $this->assertNull($catalog->getModel('disabled/hidden'));
    }

    public function testGetModelReturnsNullForInvalidReference(): void
    {
        $catalog = $this->createCatalog();

        // Invalid format returns null without throwing
        $this->assertNull($catalog->getModel('not-a-valid-ref'));
    }

    public function testRequireModelThrowsOnMissing(): void
    {
        $catalog = $this->createCatalog();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $catalog->requireModel('deepseek/nonexistent');
    }

    public function testRequireModelReturnsModel(): void
    {
        $catalog = $this->createCatalog();

        $model = $catalog->requireModel('llama_cpp/flash');
        $this->assertSame('flash', $model->id);
    }

    public function testIsAvailable(): void
    {
        $catalog = $this->createCatalog();

        $this->assertTrue($catalog->isAvailable('deepseek/deepseek-v4-pro'));
        $this->assertTrue($catalog->isAvailable('deepseek/deepseek-v4-flash'));
        $this->assertTrue($catalog->isAvailable('llama_cpp/flash'));
        $this->assertTrue($catalog->isAvailable('zai/glm-5.1'));
        $this->assertTrue($catalog->isAvailable('zai/glm-5v-turbo'));
        $this->assertFalse($catalog->isAvailable('deepseek/unknown-model'));
        $this->assertFalse($catalog->isAvailable('unknown/any-model'));
        $this->assertFalse($catalog->isAvailable('disabled/hidden'));
        $this->assertFalse($catalog->isAvailable('invalid'));
    }

    public function testAllModels(): void
    {
        $catalog = $this->createCatalog();
        $all = $catalog->allModels();

        $this->assertCount(5, $all, '5 models across 3 enabled providers');

        $refs = array_map(static fn (AiModelReference $r) => $r->toString(), $all);
        $this->assertContains('deepseek/deepseek-v4-pro', $refs);
        $this->assertContains('deepseek/deepseek-v4-flash', $refs);
        $this->assertContains('llama_cpp/flash', $refs);
        $this->assertContains('zai/glm-5.1', $refs);
        $this->assertContains('zai/glm-5v-turbo', $refs);
    }

    public function testAllModelsExcludesDisabledProvider(): void
    {
        $catalog = $this->createCatalog();
        $all = $catalog->allModels();

        $refs = array_map(static fn (AiModelReference $r) => $r->toString(), $all);
        $this->assertNotContains('disabled/hidden', $refs);
    }

    public function testDefaultModelReference(): void
    {
        $catalog = $this->createCatalog();

        $ref = $catalog->defaultModelReference();
        $this->assertNotNull($ref);
        $this->assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testDefaultModelReferenceNullWhenAbsent(): void
    {
        $emptyCatalog = new HatfieldModelCatalog(AiConfig::fromArray([]));
        $this->assertNull($emptyCatalog->defaultModelReference());
    }

    public function testFirstAvailableModel(): void
    {
        $catalog = $this->createCatalog();

        $first = $catalog->firstAvailableModel();
        $this->assertNotNull($first);
        // Providers are iterated in definition order; deepseek is first
        $this->assertSame('deepseek', $first->providerId);
    }

    public function testFirstAvailableModelNullWhenEmpty(): void
    {
        $emptyCatalog = new HatfieldModelCatalog(AiConfig::fromArray([]));
        $this->assertNull($emptyCatalog->firstAvailableModel());
    }

    public function testLlamaCppOnlyExposesListedModels(): void
    {
        $catalog = $this->createCatalog();

        // flash is listed and available
        $this->assertTrue($catalog->isAvailable('llama_cpp/flash'));

        // arbitrary/models are not available — even for llama.cpp
        $this->assertFalse($catalog->isAvailable('llama_cpp/arbitrary-model'));
        $this->assertFalse($catalog->isAvailable('llama_cpp/qwen-anything'));
        $this->assertNull($catalog->getModel('llama_cpp/mistral-7b'));
    }

    public function testConfigAccessor(): void
    {
        $catalog = $this->createCatalog();
        $config = $catalog->config();

        $this->assertSame('deepseek/deepseek-v4-pro', $config->defaultModel);
        $this->assertSame('medium', $config->defaultReasoning);
        $this->assertCount(4, $config->providers);
    }

    private function createCatalog(): HatfieldModelCatalog
    {
        $aiConfig = AiConfig::fromArray([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
                    'models' => [
                        'deepseek-v4-pro' => [
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 1000000,
                        ],
                        'deepseek-v4-flash' => [
                            'name' => 'DeepSeek V4 Flash',
                            'context_window' => 1000000,
                        ],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://192.168.2.38:8052/v1',
                    'api_key' => 'dummy',
                    'models' => [
                        'flash' => [
                            'name' => 'flash',
                            'context_window' => 200000,
                        ],
                    ],
                ],
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai/api/coding/paas/v4',
                    'models' => [
                        'glm-5.1' => [
                            'name' => 'GLM 5.1',
                            'context_window' => 200000,
                        ],
                        'glm-5v-turbo' => [
                            'name' => 'GLM 5V Turbo',
                            'context_window' => 200000,
                        ],
                    ],
                ],
                'disabled' => [
                    'type' => 'generic',
                    'enabled' => false,
                    'base_url' => 'https://disabled.example.com',
                    'models' => [
                        'hidden' => ['name' => 'Hidden Model'],
                    ],
                ],
            ],
        ]);

        return new HatfieldModelCatalog($aiConfig);
    }
}
