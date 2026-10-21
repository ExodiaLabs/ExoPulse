<?php

declare(strict_types=1);

namespace App\Domain\Git;

final class ProviderException extends \RuntimeException
{
    public function __construct(string $message = 'Le fournisseur Git est indisponible.', public readonly ?int $retryAfter = null, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
