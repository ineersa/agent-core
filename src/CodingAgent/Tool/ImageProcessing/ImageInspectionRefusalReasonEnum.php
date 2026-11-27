<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

enum ImageInspectionRefusalReasonEnum
{
    case NotReadable;
    case SizeUnavailable;
    case TooLarge;
    case OpenFailed;
    case HeaderUnreadable;
    case UnsupportedType;
    case DimensionsUnavailable;
    case DimensionsExceeded;
}
