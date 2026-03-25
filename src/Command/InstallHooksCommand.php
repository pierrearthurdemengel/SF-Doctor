<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Command;

use PierreArthur\SfDoctor\Git\PreCommitHook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sf-doctor:install-hooks',
    description: 'Installe le hook pre-commit Git qui lance SF-Doctor avant chaque commit',
)]
final class InstallHooksCommand extends Command
{
    public function __construct(
        private readonly string $projectPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uninstall', null, InputOption::VALUE_NONE, 'Desinstaller le hook pre-commit')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hook = new PreCommitHook();

        if ($input->getOption('uninstall')) {
            return $this->handleUninstall($io, $hook);
        }

        return $this->handleInstall($io, $hook);
    }

    private function handleInstall(SymfonyStyle $io, PreCommitHook $hook): int
    {
        $hookPath = $hook->install($this->projectPath);

        if ($hookPath === null) {
            $gitDir = $this->projectPath . '/.git';
            if (!is_dir($gitDir)) {
                $io->error('Ce repertoire n\'est pas un depot Git (.git/ absent).');
                return Command::FAILURE;
            }

            $existingHook = $gitDir . '/hooks/pre-commit';
            if (file_exists($existingHook) && !$hook->isSfDoctorHook($existingHook)) {
                $io->error('Un hook pre-commit existe deja et n\'a pas ete installe par SF-Doctor. Installation annulee.');
                return Command::FAILURE;
            }

            $io->error('Impossible d\'installer le hook.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Hook pre-commit installe dans %s', $hookPath));
        $io->text('SF-Doctor sera lance avant chaque commit.');
        $io->text('Pour desactiver : <comment>bin/console sf-doctor:install-hooks --uninstall</comment>');

        return Command::SUCCESS;
    }

    private function handleUninstall(SymfonyStyle $io, PreCommitHook $hook): int
    {
        if ($hook->uninstall($this->projectPath)) {
            $io->success('Hook pre-commit SF-Doctor desinstalle.');
            return Command::SUCCESS;
        }

        $hookPath = $this->projectPath . '/.git/hooks/pre-commit';
        if (file_exists($hookPath) && !$hook->isSfDoctorHook($hookPath)) {
            $io->warning('Le hook pre-commit n\'a pas ete installe par SF-Doctor. Rien a desinstaller.');
        } else {
            $io->warning('Aucun hook pre-commit SF-Doctor trouve.');
        }

        return Command::SUCCESS;
    }
}
