<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Session storage settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the session storage directory path.
 * The path may be relative (resolved against project CWD) or absolute.
 */
final readonly class SessionsConfig
{
    /**
     * @param string $path Session storage directory. Relative paths resolve
     *                     against the active project CWD. Defaults to
     *                     {@see .hatfield/sessions}, resolved to absolute by
     *                     {@see SettingsResolver}.
     */
    public function __construct(
        public string $path = '.hatfield/sessions',
    ) {
    }
}
