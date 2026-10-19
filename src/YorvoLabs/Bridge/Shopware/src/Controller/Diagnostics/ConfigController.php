<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Diagnostics\DiagnosticsRunner;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/diag/config[?namespace=YorvoLabsCanary]
 *
 * Returns a masked snapshot of Shopware SystemConfig keys for the requested
 * namespace alongside built-in validation check results.
 *
 * Requires DIAGNOSTICS scope token.
 */
#[Route(
    '/diag/config',
    name: 'canary.diag.config',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class ConfigController
{
    private const DEFAULT_NAMESPACE = 'YorvoLabsCanary';

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly SensitiveValueMasker $masker,
        private readonly DiagnosticsRunner $runner,
        private readonly Connection $connection,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;
        $namespace = $request->query->getString('namespace', self::DEFAULT_NAMESPACE);

        $snapshot   = $this->fetchSnapshot($namespace);
        $validation = array_map(
            static fn($r) => $r->toArray(),
            $this->runner->run(),
        );

        return $this->responseFactory->ok([
            'namespace'  => $namespace,
            'snapshot'   => $snapshot,
            'validation' => $validation,
        ], $requestId);
    }

    /** @return array<string, mixed> */
    private function fetchSnapshot(string $namespace): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT configuration_key, configuration_value FROM system_config WHERE configuration_key LIKE ?',
                [$namespace . '.%'],
            );
        } catch (\Throwable) {
            return [];
        }

        $raw = [];
        foreach ($rows as $row) {
            $key   = (string) $row['configuration_key'];
            $value = json_decode((string) $row['configuration_value'], true);
            $raw[$key] = $value;
        }

        return $this->masker->mask($raw);
    }
}
