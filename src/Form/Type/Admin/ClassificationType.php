<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Form\Type\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/** PunchOut fields of the product and taxon forms: UNSPSC code (and unit of measure for products). */
final class ClassificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('unspsc', TextType::class, [
            'label' => 'tmonier_punchout.form.classification.unspsc',
            'help' => 'tmonier_punchout.form.classification.unspsc_help',
            'required' => false,
            'constraints' => [new Regex(pattern: '/^\d{8}$/', message: 'tmonier_punchout.classification.unspsc')],
        ]);
        if ($options['with_unit'] === true) {
            $builder->add('unit', TextType::class, [
                'label' => 'tmonier_punchout.form.classification.unit',
                'help' => 'tmonier_punchout.form.classification.unit_help',
                'required' => false,
                'constraints' => [new Regex(pattern: '/^[A-Z0-9]{1,8}$/', message: 'tmonier_punchout.classification.unit')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_unit' => false, 'label' => false]);
        $resolver->setAllowedTypes('with_unit', 'bool');
    }
}
