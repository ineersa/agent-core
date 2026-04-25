<?php

declare(strict_types=1);

use Ineersa\AgentCore\Api\Http\RunApiController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\Authorization;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RunApiController::class)
        ->autowire()
        ->arg('$mercureAuthorization', service(Authorization::class)->nullOnInvalid())
        ->tag('controller.service_arguments')
        ->tag('routing.controller')
    ;
};
