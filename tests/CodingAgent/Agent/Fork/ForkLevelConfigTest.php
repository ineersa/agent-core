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
        self::assertSame('junior', ForkLevelEnum::Junior->value);
        self::assertSame('middle', ForkLevelEnum::Middle->value);
        self::assertSame('senior', ForkLevelEnum::Senior->value);
    }

    public function testFromStringOrNull(): void
    {
        self::assertSame(ForkLevelEnum::Junior, ForkLevelEnum::fromStringOrNull('junior'));
        self::assertSame(ForkLevelEnum::Middle, ForkLevelEnum::fromStringOrNull('middle'));
        self::assertSame(ForkLevelEnum::Senior, ForkLevelEnum::fromStringOrNull('senior'));
        self::assertNull(ForkLevelEnum::fromStringOrNull('unknown'));
        self::assertNull(ForkLevelEnum::fromStringOrNull(null));
    }

    public function testDefault(): void
    {
        self::assertSame(ForkLevelEnum::Middle, ForkLevelEnum::default());
    }

    // ── ForkLevelConfigDTO ───────────────────────────────────────────────

    public function testLevelConfigDefaults(): void
    {
        $junior = ForkLevelConfigDTO::juniorDefault();
        self::assertNull($junior->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $junior->description);

        $middle = ForkLevelConfigDTO::middleDefault();
        self::assertNull($middle->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $middle->description);

        $senior = ForkLevelConfigDTO::seniorDefault();
        self::assertNull($senior->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_SENIOR, $senior->description);
    }

    public function testLevelConfigCustom(): void
    {
        $config = new ForkLevelConfigDTO(
            model: 'openai/gpt-4',
            description: 'Custom description',
        );

        self::assertSame('openai/gpt-4', $config->model);
        self::assertSame('Custom description', $config->description);
    }

    // ── ForksConfigDTO ───────────────────────────────────────────────────

    public function testForksConfigDefaults(): void
    {
        $config = new ForksConfigDTO();

        self::assertSame(1, $config->maxConcurrent);
        self::assertSame(ForkLevelEnum::Middle, $config->defaultLevel);
        self::assertSame([], $config->levels);
    }

    public function testForksConfigDefaultInstance(): void
    {
        $config = ForksConfigDTO::defaultInstance();

        self::assertSame(1, $config->maxConcurrent);
        self::assertSame(ForkLevelEnum::Middle, $config->defaultLevel);
        self::assertCount(3, $config->levels);
        self::assertArrayHasKey('junior', $config->levels);
        self::assertArrayHasKey('middle', $config->levels);
        self::assertArrayHasKey('senior', $config->levels);
    }

    public function testLevelConfigFallsBackToDefaults(): void
    {
        $config = new ForksConfigDTO();

        // With empty levels, levelConfig falls back to built-in defaults.
        $junior = $config->levelConfig(ForkLevelEnum::Junior);
        self::assertNull($junior->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $junior->description);

        $middle = $config->levelConfig(ForkLevelEnum::Middle);
        self::assertNull($middle->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $middle->description);

        $senior = $config->levelConfig(ForkLevelEnum::Senior);
        self::assertNull($senior->model);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_SENIOR, $senior->description);
    }

    public function testLevelConfigReturnsConfigured(): void
    {
        $levels = [
            'junior' => new ForkLevelConfigDTO(model: 'fast/model'),
            'middle' => new ForkLevelConfigDTO(model: null, description: 'Custom middle'),
        ];

        $config = new ForksConfigDTO(levels: $levels);

        $junior = $config->levelConfig(ForkLevelEnum::Junior);
        self::assertSame('fast/model', $junior->model);

        $middle = $config->levelConfig(ForkLevelEnum::Middle);
        self::assertNull($middle->model);
        self::assertSame('Custom middle', $middle->description);
    }

    // ── ForkConfigResolver ───────────────────────────────────────────────

    public function testResolverDefaultLevel(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(null);

        self::assertSame(ForkLevelEnum::Middle, $resolved->level);
        self::assertNull($resolved->resolvedModel);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_MIDDLE, $resolved->levelConfig->description);
    }

    public function testResolverRequestedLevel(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(ForkLevelEnum::Senior);

        self::assertSame(ForkLevelEnum::Senior, $resolved->level);
        self::assertNull($resolved->resolvedModel);
    }

    public function testResolverConfiguredLevelModelWins(): void
    {
        $levels = [
            'senior' => new ForkLevelConfigDTO(model: 'openai/gpt-4'),
        ];
        $config = new ForksConfigDTO(levels: $levels);
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(ForkLevelEnum::Senior);

        self::assertSame(ForkLevelEnum::Senior, $resolved->level);
        self::assertSame('openai/gpt-4', $resolved->resolvedModel);
    }

    public function testResolverNullModelFallsBackToSession(): void
    {
        $config = ForksConfigDTO::defaultInstance();
        $resolver = new ForkConfigResolver($config);

        // Middle default has null model → resolvedModel is null (session fallback).
        $resolved = $resolver->resolve(null);

        self::assertNull($resolved->resolvedModel);
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

        self::assertSame(ForkLevelEnum::Senior, $resolved->level);
        self::assertSame('openai/gpt-4', $resolved->resolvedModel);
    }

    public function testResolverDefaultLevelModelFallback(): void
    {
        // Default level Middle has no configured model in levels → null model.
        $config = new ForksConfigDTO();
        $resolver = new ForkConfigResolver($config);

        $resolved = $resolver->resolve(null);

        self::assertSame(ForkLevelEnum::Middle, $resolved->level);
        self::assertNull($resolved->resolvedModel);
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

        self::assertSame(ForkLevelEnum::Junior, $resolved->level);
        self::assertNull($resolved->resolvedModel);
        self::assertSame(ForkLevelConfigDTO::DEFAULT_DESCRIPTION_JUNIOR, $resolved->levelConfig->description);
    }
}
