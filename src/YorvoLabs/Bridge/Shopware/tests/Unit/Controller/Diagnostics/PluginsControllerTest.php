<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\PluginsController;
use YorvoLabs\Canary\Response\ResponseFactory;

final class PluginsControllerTest extends TestCase
{
    /** @param EntityRepository<PluginCollection> $repository */
    private function makeController(EntityRepository $repository): PluginsController
    {
        return new PluginsController(new ResponseFactory(), $repository);
    }

    /**
     * @param EntityRepository<PluginCollection> $repository
     * @return array<string, mixed>
     */
    private function invoke(EntityRepository $repository): array
    {
        $response = ($this->makeController($repository))(Request::create('/canary/diag/plugins', 'GET'));
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** @return EntityRepository<PluginCollection> */
    private function repoWithPlugins(PluginEntity ...$entities): EntityRepository
    {
        $collection = new PluginCollection($entities);

        $searchResult = $this->createStub(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($collection);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('search')->willReturn($searchResult);

        return $repo;
    }

    /** @return EntityRepository<PluginCollection> */
    private function repoThatThrows(): EntityRepository
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('search')->willThrowException(new \RuntimeException('table not found'));
        return $repo;
    }

    #[Test]
    public function it_returns_200_with_plugins_key(): void
    {
        $response = ($this->makeController($this->repoWithPlugins()))(Request::create('/canary/diag/plugins', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('plugins', json_decode((string) $response->getContent(), true)['data']);
    }

    #[Test]
    public function it_returns_plugin_list_with_expected_fields(): void
    {
        $entity = $this->createStub(PluginEntity::class);
        $entity->method('getName')->willReturn('YorvoLabsCanary');
        $entity->method('getVersion')->willReturn('1.0.0');
        $entity->method('getActive')->willReturn(true);
        $entity->method('getInstalledAt')->willReturn(new \DateTimeImmutable('2026-01-01'));
        $entity->method('getUpgradeVersion')->willReturn(null);

        $data   = $this->invoke($this->repoWithPlugins($entity));
        $plugin = $data['plugins'][0];

        self::assertSame('YorvoLabsCanary', $plugin['name']);
        self::assertSame('1.0.0', $plugin['version']);
        self::assertTrue($plugin['active']);
        self::assertTrue($plugin['installed']);
        self::assertNull($plugin['upgrade_version']);
    }

    #[Test]
    public function it_marks_plugin_as_not_installed_when_installed_at_is_null(): void
    {
        $entity = $this->createStub(PluginEntity::class);
        $entity->method('getName')->willReturn('SomePlugin');
        $entity->method('getVersion')->willReturn('0.5.0');
        $entity->method('getActive')->willReturn(false);
        $entity->method('getInstalledAt')->willReturn(null);
        $entity->method('getUpgradeVersion')->willReturn('1.0.0');

        $data = $this->invoke($this->repoWithPlugins($entity));

        self::assertFalse($data['plugins'][0]['installed']);
        self::assertFalse($data['plugins'][0]['active']);
        self::assertSame('1.0.0', $data['plugins'][0]['upgrade_version']);
    }

    #[Test]
    public function it_returns_empty_list_when_repository_throws(): void
    {
        $data = $this->invoke($this->repoThatThrows());

        self::assertSame([], $data['plugins']);
    }
}
