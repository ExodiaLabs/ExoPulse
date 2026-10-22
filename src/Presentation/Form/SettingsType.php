<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('externalEnrichment', ChoiceType::class, [
            'label' => 'Autoriser les catalogues externes',
            'choices' => ['Non : analyse locale uniquement' => 0, 'Oui : enrichir les versions et cycles de vie' => 1],
            'help' => 'Envoie les noms et versions des packages aux registres publics. Aucun contenu de code n’est envoyé. Laissez désactivé pour conserver les noms des dépendances dans ExoPulse.',
            'constraints' => [new Assert\NotNull()],
        ]);
        foreach ([
            'frequencyHours' => ['Fréquence des analyses (heures)', 1, 720],
            'staleDays' => ['Fraîcheur maximale des résultats (jours)', 1, 365],
            'retentionDays' => ['Conservation de l’historique (jours)', 1, 3650],
            'end_of_support' => ['Pénalité : fin de support', 0, 100],
            'end_of_maintenance' => ['Pénalité : fin de maintenance active', 0, 100],
            'major' => ['Pénalité : version majeure', 0, 100],
            'minor' => ['Pénalité : version mineure', 0, 100],
            'patch' => ['Pénalité : correctif', 0, 100],
        ] as $name => [$label, $min, $max]) {
            $builder->add($name, IntegerType::class, ['label' => $label, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: $min, max: $max)]]);
        }
    }
}
