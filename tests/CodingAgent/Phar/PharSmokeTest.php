<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Built PHAR smoke test.
 *
 * Validates that the PHAR exists (e.g. /tmp/bin/hatfield.phar when
 * HATFIELD_BINARY_PATH is set) and that it boots sufficiently to
 * respond to `php hatfield.phar list` with the expected agent command.
 *
 * This test is NOT in the llm-real group because it does not need
 * llama.cpp. It is not in any group by default — run it explicitly:
 *
 *   HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 *
 * Or through Castor:
 *
 *   castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 */
#[Group('phar')]
final class PharSmokeTest extends TestCase
{
    /**
     * Default PHAR path used in skip messages.
     *
     * The actual path is resolved via HATFIELD_BINARY_PATH env var
     * (set by Castor tasks) or AgentTestExecutable.  This constant mirrors
     * the build default from .castor/helpers.php:hatfield_phar_path().
     */
    private const string DEFAULT_PHAR_PATH = '/tmp/bin/hatfield.phar';

    public function testPharBootingToAgentList(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        self::assertFileExists($pharPath, 'PHAR not found at '.$pharPath);
        self::assertFileIsReadable($pharPath);

        $output = shell_exec($php.' '.escapeshellarg($pharPath).' list 2>&1');
        self::assertNotNull($output, 'PHAR list command produced no output');
        self::assertStringContainsString('agent', $output, 'PHAR list output should contain the agent command');

        $sizeMb = filesize($pharPath) / 1024 / 1024;
        self::assertLessThan(
            20.0,
            $sizeMb,
            \sprintf('PHAR size %.1f MB exceeds 20 MB limit', $sizeMb),
        );

        echo \sprintf("\nPHAR smoke test ok: %s (%.1f MB)\n", $pharPath, $sizeMb);
    }

    public function testPharAgentHelp(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        // Also verify that --help works on the agent command
        $output = shell_exec($php.' '.escapeshellarg($pharPath).' agent --help 2>&1');
        self::assertNotNull($output, 'PHAR agent --help produced no output');
        self::assertStringContainsString('Usage:', $output);
    }
}
