<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Model\ProviderConnection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom de la connexion', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)]])
            ->add('provider', ChoiceType::class, ['label' => 'Fournisseur', 'choices' => ['GitHub' => 'github', 'GitLab' => 'gitlab']])
            ->add('baseUrl', UrlType::class, ['label' => 'URL de l’instance', 'constraints' => [new Assert\NotBlank(), new Assert\Url(protocols: ['https']), new Assert\Length(max: 255)]])
            ->add('token', PasswordType::class, ['label' => 'Jeton d’accès (vide pour conserver le jeton existant)', 'mapped' => false, 'always_empty' => true, 'required' => $options['new_connection'], 'constraints' => $options['new_connection'] ? [new Assert\NotBlank(), new Assert\Length(max: 4096), new Assert\Regex(pattern: '/[\r\n]/', match: false)] : [new Assert\Length(max: 4096), new Assert\Regex(pattern: '/[\r\n]/', match: false)]])
            ->add('branch', TextType::class, ['label' => 'Branches à analyser (séparées par des virgules)', 'required' => false, 'constraints' => [new Assert\Length(max: 255), new Assert\Regex(pattern: '/[\x00-\x1f\x7f]/', match: false)]])
            ->add('frequencyHours', IntegerType::class, ['label' => 'Fréquence de synchronisation (heures)', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 720)]])
            ->add('active', CheckboxType::class, ['label' => 'Connexion active', 'required' => false]);
        foreach (['organizations' => 'Organisations ou groupes autorisés', 'includedRepositories' => 'Repositories inclus', 'excludedRepositories' => 'Repositories exclus'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label.' (séparés par des virgules)', 'required' => false, 'constraints' => [new Assert\Count(max: 1000), new Assert\All([new Assert\Length(max: 255)])]]);
            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (mixed $values): string => is_array($values) ? implode(', ', array_filter($values, is_string(...))) : '',
                static fn (mixed $value): array => array_values(array_unique(array_filter(array_map('trim', explode(',', is_string($value) ? $value : '')), static fn (string $item): bool => '' !== $item))),
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProviderConnection::class, 'new_connection' => false]);
        $resolver->setAllowedTypes('new_connection', 'bool');
    }
}
