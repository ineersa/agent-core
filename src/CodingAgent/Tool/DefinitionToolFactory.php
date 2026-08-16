<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Symfony AI ToolFactoryInterface adapter returning exact precomputed Tool
 * metadata per registered handler object.
 *
 * The native Toolbox passes each handler instance from its `tools` iterable
 * to getTool(); the factory maps the instance's object id back to the
 * metadata computed from the registry definition. One handler object may be
 * registered under several names (the object id maps to a list of Tools),
 * and several handler instances of the same class are kept distinct.
 */
final readonly class DefinitionToolFactory implements ToolFactoryInterface
{
    /**
     * @var array<int, list<Tool>>
     */
    private array $metadataByObjectId;

    /**
     * @param array<int, list<Tool>> $metadataByObjectId Metadata per handler
     *                                                   object id (spl_object_id)
     */
    public function __construct(array $metadataByObjectId)
    {
        $this->metadataByObjectId = $metadataByObjectId;
    }

    public function getTool(object|string $reference): iterable
    {
        if (!\is_object($reference)) {
            // The native Toolbox always invokes the factory with the handler
            // instances from its `tools` iterable; class-name references are
            // never produced by it. Refuse instead of guessing.
            throw new \LogicException(\sprintf('DefinitionToolFactory only supports handler object references, got class string "%s". The native Toolbox always passes handler instances.', $reference));
        }

        return $this->metadataByObjectId[spl_object_id($reference)] ?? [];
    }
}
