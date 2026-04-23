<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Security;

use Ineersa\AgentCore\Contract\Api\AuthorizeRunInterface;
use Symfony\Component\HttpFoundation\Request;

final class AllowAllAuthorizeRun implements AuthorizeRunInterface
{
    public function authorize(Request $request, string $route): void
    {
    }
}
