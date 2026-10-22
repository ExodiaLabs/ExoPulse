<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Service\RepositoryScope;
use App\Application\Service\RequestJobs;
use App\Domain\Model\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:scan:portfolio', description: 'Demander une nouvelle analyse des applications actives du parc.')]
final class ScanPortfolioCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly RequestJobs $jobs, private readonly RepositoryScope $scope)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d’applications (1 à 1000).', '500');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter les demandes sans enregistrer ni lancer de traitement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $output->writeln('<error>La limite doit être comprise entre 1 et 1000.</error>');

            return Command::INVALID;
        }
        /** @var list<Repository> $repositories */
        $repositories = $this->em->createQueryBuilder()->select('r', 'c')->from(Repository::class, 'r')->join('r.connection', 'c')
            ->where('c.active = true')->andWhere('r.accessible = true')->andWhere('r.monitored = true')
            ->orderBy('r.id')->setMaxResults((int) $limit)->getQuery()->getResult();
        $requests = 0;
        $skipped = 0;
        foreach ($repositories as $repository) {
            if (!$this->scope->includes($repository->connection, $repository->name)) {
                ++$skipped;
                continue;
            }
            try {
                foreach ($this->jobs->branches($repository) as $branch) {
                    if (!$input->getOption('dry-run')) {
                        $this->jobs->scan($repository, 'console:portfolio', $branch);
                    }
                    ++$requests;
                }
            } catch (\DomainException) {
                ++$skipped;
            }
        }
        $output->writeln(sprintf('%s Applications sélectionnées : %d ; demandes par branche : %d ; applications ignorées : %d.', $input->getOption('dry-run') ? 'Simulation.' : 'Mise en file (réutilise les analyses déjà en attente).', count($repositories), $requests, $skipped));

        return Command::SUCCESS;
    }
}
