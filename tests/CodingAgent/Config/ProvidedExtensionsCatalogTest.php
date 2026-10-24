<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\Exception\ProvidedExtensionsCatalogException;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
            $this->createSerializer(),
            $this->createValidator(),
        );

        $all = $catalog->all();
        $this->assertCount(4, $all);

        $byId = [];
        foreach ($all as $package) {
            $byId[$package->id] = $package;
        }

        $this->assertTrue($byId['safe-guard']->builtin);
        $this->assertNull($byId['safe-guard']->package);
        $this->assertSame(
            'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
            $byId['safe-guard']->extensionClass,
        );

        $this->assertFalse($byId['task-workflow']->builtin);
        $this->assertSame('ineersa/hatfield-ext-task-workflow', $byId['task-workflow']->package);
        $this->assertSame('0.0.42', $byId['task-workflow']->version);

        $this->assertSame('ineersa/hatfield-ext-file-rewind', $byId['file-rewind']->package);
        $this->assertSame('0.0.42', $byId['file-rewind']->version);
        $this->assertSame('ineersa/hatfield-ext-observational-memory', $byId['observational-memory']->package);
        $this->assertSame('0.0.42', $byId['observational-memory']->version);

        $installable = $catalog->installable();
        $this->assertCount(3, $installable);
        foreach ($installable as $package) {
            $this->assertFalse($package->builtin);
            $this->assertNotNull($package->package);
            $this->assertNotNull($package->version);
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
YAML);

        $catalog = new ProvidedExtensionsCatalog(
            $path,
            $this->createSerializer(),
            $this->createValidator(),
        );

        $this->expectException(ProvidedExtensionsCatalogException::class);
        $this->expectExceptionMessage('failed validation');
        $catalog->all();
    }

    private function createSerializer(): SerializerInterface
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter());
        $propertyInfo = new PropertyInfoExtractor(
            [],
            [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                $classMetadataFactory,
                $nameConverter,
                null,
                $propertyInfo,
            ),
        ]);
    }

    private function createValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
