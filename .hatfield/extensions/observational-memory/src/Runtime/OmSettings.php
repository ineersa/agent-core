<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Symfony\Component\Config\Definition\Processor;

final readonly class OmSettings
{
    public const string SETTINGS_KEY = 'observational_memory';

    public function __construct(public LibrarianSettingsDTO $librarian)
    {
    }

    public static function fromApi(ExtensionApiInterface $api): self
    {
        return self::fromArray($api->getSettings(self::SETTINGS_KEY));
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $value = (new Processor())->processConfiguration(new ObservationalMemoryConfiguration(), [$raw]);

        return new self(LibrarianSettingsProvider::fromArray($value['librarian']));
    }
}
