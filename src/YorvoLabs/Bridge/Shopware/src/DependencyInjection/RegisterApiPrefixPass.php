<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use YorvoLabs\Canary\Configuration\RoutePrefix;

/**
 * Registers the Canary route prefix with Shopware's API prefix list.
 *
 * The Storefront RequestTransformer requires every request path to belong to a
 * known sales-channel domain unless the path starts with a registered API
 * prefix. Without this pass it throws SalesChannelMappingException before
 * kernel.request fires, so RequestGuardSubscriber never gets a chance to run.
 *
 * The prefix is read from CANARY_ROUTE_PREFIX at compile time (cache:clear).
 * A cache:clear is required whenever the env var value changes.
 */
final class RegisterApiPrefixPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('shopware.routing.registered_api_prefixes')) {
            return;
        }

        /** @var list<string> $prefixes */
        $prefixes = $container->getParameter('shopware.routing.registered_api_prefixes');

        $prefix = RoutePrefix::fromEnv()->value();

        if (!in_array($prefix, $prefixes, true)) {
            $container->setParameter('shopware.routing.registered_api_prefixes', [...$prefixes, $prefix]);
        }
    }
}
