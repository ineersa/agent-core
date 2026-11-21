<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\ExtensionUpdateFixture;
use PHPUnit\Framework\TestCase;

final class ExtensionPackageInstallerTest extends TestCase
{
    public function testUninstallRemovesOnlyTheSelectedDirectRequirement(): void
    {
        $fixture = new ExtensionUpdateFixture();
        try {
            $fixture->seed();
            $fixture->seed('home');
            $before = $fixture->snapshot('home');
            $installer = new ExtensionPackageInstaller(new SettingsPathResolver($fixture->directory, $fixture->directory.'/home'), $fixture->filesystem);
            $installer->uninstall(SettingsLayerEnum::Project, $fixture->project(), 'test/update-extension');
            $this->assertSame([], $installer->packages(SettingsLayerEnum::Project, $fixture->project()));
            $this->assertSame($before, $fixture->snapshot('home'));
            $manifest = json_decode(file_get_contents($fixture->root('project').'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('test/update-extension', $manifest['require'] ?? []);
        } finally {
            $fixture->close();
        }
    }

    public function testNewInstallationExplicitlyRequiresAnyVersionAndExistingInstallDoesNotRewriteIt(): void
    {
        $fixture = new ExtensionUpdateFixture();
        try {
            $fixture->seed(version: '0.0.43', requirement: '^0.0.43');
            $root = $fixture->root('project');
            $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            unset($manifest['scripts']);
            $fixture->filesystem->dumpFile($root.'/composer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
            $fixture->filesystem->remove($root.'/vendor');
            $fixture->writePackage('project', '0.0.54');
            $installer = new ExtensionPackageInstaller(new SettingsPathResolver($fixture->directory, $fixture->directory.'/home'), $fixture->filesystem);
            $installer->install(SettingsLayerEnum::Project, $fixture->project(), 'test/update-extension');
            $installed = json_decode(file_get_contents($root.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('*', $installed['require']['test/update-extension']);
            $this->assertSame('0.0.54', $fixture->loadedVersion());

            // Once installed, fixing requirements belongs to the approved updater,
            // not setup's no-op/autoloader repair path.
            $installed['require']['test/update-extension'] = '^0.0.54';
            $fixture->filesystem->dumpFile($root.'/composer.json', json_encode($installed, \JSON_THROW_ON_ERROR));
            $before = $fixture->snapshot();
            $installer->install(SettingsLayerEnum::Project, $fixture->project(), 'test/update-extension');
            $this->assertSame($before, $fixture->snapshot());
        } finally {
            $fixture->close();
        }
    }
}
