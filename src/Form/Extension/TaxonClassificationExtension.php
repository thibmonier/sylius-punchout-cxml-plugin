<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Form\Extension;

use Sylius\Bundle\TaxonomyBundle\Form\Type\TaxonType;
use Sylius\Component\Core\Model\TaxonInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Tmonier\SyliusPunchoutCxmlPlugin\Classification\ClassificationManager;
use Tmonier\SyliusPunchoutCxmlPlugin\Form\Type\Admin\ClassificationType;

/** UNSPSC code on the taxon form, inherited by its products and sub-taxons (US-005). */
final class TaxonClassificationExtension extends AbstractTypeExtension
{
    public const FIELD = 'tmonierPunchout';

    public function __construct(private readonly ClassificationManager $manager)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $taxon = $event->getData();
                $event->getForm()->add(self::FIELD, ClassificationType::class, [
                    'mapped' => false,
                    'data' => ['unspsc' => $taxon instanceof TaxonInterface ? $this->manager->loadTaxon($taxon) : null],
                ]);
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $taxon = $event->getData();
                $form = $event->getForm();
                if (!$taxon instanceof TaxonInterface || !$form->has(self::FIELD) || !$form->isValid()) {
                    return;
                }
                $data = $form->get(self::FIELD)->getData();
                $unspsc = is_array($data) && is_string($data['unspsc'] ?? null) && $data['unspsc'] !== '' ? $data['unspsc'] : null;
                $this->manager->saveTaxon($taxon, $unspsc);
            }, -100)
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [TaxonType::class];
    }
}
