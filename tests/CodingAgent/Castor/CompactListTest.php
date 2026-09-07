<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Native Castor boot must supply compact list defaults without a tool-call
 * rewrite. A subprocess is needed to exercise Castor's input binding lifecycle.
 */
final class CompactListTest extends TestCase
{
    public function testNativeListDefaultsAndExplicitFormat(): void
    {
        $castor = new ExecutableFinder()->find('castor');
        $this->assertNotNull($castor);
        $env = [
            'LLM_MODE' => false,
            'NO_COLOR' => false,
            'CLICOLOR' => false,
            'CASTOR_DISABLE_VERSION_CHECK' => false,
        ];
        $process = new Process([$castor, 'list'], ProjectDir::get(), $env, timeout: 5);

        try {
            $process->mustRun();
            $output = $process->getOutput();
            $this->assertStringContainsString('* [`check`](#check)', $output);
            $this->assertStringNotContainsString('Usage:', $output);
            $this->assertStringNotContainsString("\x1b[", $output);
        } finally {
            $process->stop();
        }

        $process = new Process([$castor, 'list', '--format=json'], ProjectDir::get(), $env, timeout: 5);
        try {
            $process->mustRun();
            $output = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertContains('check', array_column($output['commands'], 'name'));
        } finally {
            $process->stop();
        }
    }
}
