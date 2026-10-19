<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoLabs\Canary\Auth\TokenScope;

/**
 * Generates a cryptographically random bearer token for a given Canary scope.
 *
 * Usage:
 *   bin/console canary:token:generate monitoring
 *   bin/console canary:token:generate admin --length=64
 *
 * Output is a ready-to-paste .env snippet, e.g.:
 *   CANARY_TOKEN_MONITORING=a3f8...
 *
 * The generated token is never stored — run the command once and save the
 * output to your secret manager / .env file immediately.
 */
#[AsCommand(
    name: 'canary:token:generate',
    description: 'Generate a Canary bearer token for a given scope',
)]
final class GenerateTokenCommand extends Command
{
    private const MIN_BYTE_LENGTH = 32;

    protected function configure(): void
    {
        $validScopes = array_column(TokenScope::cases(), 'value');

        $this
            ->addArgument(
                'scope',
                InputArgument::REQUIRED,
                'Token scope: ' . implode(' | ', $validScopes),
            )
            ->addOption(
                'length',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf('Random byte length (min %d, output is hex so length × 2 chars)', self::MIN_BYTE_LENGTH),
                self::MIN_BYTE_LENGTH,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $scopeValue = (string) $input->getArgument('scope');
        $scope = TokenScope::tryFrom(strtolower($scopeValue));

        if ($scope === null) {
            $validScopes = array_column(TokenScope::cases(), 'value');
            $io->error(sprintf(
                'Invalid scope "%s". Valid values: %s',
                $scopeValue,
                implode(', ', $validScopes),
            ));
            return Command::FAILURE;
        }

        $byteLength = max(self::MIN_BYTE_LENGTH, (int) $input->getOption('length'));
        $token = bin2hex(random_bytes($byteLength));

        $io->section('Generated token for: ' . $scope->label());

        $io->comment('Add the following line to your .env file or secret manager:');
        $io->writeln(sprintf('<info>%s=%s</info>', $scope->envVar(), $token));
        $io->newLine();
        $io->note('This token will not be shown again. Save it now.');

        return Command::SUCCESS;
    }
}
