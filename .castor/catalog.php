<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Ineersa\CodingAgent\Config\Ai\AiCatalogVersionGuard;

use function CastorTasks\project_root_dir;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/**
 * Fail when config/ai-catalog.yaml changed without a version: bump vs origin/main.
 * Soft-passes when origin/main / merge-base is unavailable (fresh clone).
 */
#[AsTask(name: 'catalog:version-check', description: 'Fail when ai-catalog.yaml changes without a version bump')]
function catalog_version_check(): void
{
    $root = project_root_dir();
    $result = (new AiCatalogVersionGuard($root))->check('origin/main');

    foreach ($result['notes'] as $note) {
        echo 'catalog:version-check note: '.$note."\n";
    }

    if (!$result['ok']) {
        throw new RuntimeException("catalog:version-check failed:\n - ".implode("\n - ", $result['errors']));
    }

    echo "catalog:version-check ok\n";
}
