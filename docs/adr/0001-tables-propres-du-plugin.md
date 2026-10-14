# ADR-0001 — Données du plugin dans des tables propres

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Le plugin doit stocker acheteurs, sessions, journal et classifications UNSPSC / unités. Ajouter des champs aux entités Sylius (Product, Taxon, Customer) obligerait l'application hôte à modifier ses entités (traits, interfaces, migrations), ce qui freine l'adoption et complique les mises à jour.

## Décision

Toutes les données vivent dans des tables `tmonier_punchout_*` reliées par clés étrangères aux entités Sylius (`channel`, `customer`, `order`, `taxon`, `product`). Les classifications sont des tables 1-1 (`taxon_classification`, `product_classification`) éditées par des extensions de formulaire admin. L'entreprise company-accounts est référencée par id sans clé étrangère (bundle optionnel).

## Alternatives considérées

- Ajouter des traits aux entités Product / Taxon : écarté (modifications côté application).
- Attributs produit Sylius pour l'UNSPSC : écarté pour le taxon (pas d'attributs) et peu ergonomique ; reste possible via `ItemClassificationResolverInterface`.

## Conséquences

- Installation sans modifier les entités de l'application, migrations du plugin uniquement.
- Une jointure de plus à la résolution de classification : négligeable (au plus un panier à la fois).
