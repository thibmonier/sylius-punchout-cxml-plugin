<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Form\Extension;

use Sylius\Bundle\ProductBundle\Form\Type\ProductType;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Tmonier\SyliusPunchoutCxmlPlugin\Classification\ClassificationManager;
use Tmonier\SyliusPunchoutCxmlPlugin\Form\Type\Admin\ClassificationType;

/** UNSPSC code and unit of measure on the product form (US-005). */
final class ProductClassificationExtension extends AbstractTypeExtension
{
    public const FIELD = 'tmonierPunchout';

    public function __construct(private readonly ClassificationManager $manager)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $product = $event->getData();
                $event->getForm()->add(self::FIELD, ClassificationType::class, [
                    'mapped' => false,
                    'with_unit' => true,
                    'data' => $product instanceof ProductInterface ? $this->manager->loadProduct($product) : ['unspsc' => null, 'unit' => null],
                ]);
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $product = $event->getData();
                $form = $event->getForm();
                if (!$product instanceof ProductInterface || !$form->has(self::FIELD) || !$form->isValid()) {
                    return;
                }
                $data = $form->get(self::FIELD)->getData();
                $data = is_array($data) ? $data : [];
                $this->manager->saveProduct(
                    $product,
                    is_string($data['unspsc'] ?? null) && $data['unspsc'] !== '' ? $data['unspsc'] : null,
                    is_string($data['unit'] ?? null) && $data['unit'] !== '' ? $data['unit'] : null,
                );
            }, -100)
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [ProductType::class];
    }
}
