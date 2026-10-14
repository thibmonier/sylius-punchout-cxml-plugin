<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Form\Type\Admin;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class BuyerType extends AbstractResourceType
{
    /** @param list<string> $validationGroups */
    public function __construct(string $dataClass, array $validationGroups = [])
    {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'tmonier_punchout.form.buyer.name',
                'required' => true,
            ])
            ->add('protocol', ChoiceType::class, [
                'label' => 'tmonier_punchout.form.buyer.protocol.label',
                'choices' => [
                    'tmonier_punchout.form.buyer.protocol.cxml' => 'cxml',
                    'tmonier_punchout.form.buyer.protocol.oci' => 'oci',
                ],
                'required' => true,
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'tmonier_punchout.form.buyer.enabled',
                'required' => false,
            ])
            ->add('allowedReturnDomains', CollectionType::class, [
                'label' => 'tmonier_punchout.form.buyer.allowed_return_domains',
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_punchout_buyer';
    }
}
