<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: selected-doc content is part of the PHAR input fingerprint, and
 * Extension API package staging excludes the docs/ subtree so only catalog
 * materialization can introduce API docs.
 */
final class PharDocsStagingContractTest extends TestCase
{
    public function testSelectedDocContentChangeInvalidatesPharFingerprint(): void
    {
        $root = \dirname(__DIR__, 3);
        $helpers = $root.'/.castor/helpers.php';
        require_once $helpers;
        $this->assertTrue(\function_exists('CastorTasks\phar_input_fingerprint'));

        $settings = $root.'/docs/settings.md';
        $this->assertFileExists($settings);
        $original = (string) file_get_contents($settings);
        $before = \CastorTasks\phar_input_fingerprint($root);
        try {
            file_put_contents($settings, $original."\n<!-- fingerprint-probe -->\n");
            clearstatcache(true, $settings);
            $after = \CastorTasks\phar_input_fingerprint($root);
            $this->assertNotSame($before, $after, 'changing a selected built-in doc must change PHAR fingerprint');
        } finally {
            file_put_contents($settings, $original);
        }
        $restored = \CastorTasks\phar_input_fingerprint($root);
        $this->assertSame($before, $restored);
    }

    public function testExtensionApiDocsSubtreeIsExcludedFromPackageCopySemantics(): void
    {
        // Guard the staging command contract: rsync exclude docs/ then materialize catalog.
        $helpers = (string) file_get_contents(\dirname(__DIR__, 3).'/.castor/helpers.php');
        $this->assertStringContainsString('rsync -a --delete --exclude ', $helpers);
        $this->assertStringContainsString("'docs/'", $helpers);
        $this->assertStringContainsString('BuiltinDocsCatalog', $helpers);
    }

    public function testCatalogRelativePathsAreOnlyApprovedRoots(): void
    {
        $root = \dirname(__DIR__, 3);
        $entries = (new BuiltinDocsCatalog())->discover($root);
        $this->assertNotSame([], $entries);
        foreach ($entries as $entry) {
            $this->assertTrue(
                str_starts_with($entry['relativePath'], 'docs/')
                || str_starts_with($entry['relativePath'], '.hatfield/extensions/extension-api/docs/'),
                $entry['relativePath'],
            );
            $this->assertStringEndsWith('.md', $entry['relativePath']);
        }
    }
}
