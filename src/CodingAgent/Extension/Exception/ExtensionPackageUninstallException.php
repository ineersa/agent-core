<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Exception;

/** Refusals carry only host-authored messages safe to show in setup. */
final class ExtensionPackageUninstallException extends \RuntimeException
{
}
