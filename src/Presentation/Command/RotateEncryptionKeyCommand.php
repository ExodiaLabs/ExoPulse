<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Service\RotateEncryptionKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:security:rotate-encryption-key', description: 'Vérifier ou rechiffrer les identifiants Git pendant une maintenance.')]
final class RotateEncryptionKeyCommand extends Command
{
    public function __construct(private readonly RotateEncryptionKey $rotation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('new-key-file', null, InputOption::VALUE_REQUIRED, 'Fichier local contenant la nouvelle clé base64 ; jamais sa valeur.');
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Rechiffrer effectivement, uniquement après sauvegarde et arrêt du web, des workers et du planificateur.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('new-key-file');
        if (!is_string($file) || '' === $file) {
            $output->writeln('<error>Indiquez le fichier local avec --new-key-file.</error>');

            return Command::INVALID;
        }
        $execute = true === $input->getOption('execute');
        try {
            $count = $this->rotation->rotate($file, $execute);
        } catch (\Throwable) {
            $output->writeln('<error>Rotation refusée ou interrompue. Maintenez la maintenance et vérifiez le fichier, la clé actuelle et la base avant toute reprise.</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf($execute ? '%d connexion(s) rechiffrée(s).' : 'Simulation : %d connexion(s) vérifiée(s), aucune modification.', $count));

        return Command::SUCCESS;
    }
}
