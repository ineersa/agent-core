<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Optional, side-effect-free configuration discovery, without calling register().
 * The tree root names the extensions.settings key. Native node info/defaults
 * describe the form; attribute('hatfield.model_picker', true) requests a picker
 * of configured generation models. The extension owns all validation.
 */
interface ConfigurableExtensionInterface extends HatfieldExtensionInterface
{
    public function getConfiguration(): ConfigurationInterface;
}
