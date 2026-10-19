<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Bridge\Shopware\Command\GenerateTokenCommand;

final class GenerateTokenCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester(new GenerateTokenCommand());
    }

    public function testSuccessWithValidScope(): void
    {
        $exitCode = $this->tester->execute(['scope' => 'monitoring']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('CANARY_TOKEN_MONITORING=', $this->tester->getDisplay());
    }

    public function testOutputTokenIsHexString(): void
    {
        $this->tester->execute(['scope' => 'monitoring']);

        preg_match('/CANARY_TOKEN_MONITORING=([a-f0-9]+)/', $this->tester->getDisplay(), $matches);

        self::assertNotEmpty($matches[1] ?? '');
        self::assertSame(strlen($matches[1]), 64); // 32 bytes × 2 hex chars
    }

    public function testCustomLengthOption(): void
    {
        $this->tester->execute(['scope' => 'admin', '--length' => '64']);

        preg_match('/CANARY_TOKEN_ADMIN=([a-f0-9]+)/', $this->tester->getDisplay(), $matches);

        self::assertSame(128, strlen($matches[1] ?? '')); // 64 bytes × 2
    }

    public function testLengthBelowMinimumIsClampedTo32(): void
    {
        $this->tester->execute(['scope' => 'cache', '--length' => '4']);

        preg_match('/CANARY_TOKEN_CACHE=([a-f0-9]+)/', $this->tester->getDisplay(), $matches);

        self::assertGreaterThanOrEqual(64, strlen($matches[1] ?? '')); // min 32 bytes = 64 hex
    }

    public function testFailureWithInvalidScope(): void
    {
        $exitCode = $this->tester->execute(['scope' => 'invalid_scope']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid scope', $this->tester->getDisplay());
    }

    public function testFailureMessageListsValidScopes(): void
    {
        $this->tester->execute(['scope' => 'bogus']);

        $display = $this->tester->getDisplay();

        foreach (TokenScope::cases() as $scope) {
            self::assertStringContainsString($scope->value, $display);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allScopeProvider')]
    public function testAllScopesSucceed(TokenScope $scope): void
    {
        $exitCode = $this->tester->execute(['scope' => $scope->value]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString($scope->envVar() . '=', $this->tester->getDisplay());
    }

    /** @return iterable<string, array{TokenScope}> */
    public static function allScopeProvider(): iterable
    {
        foreach (TokenScope::cases() as $scope) {
            yield $scope->value => [$scope];
        }
    }

    public function testTokensAreUniqueAcrossRuns(): void
    {
        $this->tester->execute(['scope' => 'monitoring']);
        preg_match('/CANARY_TOKEN_MONITORING=([a-f0-9]+)/', $this->tester->getDisplay(), $m1);

        $this->tester->execute(['scope' => 'monitoring']);
        preg_match('/CANARY_TOKEN_MONITORING=([a-f0-9]+)/', $this->tester->getDisplay(), $m2);

        self::assertNotSame($m1[1] ?? 'a', $m2[1] ?? 'b');
    }
}
