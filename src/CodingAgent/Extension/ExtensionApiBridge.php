<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;

/**
 * Internal implementation of ExtensionApiInterface for the extension loading flow.
 *
 * In v1 (EXT-01), this bridge collects tool registrations in-memory so they
 * can be forwarded to the CodingAgent ToolRegistry later (EXT-02 adds the
 * ToolRegistry bridge). EXT-02 should either replace or decorate this service
 * to flush registrations into the permanent ToolRegistry.
 *
 * The bridge intentionally does NOT validate ToolRegistrationDTO fields —
 * name validity, schema shape, and handler resolvability are the registry's
 * responsibility, not the ExtensionApi boundary's.
 *
 * @internal this is app-internal wiring, not part of the public ExtensionApi
 */
final class ExtensionApiBridge implements ExtensionApiInterface
{
    /**
     * Collected tool registrations, in registration order.
     *
     * @var list<ToolRegistrationDTO>
     */
    private array $registeredTools = [];

    public function registerTool(ToolRegistrationDTO $tool): void
    {
        $this->registeredTools[] = $tool;
    }

    /**
     * Return all collected tool registrations and clear the buffer.
     *
     * EXT-02 should call this after the loading phase to forward registrations
     * to the ToolRegistry, ensuring registrations are consumed exactly once
     * per boot cycle.
     *
     * @return list<ToolRegistrationDTO>
     */
    public function drainRegistrations(): array
    {
        $tools = $this->registeredTools;
        $this->registeredTools = [];

        return $tools;
    }

    /**
     * Peek at collected registrations without draining.
     *
     * Useful for testing and inspection.
     *
     * @return list<ToolRegistrationDTO>
     */
    public function getRegistrations(): array
    {
        return $this->registeredTools;
    }
}
