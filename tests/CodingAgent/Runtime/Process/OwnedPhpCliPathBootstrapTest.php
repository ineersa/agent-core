<?php

declare(strict_types=1);

namespace {
    require_once __DIR__.'/Support/MicroSelfFilenamePolyfill.php';
}

namespace Ineersa\CodingAgent\Tests\Runtime\Process {
    use Ineersa\CodingAgent\Runtime\Process\OwnedPhpCliPathBootstrap;
    use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Contract: fused natives that advertise PHP_BINARY=hatfield-php prepend the
     * adjacent helper directory to PATH. Missing helpers fail closed. Host/build
     * PHP is never used as fallback. No temporary staging directories. Companion
     * hatfield.<target>.php is a release artifact name only, not a runtime layout.
     */
    final class OwnedPhpCliPathBootstrapTest extends TestCase
    {
        private string $originalPath = '';

        protected function setUp(): void
        {
            $path = getenv('PATH');
            $this->originalPath = false === $path ? '' : $path;
        }

        protected function tearDown(): void
        {
            $GLOBALS['hatfield_test_micro_self'] = '';
            if ('' === $this->originalPath) {
                putenv('PATH');
                unset($_ENV['PATH'], $_SERVER['PATH']);
            } else {
                putenv('PATH='.$this->originalPath);
                $_ENV['PATH'] = $this->originalPath;
                $_SERVER['PATH'] = $this->originalPath;
            }
            parent::tearDown();
        }

        public function testEnsureNoopsWhenSelfEmpty(): void
        {
            $GLOBALS['hatfield_test_micro_self'] = '';
            putenv('PATH=/usr/bin:/bin');
            $_ENV['PATH'] = '/usr/bin:/bin';
            $_SERVER['PATH'] = '/usr/bin:/bin';
            $this->assertFalse(OwnedPhpCliPathBootstrap::ensure());
            $this->assertSame('/usr/bin:/bin', getenv('PATH'));
        }

        public function testEnsureNoopsWhenPhpBinaryIsNotOwnedBasename(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-noop');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                file_put_contents($fused, "#!/bin/sh\nexit 0\n");
                chmod($fused, 0755);
                $GLOBALS['hatfield_test_micro_self'] = $fused;
                putenv('PATH=/usr/bin:/bin');
                $_ENV['PATH'] = '/usr/bin:/bin';
                $_SERVER['PATH'] = '/usr/bin:/bin';
                // Host PHP_BINARY is not hatfield-php, so ensure() must not touch PATH.
                $this->assertFalse(OwnedPhpCliPathBootstrap::ensure());
                $this->assertSame('/usr/bin:/bin', getenv('PATH'));
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        public function testResolveAdjacentHelperAcceptsCanonicalHatfieldPhp(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-cli');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                $helper = $dir.'/hatfield-php';
                file_put_contents($fused, "#!/bin/sh\nexit 0\n");
                file_put_contents($helper, "#!/bin/sh\necho owned\n");
                chmod($fused, 0755);
                chmod($helper, 0755);

                $resolved = $this->resolveAdjacentHelper((string) realpath($fused));
                $this->assertSame(realpath($helper), $resolved);

                putenv('PATH=/usr/bin:/bin');
                $_ENV['PATH'] = '/usr/bin:/bin';
                $_SERVER['PATH'] = '/usr/bin:/bin';
                $this->prependPath((string) \dirname((string) $resolved));
                $this->assertSame($dir.\PATH_SEPARATOR.'/usr/bin'.\PATH_SEPARATOR.'/bin', getenv('PATH'));
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        public function testResolveAdjacentHelperRejectsCompanionPhpBasename(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-companion-reject');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                $companion = $dir.'/hatfield.linux-amd64.php';
                file_put_contents($fused, "#!/bin/sh\nexit 0\n");
                file_put_contents($companion, "#!/bin/sh\necho companion\n");
                chmod($fused, 0755);
                chmod($companion, 0755);

                $this->assertNull($this->resolveAdjacentHelper((string) realpath($fused)));
                $stage = sys_get_temp_dir().'/hatfield-owned-php-'.hash(
                    'sha256',
                    realpath($fused)."\0".realpath($companion),
                );
                $this->assertDirectoryDoesNotExist($stage);
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        public function testResolveAdjacentHelperRejectsMissingHelper(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-missing');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                file_put_contents($fused, "#!/bin/sh\nexit 0\n");
                chmod($fused, 0755);
                $this->assertNull($this->resolveAdjacentHelper((string) realpath($fused)));
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        public function testResolveAdjacentHelperRejectsNonExecutableHelper(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-nonexec');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                $helper = $dir.'/hatfield-php';
                file_put_contents($fused, "#!/bin/sh\nexit 0\n");
                file_put_contents($helper, "#!/bin/sh\necho owned\n");
                chmod($fused, 0755);
                chmod($helper, 0644);
                $this->assertNull($this->resolveAdjacentHelper((string) realpath($fused)));
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        private function resolveAdjacentHelper(string $selfReal): ?string
        {
            $method = new ReflectionMethod(OwnedPhpCliPathBootstrap::class, 'resolveAdjacentHelper');

            $result = $method->invoke(null, $selfReal);
            $this->assertTrue(null === $result || \is_string($result));

            return $result;
        }

        private function prependPath(string $directory): void
        {
            $method = new ReflectionMethod(OwnedPhpCliPathBootstrap::class, 'prependPath');
            $method->invoke(null, $directory);
        }
    }
}
