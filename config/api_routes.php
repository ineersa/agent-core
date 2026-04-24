<?php

declare(strict_types=1);

use Ineersa\AgentCore\Api\Http\RunApiController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RunApiController::class)
        ->autowire()
        ->tag('controller.service_arguments')
        ->tag('routing.controller')
    ;
};
