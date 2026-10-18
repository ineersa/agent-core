<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Contract: an interrupted SPC micro-only phar.c patch is restored before the
 * next CLI/micro rebuild, so make cli does not link an undefined micro_get_filename.
 * Invalid backups fail closed instead of overwriting phar.c.
 */
final class SpcPharPatchRestoreTest extends TestCase
{
    public function testRestoreRemovesInterruptedMicroOnlyPharPatch(): void
    {
        $root = ProjectDir::get();
        require_once $root.'/.castor/helpers.php';
        require_once $root.'/.castor/distribution.php';

        $this->assertTrue(\function_exists('distribution_restore_interrupted_spc_phar_patch'));

        $work = TestDirectoryIsolation::createOsTempDir('spc-phar-restore-');
        try {
            $pharDir = $work.'/source/php-src/ext/phar';
            mkdir($pharDir, 0755, true);
            $pharC = $pharDir.'/phar.c';
            $backup = $pharC.'.bak';

            $pristine = "static zend_op_array *phar_compile_file(zend_file_handle *file_handle, int type)\n{\n\tif (strstr(ZSTR_VAL(file_handle->filename), \".phar\") && !strstr(ZSTR_VAL(file_handle->filename), \"://\")) {\n\t}\n}\n";
            $patched = "char *micro_get_filename(void);\n\nstatic zend_op_array *phar_compile_file(zend_file_handle *file_handle, int type)\n{\n\tif ((strstr(ZSTR_VAL(file_handle->filename), micro_get_filename()) || strstr(ZSTR_VAL(file_handle->filename), \".phar\")) && !strstr(ZSTR_VAL(file_handle->filename), \"://\")) {\n\t}\n}\n";
            file_put_contents($pharC, $patched);
            file_put_contents($backup, $pristine);

            distribution_restore_interrupted_spc_phar_patch($work);

            $this->assertFileDoesNotExist($backup);
            $this->assertSame($pristine, (string) file_get_contents($pharC));
            $this->assertStringNotContainsString('micro_get_filename', (string) file_get_contents($pharC));

            // Idempotent when no backup remains.
            distribution_restore_interrupted_spc_phar_patch($work);
            $this->assertSame($pristine, (string) file_get_contents($pharC));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testRestoreRejectsBackupThatStillReferencesMicroGetFilename(): void
    {
        $root = ProjectDir::get();
        require_once $root.'/.castor/helpers.php';
        require_once $root.'/.castor/distribution.php';

        $work = TestDirectoryIsolation::createOsTempDir('spc-phar-restore-bad-');
        try {
            $pharDir = $work.'/source/php-src/ext/phar';
            mkdir($pharDir, 0755, true);
            $pharC = $pharDir.'/phar.c';
            $backup = $pharC.'.bak';
            $patched = "char *micro_get_filename(void);\nint x;\n";
            file_put_contents($pharC, $patched);
            file_put_contents($backup, $patched);

            try {
                distribution_restore_interrupted_spc_phar_patch($work);
                $this->fail('Expected RuntimeException for poisoned backup');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('phar.c.bak still references micro_get_filename', $e->getMessage());
            }

            $this->assertFileExists($backup);
            $this->assertSame($patched, (string) file_get_contents($pharC));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testRestoreRejectsEmptyBackup(): void
    {
        $root = ProjectDir::get();
        require_once $root.'/.castor/helpers.php';
        require_once $root.'/.castor/distribution.php';

        $work = TestDirectoryIsolation::createOsTempDir('spc-phar-restore-empty-');
        try {
            $pharDir = $work.'/source/php-src/ext/phar';
            mkdir($pharDir, 0755, true);
            $pharC = $pharDir.'/phar.c';
            $backup = $pharC.'.bak';
            $patched = "char *micro_get_filename(void);\nint x;\n";
            file_put_contents($pharC, $patched);
            file_put_contents($backup, '');

            try {
                distribution_restore_interrupted_spc_phar_patch($work);
                $this->fail('Expected RuntimeException for empty backup');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('phar.c.bak is empty', $e->getMessage());
            }

            $this->assertFileExists($backup);
            $this->assertSame($patched, (string) file_get_contents($pharC));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testStagePhpmicroForSpcCopiesRequiredFilesAndSkipsGit(): void
    {
        $root = ProjectDir::get();
        require_once $root.'/.castor/helpers.php';
        require_once $root.'/.castor/distribution.php';

        $this->assertTrue(\function_exists('distribution_stage_phpmicro_for_spc'));

        $checkout = TestDirectoryIsolation::createOsTempDir('spc-phpmicro-checkout-');
        try {
            file_put_contents($checkout.'/config.m4', "PHP_ARG_ENABLE(micro)\n");
            file_put_contents($checkout.'/php_micro.c', "int micro_main(void) { return 0; }\n");
            file_put_contents($checkout.'/php_micro_fileinfo.c', "int micro_fileinfo(void) { return 0; }\n");
            mkdir($checkout.'/.git/objects', 0755, true);
            file_put_contents($checkout.'/.git/HEAD', "ref: refs/heads/main\n");
            file_put_contents($checkout.'/.git/objects/pack', "secret\n");

            $stage = distribution_stage_phpmicro_for_spc($checkout, 'deadbeef');
            try {
                $this->assertFileExists($stage.'/config.m4');
                $this->assertFileExists($stage.'/php_micro.c');
                $this->assertFileExists($stage.'/php_micro_fileinfo.c');
                $this->assertDirectoryDoesNotExist($stage.'/.git');
                $this->assertFileDoesNotExist($stage.'/.git/HEAD');
            } finally {
                \CastorTasks\remove_path_checked($stage);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($checkout);
        }
    }
}
