<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Docs\BuiltinDocsValidator;

use function CastorTasks\project_root_dir;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/**
 * Deterministic documentation catalog and size validation.
 */
#[AsTask(name: 'docs:validate', description: 'Validate built-in docs catalog, package-safe links, and size limits')]
function docs_validate(): void
{
    $root = project_root_dir();
    $validator = new BuiltinDocsValidator();
    $errors = $validator->validate($root);

    if ([] !== $errors) {
        $message = "docs:validate failed:\n - ".implode("\n - ", $errors);
        throw new RuntimeException($message);
    }

    $entries = (new BuiltinDocsCatalog())->discover($root);
    echo sprintf(
        "docs:validate ok (%d built-in documents, max %d chars)\n",
        count($entries),
        BuiltinDocsCatalog::MAX_DOCUMENT_CHARS,
    );
    foreach ($entries as $entry) {
        echo sprintf("  - %s (%s)\n", $entry['id'], $entry['relativePath']);
    }
}
