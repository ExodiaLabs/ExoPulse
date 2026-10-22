<?php

declare(strict_types=1);

use App\Application\Message\SynchronizeConnection;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\SyncJob;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

// Explicit isolated target only. Never load a default or local production DATABASE_URL.
$url = getenv('DATABASE_URL');
if (!is_string($url) || '/exopulse_worker_verification_test' !== parse_url($url, PHP_URL_PATH)) {
    fwrite(STDERR, "Définir explicitement DATABASE_URL vers exopulse_worker_verification_test. Aucune autre base n’est autorisée.\n");
    exit(1);
}
$root = dirname(__DIR__);
$environment = array_replace(getenv(), [
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '1',
    'APP_SECRET' => 'synthetic-worker-verification-secret',
    'EXOPULSE_ENCRYPTION_KEY' => base64_encode(str_repeat('v', 32)),
    'ADMIN_IDENTIFIER' => 'synthetic-worker-verifier',
    'ADMIN_PASSWORD_HASH' => '',
    'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=0',
]);
foreach ($environment as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}
// Load committed defaults only; the local secrets file is intentionally not read here.
(new Symfony\Component\Dotenv\Dotenv())->load($root.'/.env');
foreach ($_ENV as $name => $value) {
    if (is_string($name) && is_string($value) && !isset($environment[$name])) {
        $environment[$name] = $value;
    }
}
/**
 * @param list<string>          $arguments
 * @param array<string, string> $environment
 */
function runWorkerVerificationCommand(array $arguments, string $root, array $environment): void
{
    $process = new Process([PHP_BINARY, $root.'/bin/console', ...$arguments, '--env=dev', '--no-interaction'], $root, $environment, timeout: 30);
    $process->mustRun();
}
$count = static function (mixed $value): int {
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    throw new LogicException('Invalid database count.');
};
$kernel = null;
$em = null;
$connectionId = null;
$jobId = null;
$success = false;
$phase = 'migrations';
try {
    runWorkerVerificationCommand(['doctrine:migrations:migrate', '--allow-no-migration'], $root, $environment);
    runWorkerVerificationCommand(['messenger:setup-transports'], $root, $environment);
    $phase = 'initialisation du noyau';
    $kernel = new Kernel('dev', true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof ManagerRegistry) {
        throw new LogicException('Doctrine registry unavailable.');
    }
    $em = $registry->getManager();
    if (!$em instanceof EntityManagerInterface) {
        throw new LogicException('ORM unavailable.');
    }
    if ('exopulse_worker_verification_test' !== $em->getConnection()->getDatabase()) {
        throw new LogicException('Unexpected database.');
    }
    if (0 !== $em->getRepository(ProviderConnection::class)->count([]) || 0 !== $count($em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'))) {
        throw new LogicException('Verification database must not contain application data or messages.');
    }
    $connection = new ProviderConnection();
    $connection->name = 'Synthetic isolated worker verification';
    $connection->active = false;
    $job = new SyncJob();
    $job->connection = $connection;
    $connectionId = $connection->id;
    $jobId = $job->id;
    $em->persist($connection);
    $em->persist($job);
    $em->flush();
    $phase = 'dispatch Messenger';
    $bus = $kernel->getContainer()->get('messenger.default_bus');
    if (!$bus instanceof MessageBusInterface) {
        throw new LogicException('Messenger bus unavailable.');
    }
    $bus->dispatch(new SynchronizeConnection($jobId));
    $phase = 'vérification de persistance';
    $queued = $count($em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE body LIKE ?', ['%'.$jobId.'%']));
    if (1 !== $queued) {
        throw new LogicException('The message was not persisted.');
    }
    fwrite(STDOUT, "Transport Doctrine : un message effectivement persisté.\n");
    $phase = 'worker séparé';
    runWorkerVerificationCommand(['messenger:consume', 'async', '--limit=1', '--time-limit=10', '--memory-limit=128M'], $root, $environment);
    $em->clear();
    $processed = $em->find(SyncJob::class, $jobId);
    $remaining = $count($em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE body LIKE ?', ['%'.$jobId.'%']));
    if (!$processed instanceof SyncJob || 'cancelled' !== $processed->status || null === $processed->finishedAt || 0 !== $remaining) {
        throw new LogicException('Separate worker did not acknowledge and cancel the inactive connection job.');
    }
    fwrite(STDOUT, "Worker PHP séparé : traitement annulé pour connexion inactive, date de fin enregistrée, message acquitté. Aucun appel Git nécessaire.\n");
    $success = true;
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase : '.$phase.' ('.$error::class.").\n");
    fwrite(STDERR, "Échec de la vérification isolée du worker. Aucune sortie de commande ou valeur de configuration divulguée.\n");
} finally {
    if ($em instanceof EntityManagerInterface && null !== $connectionId && null !== $jobId) {
        try {
            $db = $em->getConnection();
            $db->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', ['%'.$jobId.'%']);
            $db->executeStatement('DELETE FROM audit_event WHERE subject_id IN (?, ?)', [$connectionId, $jobId]);
            $db->executeStatement('DELETE FROM provider_connection WHERE id = ?', [$connectionId]);
            fwrite(STDOUT, "Données synthétiques et messages nettoyés dans la base isolée.\n");
        } catch (Throwable) {
            $success = false;
            fwrite(STDERR, "Le nettoyage de la base isolée doit être vérifié.\n");
        }
    }
    $kernel?->shutdown();
}
exit($success ? 0 : 1);
