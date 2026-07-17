<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Container wiring regression for SettingsValueResolver.
 *
 * Thesis: production must inject a strict PropertyAccessor (THROW_ON_INVALID_INDEX).
 * FrameworkBundle's shared non-strict @property_accessor treats missing array
 * indices as readable null, which makes every path look Project-sourced and
 * hides true missing paths. This test boots the real container service.
 */
final class SettingsValueResolverTest extends IsolatedKernelTestCase
{
    public function testContainerResolverUsesStrictMissingIndexSemantics(): void
    {
        /** @var SettingsValueResolver $resolver */
        $resolver = self::getContainer()->get('test.settings_value_resolver');

        $settings = new SettingsResolutionDTO(
            defaultsRaw: [
                'tui' => [
                    'theme' => 'cyberpunk',
                ],
            ],
            userRaw: [],
            projectRaw: [],
            effective: [
                'tui' => [
                    'theme' => 'cyberpunk',
                ],
            ],
        );

        $missing = $resolver->resolve($settings, 'no.such.path');
        $this->assertFalse($missing->exists, 'Missing path must not be treated as readable under non-strict PropertyAccessor');
        $this->assertNull($missing->layer);

        $theme = $resolver->resolve($settings, 'tui.theme');
        $this->assertTrue($theme->exists);
        $this->assertSame('cyberpunk', $theme->value);
        $this->assertSame(
            SettingsLayerEnum::Defaults,
            $theme->layer,
            'Defaults-only path must not falsely rank as Project under non-strict isReadable()',
        );
    }
}
