<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/diag/env
 *
 * Reports PHP runtime info, Shopware version, and a masked snapshot of
 * environment variables. Sensitive values (tokens, passwords, DSNs) are
 * replaced with [REDACTED] before being returned.
 *
 * Requires DIAGNOSTICS scope token.
 */
#[Route(
    '/diag/env',
    name: 'canary.diag.env',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class EnvController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly SensitiveValueMasker $masker,
        private readonly string $shopwareVersion,
        private readonly string $appEnv,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        return $this->responseFactory->ok([
            'php'      => $this->phpInfo(),
            'shopware' => $this->shopwareInfo(),
            'env'      => $this->maskedEnv(),
        ], $requestId);
    }

    /** @return array<string, mixed> */
    private function phpInfo(): array
    {
        return [
            'version'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'memory_limit'       => ini_get('memory_limit') ?: 'unknown',
            'max_execution_time' => (int) (ini_get('max_execution_time') ?: 0),
            'extensions'         => get_loaded_extensions(),
        ];
    }

    /** @return array<string, mixed> */
    private function shopwareInfo(): array
    {
        return [
            'version' => $this->shopwareVersion,
            'app_env' => $this->appEnv,
        ];
    }

    /** @return array<string, mixed> */
    private function maskedEnv(): array
    {
        $raw = array_merge($_SERVER, $_ENV);

        // Keep only ALL_CAPS_UNDERSCORE keys (skip HTTP_*, SERVER_*, etc.)
        $filtered = array_filter(
            $raw,
            static fn(string $key): bool => (bool) preg_match('/^[A-Z][A-Z0-9_]*$/', $key),
            ARRAY_FILTER_USE_KEY,
        );

        /** @var array<string, mixed> $filtered */
        return $this->masker->mask($filtered);
    }
}
