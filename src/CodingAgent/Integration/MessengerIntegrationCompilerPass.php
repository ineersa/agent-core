<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Integration;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Wires Messenger buses and middleware, then delegates to MessengerPass.
 *
 * The #[AsMessageHandler] → messenger.message_handler tag mapping is
 * registered separately via ContainerBuilder::registerAttributeForAutoconfiguration()
 * during Kernel::build() — before any compiler passes run.
 *
 * This pass handles the remaining FrameworkBundle responsibilities:
 *   (a) tag buses as "messenger.bus"
 *   (b) create per-bus HandleMessageMiddleware services
 *   (c) set {bus}.middleware parameters
 *   (d) delegate to Symfony\Component\Messenger\DependencyInjection\MessengerPass
 *
 * After this pass runs, handlers with #[AsMessageHandler] are correctly
 * wired into their buses, provided each handler's own dependency chain
 * can be resolved by the DI container.
 */
final class MessengerIntegrationCompilerPass implements CompilerPassInterface
{
    private const array BUS_IDS = [
        'agent.command.bus',
        'agent.execution.bus',
        'agent.publisher.bus',
    ];

    public function process(ContainerBuilder $container): void
    {
        $this->tagBuses($container);
        $this->createMiddlewareParameters($container);
        $this->createHandleMessageMiddlewareServices($container);

        // Delegate handler → bus locator wiring to Symfony's own pass.
        (new MessengerPass())->process($container);
    }

    /**
     * Build the attribute-to-tag mapping for #[AsMessageHandler].
     *
     * Called from Kernel::build() BEFORE compilation, so the autoconfigure
     * scanner can discover and tag handler services.
     *
     * Key mapping: AsMessageHandler::$fromTransport → tag key "from_transport".
     * For method-level attributes, the method name is propagated.
     */
    public static function registerHandlerAttribute(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsMessageHandler::class,
            static function (ChildDefinition $definition, AsMessageHandler $attribute, \Reflector $reflector): void {
                $tag = array_filter(
                    get_object_vars($attribute),
                    static fn (mixed $v): bool => null !== $v && 0 !== $v && false !== $v && '' !== $v,
                );

                // MessengerPass expects "from_transport", not "fromTransport".
                if (isset($tag['fromTransport'])) {
                    $tag['from_transport'] = $tag['fromTransport'];
                    unset($tag['fromTransport']);
                }

                // Propagate method name for method-level attributes.
                if ($reflector instanceof \ReflectionMethod && !isset($tag['method'])) {
                    $tag['method'] = $reflector->getName();
                }

                $definition->addTag('messenger.message_handler', $tag);
            },
        );
    }

    /**
     * Mark existing bus services so MessengerPass discovers them.
     */
    private function tagBuses(ContainerBuilder $container): void
    {
        foreach (self::BUS_IDS as $busId) {
            if (!$container->hasDefinition($busId)) {
                continue;
            }

            $def = $container->getDefinition($busId);
            if (!$def->hasTag('messenger.bus')) {
                $def->addTag('messenger.bus');
            }
        }
    }

    /**
     * Create {bus}.middleware parameters.
     *
     * MessengerPass reads this parameter to replace the MessageBus
     * constructor argument with the configured middleware stack.
     */
    private function createMiddlewareParameters(ContainerBuilder $container): void
    {
        foreach (self::BUS_IDS as $busId) {
            $paramName = $busId.'.middleware';

            if ($container->hasParameter($paramName)) {
                continue;
            }

            $container->setParameter($paramName, [
                ['id' => $busId.'.middleware.handle_message'],
            ]);
        }
    }

    /**
     * Create a synchronous HandleMessageMiddleware service per bus.
     *
     * The HandlersLocator argument is a placeholder with ignore-on-invalid
     * — MessengerPass will create the real locator service
     * ({bus}.messenger.handlers_locator) and replace this argument during
     * registerHandlers().
     */
    private function createHandleMessageMiddlewareServices(ContainerBuilder $container): void
    {
        foreach (self::BUS_IDS as $busId) {
            $middlewareId = $busId.'.middleware.handle_message';

            if ($container->hasDefinition($middlewareId)) {
                continue;
            }

            $container->setDefinition(
                $middlewareId,
                new Definition(
                    HandleMessageMiddleware::class,
                    [
                        new Reference(
                            $busId.'.messenger.handlers_locator',
                            ContainerBuilder::IGNORE_ON_INVALID_REFERENCE,
                        ),
                    ],
                ),
            );
        }
    }
}
