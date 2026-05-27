<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Canonical content-part type constants shared between AgentCore
 * and CodingAgent for tool results that declare attachment references.
 *
 * CodingAgent tools emit these constants in their result payloads;
 * AgentCore normalizers and converters consume them without knowing
 * about specific CodingAgent tool types.
 */
final class ToolResultType
{
    /**
     * Content part type for image references that AgentMessageConverter
     * converts into real Symfony AI Image attachments in synthetic
     * UserMessage instances.
     */
    public const string IMAGE_REF = 'image_ref';
}
