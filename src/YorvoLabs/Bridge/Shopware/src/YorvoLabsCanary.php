<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoLabs\Canary\Bridge\Shopware\DependencyInjection\RegisterApiPrefixPass;
use YorvoLabs\Canary\Configuration\RoutePrefix;

class YorvoLabsCanary extends Plugin
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // No DependencyInjection/YorvoLabsCanaryExtension exists; services are loaded
        // directly in build() via XmlFileLoader. Returning null prevents Symfony's
        // Bundle convention from trying to auto-discover the class by name.
        return null;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');
        $loader->load('monolog.xml');

        // Register the canary_audit Monolog handler so MonologAuditLogger writes
        // to var/log/canary_audit.log without any manual monolog.yaml setup.
        // prependExtensionConfig runs before MonologExtension::load(), so this
        // acts as a plugin default that users can override in their own monolog.yaml.
        $container->prependExtensionConfig('monolog', [
            'handlers' => [
                'canary_audit' => [
                    'type'     => 'stream',
                    'path'     => '%kernel.logs_dir%/canary_audit.log',
                    'level'    => 'info',
                    'channels' => ['canary_audit'],
                ],
            ],
        ]);

        $container->addCompilerPass(new RegisterApiPrefixPass());
    }

    public function enrichPrivileges(): array
    {
        return [
            'canary:viewer' => [],
        ];
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        if ($this->container === null) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement(
            "DELETE FROM system_config WHERE configuration_key LIKE 'YorvoLabsCanary.%'",
        );
    }

    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        $prefix = RoutePrefix::fromEnv()->withLeadingSlash();

        $controllerDir = $this->getPath() . '/Controller';
        $routes->import($controllerDir . '/Health/', 'attribute')->prefix($prefix);
        $routes->import($controllerDir . '/Cache/', 'attribute')->prefix($prefix);
        $routes->import($controllerDir . '/Deploy/', 'attribute')->prefix($prefix);
        $routes->import($controllerDir . '/Diagnostics/', 'attribute')->prefix($prefix);

        // Admin proxy routes live at /api/_action/canary/* — no Canary prefix applied.
        $adminDir = $this->getPath() . '/Administration';
        $routes->import($adminDir . '/Controller/', 'attribute');
    }
}
