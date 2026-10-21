<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Message\RefreshIssue;
use App\Application\Service\ConfigurationStore;
use App\Application\Service\RequestJobs;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:schedule', description: 'Mettre en file les synchronisations et analyses arrivées à échéance.')]
final class ScheduleCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;

    public function __construct(private EntityManagerInterface $em, private RequestJobs $jobs, private ConfigurationStore $configuration, private LockFactory $locks, private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Répéter toutes les 60 secondes jusqu’à SIGTERM/SIGINT.');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $lock = $this->locks->createLock('scheduler-tick', 300, false);
            if ($lock->acquire()) {
                try {
                    $this->tick($output, $lock);
                } finally {
                    $lock->release();
                    $this->em->clear();
                }
            } else {
                $output->writeln('Un autre planificateur exécute déjà ce passage.');
            }
            if (!$input->getOption('watch')) {
                break;
            }
            for ($second = 0; $second < 60 && !$this->stopping; ++$second) {
                sleep(1);
            }
        } while (!$this->stopping);

        return Command::SUCCESS;
    }

    private function tick(OutputInterface $output, LockInterface $lock): void
    {
        $now = new \DateTimeImmutable();
        $syncs = 0;
        $scans = 0;
        $cursor = '00000000-0000-0000-0000-000000000000';
        do {
            $connections = $this->em->createQueryBuilder()->select('c')->from(ProviderConnection::class, 'c')
                ->where('c.active = true')->andWhere('c.id > :cursor')->setParameter('cursor', $cursor)
                ->andWhere('c.nextSyncAt IS NULL OR c.nextSyncAt <= :now')->setParameter('now', $now)
                ->orderBy('c.id', 'ASC')->setMaxResults(1000)->getQuery()->getResult();
            if (!is_array($connections)) {
                throw new \LogicException('Expected a connection result list.');
            }
            $lock->refresh();
            foreach ($connections as $connection) {
                if (!$connection instanceof ProviderConnection) {
                    continue;
                }
                $cursor = $connection->id;
                $this->jobs->synchronize($connection, 'scheduler');
                $connection->nextSyncAt = $now->modify('+'.max(1, $connection->frequencyHours).' hours');
                $this->em->flush();
                ++$syncs;
            }
        } while (1000 === count($connections) && !$this->stopping);
        $frequency = $this->configuration->values()['frequencyHours'];
        $cutoff = $now->modify('-'.max(1, $frequency).' hours');
        $cursor = '00000000-0000-0000-0000-000000000000';
        do {
            $repositories = $this->em->createQueryBuilder()->select('r', 'c')->from(Repository::class, 'r')
                ->join('r.connection', 'c')->where('c.active = true')->andWhere('r.monitored = true')->andWhere('r.archived = false')->andWhere('r.accessible = true')
                ->andWhere('r.id > :cursor')->setParameter('cursor', $cursor)->orderBy('r.id', 'ASC')->setMaxResults(1000)->getQuery()->getResult();
            if (!is_array($repositories)) {
                throw new \LogicException('Expected a repository result list.');
            }
            $lock->refresh();
            $ids = [];
            foreach ($repositories as $repository) {
                if ($repository instanceof Repository) {
                    $ids[] = $repository->id;
                }
            }
            $latest = [];
            if ([] !== $ids) {
                $rows = $this->em->createQueryBuilder()->select('IDENTITY(s.repository) AS repositoryId', 's.branch AS branch', 'MAX(s.createdAt) AS latest')
                    ->from(Scan::class, 's')->where('s.repository IN (:ids)')->setParameter('ids', $ids)->groupBy('s.repository', 's.branch')->getQuery()->getArrayResult();
                foreach ($rows as $row) {
                    if (is_array($row) && is_string($row['repositoryId'] ?? null) && is_string($row['branch'] ?? null) && is_string($row['latest'] ?? null)) {
                        $latest[$row['repositoryId']][$row['branch']] = new \DateTimeImmutable($row['latest']);
                    }
                }
            }
            foreach ($repositories as $repository) {
                if (!$repository instanceof Repository) {
                    continue;
                }
                $cursor = $repository->id;
                foreach ($this->jobs->branches($repository) as $branch) {
                    if (($latest[$repository->id][$branch] ?? null) > $cutoff) {
                        continue;
                    }
                    try {
                        $this->jobs->scan($repository, 'scheduler', $branch);
                        ++$scans;
                    } catch (\DomainException) {
                        $output->writeln(sprintf('Application %s ignorée : périmètre de surveillance modifié.', $repository->id));
                    }
                }
            }
        } while (1000 === count($repositories) && !$this->stopping);
        $cursor = '00000000-0000-0000-0000-000000000000';
        $issuesQueued = 0;
        do {
            $issues = $this->em->createQueryBuilder()->select('i')->from(IssueTracking::class, 'i')
                ->join('i.repository', 'r')->join('r.connection', 'c')->where('c.active = true')
                ->andWhere('i.id > :cursor')->setParameter('cursor', $cursor)
                ->andWhere("i.number <> ''")->andWhere('i.nextRefreshAt IS NULL OR i.nextRefreshAt <= :now')->setParameter('now', $now)
                ->orderBy('i.id', 'ASC')->setMaxResults(1000)->getQuery()->getResult();
            if (!is_array($issues)) {
                throw new \LogicException('Expected an issue result list.');
            }
            $lock->refresh();
            foreach ($issues as $issue) {
                if (!$issue instanceof IssueTracking) {
                    continue;
                }
                $cursor = $issue->id;
                $this->em->wrapInTransaction(function () use ($issue, $now): void {
                    $issue->nextRefreshAt = $now->modify('+6 hours');
                    $this->em->flush();
                    $this->bus->dispatch(new RefreshIssue($issue->id));
                });
                ++$issuesQueued;
            }
        } while (1000 === count($issues) && !$this->stopping);
        $output->writeln(sprintf('%d actualisation(s) d’issue mises en file.', $issuesQueued));
        $output->writeln(sprintf('%d synchronisation(s), %d analyse(s) mises en file.', $syncs, $scans));
    }
}
