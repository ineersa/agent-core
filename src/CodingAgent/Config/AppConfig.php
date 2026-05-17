<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Resolved Hatfield configuration for the active project.
 *
 * Production DI constructor self-hydrates via AppConfigLoader, using
 * getcwd() as the project root. All public properties are readonly.
 */
final class AppConfig
{
    public readonly string $cwd;
    public readonly TuiConfig $tui;
    public readonly array $sessions;
    public readonly ?AiConfig $ai;
    public readonly array $raw;
    public readonly ?HatfieldModelCatalog $catalog;

    /**
     * Production constructor — loads and hydrates from Hatfield config layers.
     */
    public function __construct(
        AppConfigLoader $loader,
        AppResourceLocator $resources,
    ) {
        $this->cwd = getcwd() ?: '/';
        $data = $loader->load($resources->getDefaultsPath());
        $this->tui = TuiConfig::fromArray((array) ($data['tui'] ?? []));
        $this->sessions = (array) ($data['sessions'] ?? []);
        $this->ai = AiConfig::optionalFromArray($data);
        $this->catalog = null !== $this->ai ? new HatfieldModelCatalog($this->ai) : null;
        $this->raw = $data;
    }

    /**
     * @internal Test helper — hydrate from a pre-built config array.
     *
     * Uses Reflection to bypass the readonly constraint during test
     * construction.  Not part of the production API.
     */
    public static function fromArray(array $data): self
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        \Closure::bind(function () use ($data): void {
            $this->cwd = getcwd() ?: '/';
            $this->tui = TuiConfig::fromArray((array) ($data['tui'] ?? []));
            $this->sessions = (array) ($data['sessions'] ?? []);
            $ai = AiConfig::optionalFromArray($data);
            $this->ai = $ai;
            $this->catalog = null !== $ai ? new HatfieldModelCatalog($ai) : null;
            $this->raw = $data;
        }, null, self::class)->call($instance);

        return $instance;
    }
}
