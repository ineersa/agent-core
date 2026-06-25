<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function CastorTasks\pi_bwrap_already_inside;
use function CastorTasks\pi_bwrap_disabled_by_env;
use function CastorTasks\should_auto_wrap_agent_castor_task;

require_once __DIR__.'/../../../.castor/helpers.php';

#[Group('unit')]
final class PiBwrapCastorHelperTest extends TestCase
{
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    public function testPiBwrapDisabledByEnv(): void
    {
        $this->setEnv('HATFIELD_BWRAP', '0');
        $this->assertTrue(pi_bwrap_disabled_by_env());
        $this->setEnv('HATFIELD_BWRAP', 'false');
        $this->assertTrue(pi_bwrap_disabled_by_env());
        $this->setEnv('HATFIELD_BWRAP', null);
        $this->assertFalse(pi_bwrap_disabled_by_env());
    }

    public function testPiBwrapAlreadyInside(): void
    {
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', '1');
        $this->assertTrue(pi_bwrap_already_inside());
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);
        $this->assertFalse(pi_bwrap_already_inside());
    }

    public function testShouldAutoWrapSkipsWhenDisabledOrInside(): void
    {
        $this->setEnv('HATFIELD_BWRAP', '0');
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);
        $this->assertFalse(should_auto_wrap_agent_castor_task());

        $this->setEnv('HATFIELD_BWRAP', null);
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', '1');
        $this->assertFalse(should_auto_wrap_agent_castor_task());
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (!\array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
