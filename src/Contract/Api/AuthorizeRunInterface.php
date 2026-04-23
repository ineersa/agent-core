<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Api;

use Symfony\Component\HttpFoundation\Request;

interface AuthorizeRunInterface
{
    /**
     * Authorize current request for the resolved route.
     *
     * Throw an HTTP 403 exception (e.g. AccessDeniedHttpException) to deny access.
     */
    public function authorize(Request $request, string $route): void;
}
