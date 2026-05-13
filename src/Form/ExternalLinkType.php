<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire CRUD pour les liens externes du lanceur d'apps.
 *
 * Pas de mapping direct sur l'entité (qui est encapsulée derrière des
 * setters typés) : on travaille avec un DTO simple `ExternalLinkInput`
 * pour bénéficier de la validation Symfony, puis l'application l'apparie
 * à l'entité dans le contrôleur (création) ou la met à jour (édition).
 *
 * @extends AbstractType<ExternalLinkInput>
 */
final class ExternalLinkType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 1, max: 64),
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'default_protocol' => 'https',
                'constraints' => [
                    new Assert\NotBlank(),
                    // requireTld désactivé pour autoriser les hostnames sans
                    // TLD (`http://intranet`, `http://localhost:8025`, etc.)
                    // qui sont courants pour des outils internes auto-hébergés.
                    new Assert\Url(requireTld: false),
                    new Assert\Length(max: 1024),
                ],
            ])
            ->add('icon', TextType::class, [
                'label' => 'Icône (emoji ou lettre)',
                'required' => false,
                'constraints' => [
                    new Assert\Length(max: 128),
                ],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description (tooltip)',
                'required' => false,
                'constraints' => [
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Position (tri ASC)',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\GreaterThanOrEqual(0),
                ],
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExternalLinkInput::class,
        ]);
    }
}
