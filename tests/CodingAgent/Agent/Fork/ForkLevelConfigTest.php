<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkResolvedConfigDTO;
use Ineersa\CodingAgent\Config\ForkLevelConfigDTO;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for fork level/config DTOs, default values, and resolver resolution.
 *
 * Test thesis:
 *   - ForkLevelEnum has correct cases, fromStringOrNull parsing, and default.
 *   - ForkLevelConfigDTO defaults are sensible and match documented descriptions.
 *   - ForksConfigDTO default instance has all three levels populated.
 *   - ForkConfigResolver resolves defaults correctly and applies level/model overrides.
 */
#[CoversClass(ForkLevelEnum::class)]
#[CoversClass(ForkLevelConfigDTO::class)]
#[CoversClass(ForksConfigDTO::class)]
#[CoversClass(ForkConfigResolver::class)]
#[CoversClass(ForkResolvedConfigDTO::class)]
final class ForkLevelConfigTest extends TestCase
{
    // ── ForkLevelEnum ────────────────────────────────────────────────────

    public function testEnumCases(): void
    {
        $this->assertSame('junior', ForkLevelEnum::Junior->value);
        $this->assertSame('middle', ForkLevelEnum::Middle->value);
        $this->assertSame('senior', ForkLevelEnum::Senior->value);
    }

    public function testFromStringOrNull(): void
    {
        $this->assertSame(ForkLevelEnum::Junior, ForkLevelEnum::fromStringOrNull('junior'));
        $this->assertSame(ForkLevelEnum::Middle, ForkLevelEnum::fromStringOrNull('middle'));
        $this->assertSame(ForkLevelEnum::Senior, ForkLevelEnum::fromStringOrNull('senior'));
        $this->assertNull(ForkLevelEnum::fromStringOrNull('unknown'));
        $this->assertNull(ForkLevelEnum::fromStringOrNull(null));
    }

    public function testDefault(): void
    {
        $this->assertSame(ForkLevelEnum::Middle, ForkLevelEnum::default());
    }

    // ── ForkLevelConfigDTO ───────────────────────────────────────────────

    public function testLevelConfigDefaults(): void
    {
        $junior = ForkLevelConfigDTO::juniorDefault();
        $this->assertNull($junior->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $junior->description);

        $middle = ForkLevelConfigDTO::middleDefault();
        $this->assertNull($middle->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $middle->description);

        $senior = ForkLevelConfigDTO::seniorDefault();
        $this->assertNull($senior->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_SENIOR, $senior->description);
    }

    public function testLevelConfigCustom(): void
    {
        $config = new ForkLevelConfigDTO(
            model: 'openai/gpt-4',
            description: 'Custom description',
        );

        $this->assertSame('openai/gpt-4', $config->model);
        $this->assertSame('Custom description', $config->description);
    }

    // ── ForksConfigDTO ───────────────────────────────────────────────────

    public function testForksConfigDefaults(): void
    {
        $config = new ForksConfigDTO();

        $this->assertSame(1, $config->maxConcurrent);
        $this->assertSame(ForkLevelEnum::Middle, $config->defaultLevel);
        $this->assertSame([], $config->levels);
    }

    public function testForksConfigDefaultInstance(): void
    {
        $config = ForksConfigDTO::defaultInstance();

        $this->assertSame(1, $config->maxConcurrent);
        $this->assertSame(ForkLevelEnum::Middle, $config->defaultLevel);
        $this->assertCount(3, $config->levels);
        $this->assertArrayHasKey('junior', $config->levels);
        $this->assertArrayHasKey('middle', $config->levels);
        $this->assertArrayHasKey('senior', $config->levels);
    }

    public function testLevelConfigFallsBackToDefaults(): void
    {
        $config = new ForksConfigDTO();

        // With empty levels, levelConfig falls back to built-in defaults.
        $junior = $config->levelConfig(ForkLevelEnum::Junior);
        $this->assertNull($junior->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $junior->description);

        $middle = $config->levelConfig(ForkLevelEnum::Middle);
        $this->assertNull($middle->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $middle->description);

        $senior = $config->levelConfig(ForkLevelEnum::Senior);
        $this->assertNull($senior->model);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_SENIOR, $senior->description);
    }

    public function testLevelConfigReturnsConfigured(): void
    {
        $levels = [
            'junior' => new ForkLevelConfigDTO(model: 'fast/model'),
            'middle' => new ForkLevelConfigDTO(model: null, description: 'Custom middle'),
        ];

        $config = new ForksConfigDTO(levels: $levels);

        $junior = $config->levelConfig(ForkLevelEnum::Junior);
        $this->assertSame('fast/model', $junior->model);

        $middle = $config->levelConfig(ForkLevelEnum::Middle);
        $this->assertNull($middle->model);
        $this->assertSame('Custom middle', $middle->description);
    }

    // ── ForkConfigResolver ───────────────────────────────────────────────

    public function testResolverDefaultLevel(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(null);

        $this->assertSame(ForkLevelEnum::Middle, $resolved->level);
        $this->assertNull($resolved->resolvedModel);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $resolved->levelConfig->description);
    }

    public function testResolverRequestedLevel(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(ForkLevelEnum::Senior);

        $this->assertSame(ForkLevelEnum::Senior, $resolved->level);
        $this->assertNull($resolved->resolvedModel);
    }

    public function testResolverConfiguredLevelModelWins(): void
    {
        $levels = [
            'senior' => new ForkLevelConfigDTO(model: 'openai/gpt-4'),
        ];
        $config = new ForksConfigDTO(levels: $levels);
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(ForkLevelEnum::Senior);

        $this->assertSame(ForkLevelEnum::Senior, $resolved->level);
        $this->assertSame('openai/gpt-4', $resolved->resolvedModel);
    }

    public function testResolverNullModelFallsBackToSession(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        // Middle default has null model → resolvedModel is null (session fallback).
        $resolved = $resolver->resolve(null);

        $this->assertNull($resolved->resolvedModel);
    }

    public function testResolverCustomDefaultLevel(): void
    {
        $config = new ForksConfigDTO(
            defaultLevel: ForkLevelEnum::Senior,
            levels: [
                'senior' => new ForkLevelConfigDTO(model: 'openai/gpt-4'),
            ],
        );
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(null);

        $this->assertSame(ForkLevelEnum::Senior, $resolved->level);
        $this->assertSame('openai/gpt-4', $resolved->resolvedModel);
    }

    public function testResolverDefaultLevelModelFallback(): void
    {
        // Default level Middle has no configured model in levels → null model.
        $config = new ForksConfigDTO();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(null);

        $this->assertSame(ForkLevelEnum::Middle, $resolved->level);
        $this->assertNull($resolved->resolvedModel);
    }

    public function testResolverUnconfiguredLevelFallsBackToDefaults(): void
    {
        // No 'junior' level configured.
        $config = new ForksConfigDTO(
            defaultLevel: ForkLevelEnum::Middle,
            levels: ['senior' => new ForkLevelConfigDTO()],
        );
        $resolver = new ForkConfigResolver($config);

        // Even though requested, junior falls back to built-in defaults.
        $resolved = $resolver->resolve(ForkLevelEnum::Junior);

        $this->assertSame(ForkLevelEnum::Junior, $resolved->level);
        $this->assertNull($resolved->resolvedModel);
        $this->assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $resolved->levelConfig->description);
    }
}
