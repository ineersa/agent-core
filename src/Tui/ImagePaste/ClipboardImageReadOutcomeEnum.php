<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

enum ClipboardImageReadOutcomeEnum: string
{
    case Image = 'image';
    case NoImage = 'no_image';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
}
