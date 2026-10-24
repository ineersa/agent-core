<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\Exception\ProvidedExtensionsCatalogException;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Tests\Support\ExtensionsSetupTestSupport;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class ProvidedExtensionsCatalogTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testBundledCatalogListsBuiltinAndPinnedInstallablePackages(): void
    {
        $catalog = new ProvidedExtensionsCatalog(
            (new AppResourceLocator(ProjectDir::get()))->getProvidedExtensionsCatalogPath(),
            ExtensionsSetupTestSupport::createSerializer(),
            ExtensionsSetupTestSupport::createValidator(),
        );

        $all = $catalog->all();
        $this->assertCount(4, $all);

        $byId = [];
        foreach ($all as $package) {
            $byId[$package->id] = $package;
        }

        $this->assertTrue($byId['safe-guard']->builtin);
        $this->assertNull($byId['safe-guard']->package);
        $this->assertSame('safe_guard', $byId['safe-guard']->settingsKey);
        $this->assertNotEmpty($byId['safe-guard']->guidedFields);
        $this->assertSame(
            'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
            $byId['safe-guard']->extensionClass,
        );

        $this->assertFalse($byId['task-workflow']->builtin);
        $this->assertSame('ineersa/hatfield-ext-task-workflow', $byId['task-workflow']->package);
        $this->assertSame('0.0.42', $byId['task-workflow']->version);
        $this->assertSame('task_workflow', $byId['task-workflow']->settingsKey);

        $this->assertSame('ineersa/hatfield-ext-file-rewind', $byId['file-rewind']->package);
        $this->assertSame('0.0.42', $byId['file-rewind']->version);
        $this->assertSame('file_rewind', $byId['file-rewind']->settingsKey);
        $this->assertSame('ineersa/hatfield-ext-observational-memory', $byId['observational-memory']->package);
        $this->assertSame('0.0.42', $byId['observational-memory']->version);
        $this->assertSame('observational_memory', $byId['observational-memory']->settingsKey);
        $this->assertSame('open_unit_ratio', $byId['observational-memory']->guidedFields[2]->type);

        foreach (['task-workflow', 'file-rewind', 'observational-memory'] as $id) {
            $this->assertFalse($byId[$id]->builtin);
            $this->assertNotNull($byId[$id]->package);
            $this->assertNotNull($byId[$id]->version);
            $this->assertNotSame('', $byId[$id]->settingsKey);
        }
    }

    public function testInvalidCatalogFailsClosedWithTypedException(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('provided-ext-catalog');
        $path = $this->tmpDir.'/bad.yaml';
        file_put_contents($path, <<<'YAML'
packages:
  - id: broken
    name: Broken
    builtin: false
    extension_class: Broken\Extension
    settings_key: broken
YAML);

        $catalog = new ProvidedExtensionsCatalog(
            $path,
            ExtensionsSetupTestSupport::createSerializer(),
            ExtensionsSetupTestSupport::createValidator(),
        );

        $this->expectException(ProvidedExtensionsCatalogException::class);
        $this->expectExceptionMessage('failed validation');
        $catalog->all();
    }
}
