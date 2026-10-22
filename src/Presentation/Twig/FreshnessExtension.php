<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Application\Service\ConfigurationStore;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FreshnessExtension extends AbstractExtension implements ResetInterface
{
    private ?\DateTimeImmutable $cutoff = null;

    public function __construct(private ConfigurationStore $configuration)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('analysis_is_stale', $this->isStale(...))];
    }

    public function isStale(?\DateTimeImmutable $verifiedAt): bool
    {
        if (null === $verifiedAt) {
            return true;
        }
        $this->cutoff ??= new \DateTimeImmutable('-'.max(1, $this->configuration->values()['staleDays']).' days');

        return $verifiedAt < $this->cutoff;
    }

    public function reset(): void
    {
        $this->cutoff = null;
    }
}
