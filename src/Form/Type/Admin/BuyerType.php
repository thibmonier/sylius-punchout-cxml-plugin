<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Form\Type\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Admin form of a PunchOut buyer: its `From` and `Sender` identities (one "domain identity" per line,
 * several allowed, US-002), the Sylius customer the sessions log in as (mandatory, ADR-0003), the
 * allowed return domains (US-004) and iframe origins (US-016).
 */
final class BuyerType extends AbstractResourceType
{
    private const IDENTITY_FIELDS = [
        BuyerIdentity::ROLE_FROM => 'fromIdentities',
        BuyerIdentity::ROLE_SENDER => 'senderIdentities',
    ];

    /**
     * @param list<string> $validationGroups
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     */
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private readonly RepositoryInterface $customerRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'sylius.ui.code',
                'empty_data' => '',
                'constraints' => [new NotBlank(), new Length(max: 64), new Regex(pattern: '/^[a-z0-9_-]+$/', message: 'tmonier_punchout.buyer.code.format')],
            ])
            ->add('name', TextType::class, [
                'label' => 'sylius.ui.name',
                'empty_data' => '',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('enabled', CheckboxType::class, ['label' => 'sylius.ui.enabled', 'required' => false])
            ->add('protocol', ChoiceType::class, [
                'label' => 'tmonier_punchout.form.buyer.protocol.label',
                'choices' => ['tmonier_punchout.form.buyer.protocol.cxml' => 'cxml', 'tmonier_punchout.form.buyer.protocol.oci' => 'oci'],
            ])
            ->add('channel', ChannelChoiceType::class, [
                'label' => 'sylius.ui.channel',
                'constraints' => [new NotNull(message: 'tmonier_punchout.buyer.channel.not_blank')],
            ])
            ->add('customer', EmailType::class, [
                'label' => 'tmonier_punchout.form.buyer.customer',
                'help' => 'tmonier_punchout.form.buyer.customer_help',
                'invalid_message' => 'tmonier_punchout.buyer.customer.unknown',
                'constraints' => [new NotNull(message: 'tmonier_punchout.buyer.customer.not_blank')],
            ])
            ->add('returnDomains', TextareaType::class, [
                'label' => 'tmonier_punchout.form.buyer.return_domains',
                'help' => 'tmonier_punchout.form.buyer.return_domains_help',
                'constraints' => [
                    new Count(min: 1, minMessage: 'tmonier_punchout.buyer.return_domains.not_blank'),
                    new All([new Regex(pattern: '/^(\*\.)?([a-z0-9-]+\.)+[a-z][a-z0-9-]+$|^localhost$/', message: 'tmonier_punchout.buyer.return_domains.invalid')]),
                ],
            ])
            ->add('includeTax', CheckboxType::class, ['label' => 'tmonier_punchout.form.buyer.include_tax', 'required' => false])
            ->add('includeShipping', CheckboxType::class, ['label' => 'tmonier_punchout.form.buyer.include_shipping', 'required' => false])
            ->add('iframeOrigins', TextareaType::class, [
                'label' => 'tmonier_punchout.form.buyer.iframe_origins',
                'help' => 'tmonier_punchout.form.buyer.iframe_origins_help',
                'required' => false,
                'constraints' => [new All([new Regex(pattern: '#^https://([a-z0-9-]+\.)+[a-z][a-z0-9-]+(:\d{1,5})?$#', message: 'tmonier_punchout.buyer.iframe_origins.invalid')])],
            ])
        ;

        foreach (self::IDENTITY_FIELDS as $field) {
            $builder->add($field, TextareaType::class, [
                'mapped' => false,
                'label' => 'tmonier_punchout.form.buyer.' . $field,
                'help' => 'tmonier_punchout.form.buyer.identities_format',
                'attr' => ['rows' => 2],
                'constraints' => [new NotBlank()],
            ]);
        }

        $builder->get('customer')->addModelTransformer(new CallbackTransformer(
            static fn (?CustomerInterface $customer): ?string => $customer?->getEmail(),
            function (?string $email): ?CustomerInterface {
                if ($email === null || trim($email) === '') {
                    return null;
                }
                $customer = $this->customerRepository->findOneBy(['emailCanonical' => mb_strtolower(trim($email))]);
                if (!$customer instanceof CustomerInterface) {
                    throw new TransformationFailedException('Unknown customer');
                }

                return $customer;
            },
        ));

        // One value per line (or separated by spaces / commas), lowercased, without duplicates.
        $lines = new CallbackTransformer(
            static fn (?array $values): string => implode("\n", array_filter($values ?? [], is_string(...))),
            /** @return list<string> */
            static fn (?string $text): array => array_values(array_unique(array_filter(array_map(
                static fn (string $line): string => rtrim(mb_strtolower(trim($line)), '/'),
                preg_split('/[\s,]+/', (string) $text) ?: [],
            ), static fn (string $value): bool => $value !== ''))),
        );
        $builder->get('returnDomains')->addModelTransformer($lines);
        $builder->get('iframeOrigins')->addModelTransformer($lines);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->fillIdentities(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->applyIdentities(...));
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_punchout_buyer';
    }

    private function fillIdentities(FormEvent $event): void
    {
        $buyer = $event->getData();
        if (!$buyer instanceof BuyerInterface) {
            return;
        }
        $lines = [];
        foreach ($buyer->getIdentities() as $identity) {
            $lines[$identity->getRole()][] = $identity->getDomain() . ' ' . $identity->getIdentity();
        }
        foreach (self::IDENTITY_FIELDS as $role => $field) {
            $event->getForm()->get($field)->setData(implode("\n", $lines[$role] ?? []));
        }
    }

    private function applyIdentities(FormEvent $event): void
    {
        $buyer = $event->getData();
        $form = $event->getForm();
        if (!$buyer instanceof Buyer || !$form->isValid()) {
            return;
        }

        foreach (self::IDENTITY_FIELDS as $role => $field) {
            $wanted = $this->parseIdentities($form->get($field));
            if ($wanted === null) {
                continue;
            }

            foreach ($buyer->getIdentities()->toArray() as $identity) {
                if ($identity->getRole() === $role && !isset($wanted[mb_strtolower($identity->getDomain()) . ' ' . $identity->getIdentity()])) {
                    $buyer->removeIdentity($identity);
                }
            }
            foreach ($wanted as $key => [$domain, $value]) {
                if ($this->hasIdentity($buyer, $role, $key)) {
                    continue;
                }
                if ($this->identityBelongsToAnotherBuyer($buyer, $role, $domain, $value)) {
                    $form->get($field)->addError(new FormError($this->translator->trans('tmonier_punchout.buyer.identity.taken', [], 'validators')));

                    continue;
                }
                $identity = new BuyerIdentity();
                $identity->setRole($role);
                $identity->setDomain($domain);
                $identity->setIdentity($value);
                $buyer->addIdentity($identity);
            }
        }
    }

    /**
     * "domain identity" pairs, one per line, keyed by the lowercased domain and the identity; null (with
     * an error on the field) when a line is not a pair.
     *
     * @param FormInterface<mixed> $field
     *
     * @return array<string, array{string, string}>|null
     */
    private function parseIdentities(FormInterface $field): ?array
    {
        $identities = [];
        foreach (preg_split('/\R/', $this->text($field)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) !== 2 || mb_strlen($parts[0]) > 64 || mb_strlen($parts[1]) > 255) {
                $field->addError(new FormError($this->translator->trans('tmonier_punchout.buyer.identity.format', [], 'validators')));

                return null;
            }
            $identities[mb_strtolower($parts[0]) . ' ' . $parts[1]] = [$parts[0], $parts[1]];
        }

        return $identities;
    }

    private function hasIdentity(Buyer $buyer, string $role, string $key): bool
    {
        foreach ($buyer->getIdentities() as $identity) {
            if ($identity->getRole() === $role && mb_strtolower($identity->getDomain()) . ' ' . $identity->getIdentity() === $key) {
                return true;
            }
        }

        return false;
    }

    /** An identity designates a single buyer (unique index on role, domain and identity). */
    private function identityBelongsToAnotherBuyer(Buyer $buyer, string $role, string $domain, string $value): bool
    {
        $owner = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.buyer)')
            ->from(BuyerIdentity::class, 'i')
            ->andWhere('i.role = :role')
            ->andWhere('LOWER(i.domain) = :domain')
            ->andWhere('i.identity = :identity')
            ->setParameter('role', $role)
            ->setParameter('domain', mb_strtolower($domain))
            ->setParameter('identity', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return is_numeric($owner) && (int) $owner !== $buyer->getId();
    }

    /** @param FormInterface<mixed> $field */
    private function text(FormInterface $field): string
    {
        $data = $field->getData();

        return is_string($data) ? $data : '';
    }
}
