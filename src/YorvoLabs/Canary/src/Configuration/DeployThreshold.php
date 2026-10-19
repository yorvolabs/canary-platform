<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Configuration;

enum DeployThreshold: string
{
    case QUEUE  = 'queue';
    case FAILED = 'failed';
}
