<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Configuration;

enum RateLimitGroup: string
{
    case HEALTH   = 'health';
    case MUTATION = 'mutation';
}
