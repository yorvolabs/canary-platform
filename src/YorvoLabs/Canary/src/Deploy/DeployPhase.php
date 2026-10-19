<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Deploy;

enum DeployPhase: string
{
    case PRE  = 'pre';
    case POST = 'post';
}
