<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/diag/plugins
 *
 * Lists all installed and uninstalled plugins with their name, version,
 * active status, and any available upgrade version.
 *
 * Requires DIAGNOSTICS scope token.
 */
#[Route(
    '/diag/plugins',
    name: 'canary.diag.plugins',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class PluginsController
{
    /**
     * @param EntityRepository<PluginCollection> $pluginRepository
     */
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly EntityRepository $pluginRepository,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        return $this->responseFactory->ok([
            'plugins' => $this->fetchPlugins(),
        ], $requestId);
    }

    /** @return list<array<string, mixed>> */
    private function fetchPlugins(): array
    {
        try {
            $criteria = new Criteria();
            $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

            $result  = $this->pluginRepository->search($criteria, Context::createDefaultContext());
            $plugins = [];

            foreach ($result->getEntities() as $plugin) {
                /** @var PluginEntity $plugin */
                $plugins[] = [
                    'name'            => $plugin->getName(),
                    'version'         => $plugin->getVersion(),
                    'active'          => $plugin->getActive(),
                    'installed'       => $plugin->getInstalledAt() !== null,
                    'upgrade_version' => $plugin->getUpgradeVersion(),
                ];
            }

            return $plugins;
        } catch (\Throwable) {
            return [];
        }
    }
}
