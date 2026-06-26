<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use PHPUnit\Framework\TestCase;

class HatfieldModelCatalogTest extends TestCase
{
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

    public function testGetProvider(): void
    {
        $catalog = $this->createCatalog();

        self::assertNotNull($catalog->getProvider('deepseek'));
        self::assertNotNull($catalog->getProvider('llama_cpp'));
        self::assertNotNull($catalog->getProvider('zai'));
        self::assertNull($catalog->getProvider('nonexistent'));
    }

    public function testGetModelByString(): void
    {
        $catalog = $this->createCatalog();

        $model = $catalog->getModel('deepseek/deepseek-v4-pro');
        self::assertNotNull($model);
        self::assertSame('deepseek-v4-pro', $model->id);
        self::assertSame('DeepSeek V4 Pro', $model->name);
    }

    public function testGetModelByReference(): void
    {
        $catalog = $this->createCatalog();
        $ref = AiModelReference::parse('zai/glm-5.1');

        $model = $catalog->getModel($ref);
        self::assertNotNull($model);
        self::assertSame('GLM 5.1', $model->name);
    }

    public function testGetModelReturnsNullForUnknownModel(): void
    {
        $catalog = $this->createCatalog();

        self::assertNull($catalog->getModel('deepseek/nonexistent'));
    }

    public function testGetModelReturnsNullForUnknownProvider(): void
    {
        $catalog = $this->createCatalog();

        self::assertNull($catalog->getModel('unknown/any-model'));
    }

    public function testGetModelReturnsNullForDisabledProvider(): void
    {
        $catalog = $this->createCatalog();

        // Disabled provider's models are not available
        self::assertNull($catalog->getModel('disabled/hidden'));
    }

    public function testGetModelReturnsNullForInvalidReference(): void
    {
        $catalog = $this->createCatalog();

        // Invalid format returns null without throwing
        self::assertNull($catalog->getModel('not-a-valid-ref'));
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
        self::assertSame('flash', $model->id);
    }

    public function testIsAvailable(): void
    {
        $catalog = $this->createCatalog();

        self::assertTrue($catalog->isAvailable('deepseek/deepseek-v4-pro'));
        self::assertTrue($catalog->isAvailable('deepseek/deepseek-v4-flash'));
        self::assertTrue($catalog->isAvailable('llama_cpp/flash'));
        self::assertTrue($catalog->isAvailable('zai/glm-5.1'));
        self::assertTrue($catalog->isAvailable('zai/glm-5v-turbo'));
        self::assertFalse($catalog->isAvailable('deepseek/unknown-model'));
        self::assertFalse($catalog->isAvailable('unknown/any-model'));
        self::assertFalse($catalog->isAvailable('disabled/hidden'));
        self::assertFalse($catalog->isAvailable('invalid'));
    }

    public function testAllModels(): void
    {
        $catalog = $this->createCatalog();
        $all = $catalog->allModels();

        self::assertCount(5, $all, '5 models across 3 enabled providers');

        $refs = array_map(static fn (AiModelReference $r) => $r->toString(), $all);
        self::assertContains('deepseek/deepseek-v4-pro', $refs);
        self::assertContains('deepseek/deepseek-v4-flash', $refs);
        self::assertContains('llama_cpp/flash', $refs);
        self::assertContains('zai/glm-5.1', $refs);
        self::assertContains('zai/glm-5v-turbo', $refs);
    }

    public function testAllModelsExcludesDisabledProvider(): void
    {
        $catalog = $this->createCatalog();
        $all = $catalog->allModels();

        $refs = array_map(static fn (AiModelReference $r) => $r->toString(), $all);
        self::assertNotContains('disabled/hidden', $refs);
    }

    public function testDefaultModelReference(): void
    {
        $catalog = $this->createCatalog();

        $ref = $catalog->defaultModelReference();
        self::assertNotNull($ref);
        self::assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testDefaultModelReferenceNullWhenAbsent(): void
    {
        $emptyCatalog = new HatfieldModelCatalog(AiConfig::fromArray([]));
        self::assertNull($emptyCatalog->defaultModelReference());
    }

    public function testFirstAvailableModel(): void
    {
        $catalog = $this->createCatalog();

        $first = $catalog->firstAvailableModel();
        self::assertNotNull($first);
        // Providers are iterated in definition order; deepseek is first
        self::assertSame('deepseek', $first->providerId);
    }

    public function testFirstAvailableModelNullWhenEmpty(): void
    {
        $emptyCatalog = new HatfieldModelCatalog(AiConfig::fromArray([]));
        self::assertNull($emptyCatalog->firstAvailableModel());
    }

    public function testLlamaCppOnlyExposesListedModels(): void
    {
        $catalog = $this->createCatalog();

        // flash is listed and available
        self::assertTrue($catalog->isAvailable('llama_cpp/flash'));

        // arbitrary/models are not available — even for llama.cpp
        self::assertFalse($catalog->isAvailable('llama_cpp/arbitrary-model'));
        self::assertFalse($catalog->isAvailable('llama_cpp/qwen-anything'));
        self::assertNull($catalog->getModel('llama_cpp/mistral-7b'));
    }

    public function testConfigAccessor(): void
    {
        $catalog = $this->createCatalog();
        $config = $catalog->config();

        self::assertSame('deepseek/deepseek-v4-pro', $config->defaultModel);
        self::assertSame('medium', $config->defaultReasoning);
        self::assertCount(4, $config->providers);
    }
}
