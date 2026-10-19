<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\DependencyInjection;

use YorvoLabs\Canary\Auth\IpAllowlistChecker;
use YorvoLabs\Canary\Auth\TokenAuthenticator;
use YorvoLabs\Canary\Configuration\Options;

/**
 * Static factories for services whose constructor arguments are arrays derived
 * from Options. Avoids symfony/expression-language (not shipped by Shopware).
 */
final class AuthServiceFactory
{
    public static function createTokenAuthenticator(Options $config): TokenAuthenticator
    {
        return new TokenAuthenticator($config->getTokenMap());
    }

    public static function createIpAllowlistChecker(Options $config): IpAllowlistChecker
    {
        return new IpAllowlistChecker($config->getAllowedCidrs());
    }
}
