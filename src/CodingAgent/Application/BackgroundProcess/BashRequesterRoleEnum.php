<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

/** Fixed roles prevent one requester from acknowledging another's live replies. */
enum BashRequesterRoleEnum: string
{
    case Tool = 'tool';
    case Controller = 'controller';

    public function queue(): string
    {
        return 'bash_registration_reply_'.$this->value;
    }
}
