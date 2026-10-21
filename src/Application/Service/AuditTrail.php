<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AuditTrail
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function record(string $action, string $subjectId, string $actor = 'system'): void
    {
        $event = new AuditEvent();
        $event->action = $action;
        $event->subjectId = $subjectId;
        $event->actor = $actor;
        $this->em->persist($event);
    }
}
