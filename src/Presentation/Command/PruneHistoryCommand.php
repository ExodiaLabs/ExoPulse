<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Service\AuditTrail;
use App\Application\Service\ConfigurationStore;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:history:prune', description: 'Prévisualiser ou purger les anciennes analyses terminées hors résultat courant.')]
final class PruneHistoryCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private ConfigurationStore $configuration, private AuditTrail $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Supprimer effectivement les analyses éligibles.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $this->configuration->values()['retentionDays'];
        $cutoff = new \DateTimeImmutable('-'.max(1, $days).' days');
        $condition = 's.createdAt < :cutoff AND s.status IN (:statuses) AND s.id NOT IN (SELECT IDENTITY(r.latestScan) FROM '.Repository::class.' r WHERE r.latestScan IS NOT NULL)';
        $parameters = ['cutoff' => $cutoff, 'statuses' => ['completed', 'partial', 'failed']];
        $count = $this->em->createQuery('SELECT COUNT(s.id) FROM '.Scan::class.' s WHERE '.$condition)->setParameters($parameters)->getSingleScalarResult();
        if (!$input->getOption('execute')) {
            $output->writeln(sprintf('Simulation : %s analyse(s) éligible(s), rétention %d jours. Ajouter --execute pour supprimer.', $count, $days));

            return Command::SUCCESS;
        }
        $deleted = $this->em->wrapInTransaction(function () use ($condition, $parameters): mixed {
            $deleted = $this->em->createQuery('DELETE FROM '.Scan::class.' s WHERE '.$condition)->setParameters($parameters)->execute();
            $this->audit->record('history.pruned', 'scan-history', 'console');
            $this->em->flush();

            return $deleted;
        });
        if (!is_int($deleted)) {
            throw new \LogicException('Unexpected deletion count.');
        }
        $output->writeln(sprintf('%d analyse(s) supprimée(s).', $deleted));

        return Command::SUCCESS;
    }
}
