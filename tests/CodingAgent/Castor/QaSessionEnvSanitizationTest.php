<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: QA lane shell prefixes strip the six session-controller doctrine
 * transport DSNs (exported into every bash command by a live Hatfield session)
 * so unit lanes always resolve the documented in-memory transports, while a
 * control var still passes through.
 */
final class QaSessionEnvSanitizationTest extends TestCase
{
    private const DSN_VARS = [
        'HATFIELD_RUN_CONTROL_TRANSPORT_DSN',
        'HATFIELD_LLM_TRANSPORT_DSN',
        'HATFIELD_TOOL_TRANSPORT_DSN',
        'HATFIELD_AGENT_TRANSPORT_DSN',
        'HATFIELD_MCP_TRANSPORT_DSN',
        'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN',
    ];

    private const CONTROL_VAR = 'HATFIELD_SESSION_ID';

    public function testObservabilityPrefixUnsetsAllSixSessionTransportDsns(): void
    {
        self::requireCastorFiles();

        $prefix = qa_observability_env_command();

        $this->assertStringContainsString('env -u HATFIELD_RUN_CONTROL_TRANSPORT_DSN', $prefix);
        foreach (self::DSN_VARS as $var) {
            $this->assertStringContainsString('-u '.$var, $prefix, $var.' must be unset in the QA prefix');
        }
        $this->assertSame(
            \count(self::DSN_VARS),
            substr_count($prefix, ' -u '),
            'exactly the six DSN vars must be unset, no more',
        );
    }

    public function testCheckParatestCommandUnsetsAllSixSessionTransportDsns(): void
    {
        self::requireCastorFiles();

        $cmd = build_check_paratest_command();

        foreach (self::DSN_VARS as $var) {
            $this->assertStringContainsString('-u '.$var, $cmd, $var.' must be unset in the check unit lane');
        }
    }

    public function testChildProcessDoesNotInheritSessionTransportDsns(): void
    {
        self::requireCastorFiles();

        $dsn = 'doctrine://messenger_transport?queue_name=session_poison_x&redeliver_timeout=60';
        $poisoned = [];
        try {
            foreach (self::DSN_VARS as $var) {
                putenv($var.'='.$dsn);
                $poisoned[] = $var;
            }
            putenv(self::CONTROL_VAR.'=session-test-control-42');
            $poisoned[] = self::CONTROL_VAR;

            // Prove the poison is actually active before building the prefix.
            $this->assertSame($dsn, getenv('HATFIELD_LLM_TRANSPORT_DSN'));

            $prefix = qa_observability_env_command();
            $phpBin = escapeshellarg(\PHP_BINARY);

            foreach (self::DSN_VARS as $var) {
                $out = shell_exec($prefix.' '.$phpBin.' -r '.escapeshellarg('echo (string) getenv('.var_export($var, true).');'));
                $this->assertSame('', (string) $out, $var.' must be UNSET in the QA child process');
            }

            $out = shell_exec($prefix.' '.$phpBin.' -r '.escapeshellarg('echo (string) getenv('.var_export(self::CONTROL_VAR, true).');'));
            $this->assertSame('session-test-control-42', (string) $out, 'control var must still pass through');
        } finally {
            foreach ($poisoned as $var) {
                putenv($var);
            }
        }
    }

    private static function requireCastorFiles(): void
    {
        $root = ProjectDir::get();
        $envPhp = $root.'/.castor/env.php';
        $phpunitPhp = $root.'/.castor/phpunit.php';
        self::assertFileExists($envPhp);
        self::assertFileExists($phpunitPhp);
        require_once $envPhp;
        require_once $phpunitPhp;
        // env.php / phpunit.php are global-namespace files (only helpers.php
        // declares `namespace CastorTasks`).
        self::assertTrue(
            \function_exists('qa_observability_env_command'),
            'qa_observability_env_command must load from .castor/env.php',
        );
        self::assertTrue(
            \function_exists('build_check_paratest_command'),
            'build_check_paratest_command must load from .castor/phpunit.php',
        );
    }
}
