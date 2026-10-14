# ADR-0005 — Prix et totaux renvoyés hors taxes et hors port

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Les outils d'achat attendent des prix unitaires nets et calculent eux-mêmes taxes et frais. Sylius stocke des prix TTC ou HT selon le canal, répartit les promotions par unité et ajoute un port estimé au total de commande.

## Décision

Prix unitaire = `getFullDiscountedUnitPrice()` (remises unitaires et part de promotion de commande), taxe incluse retirée si le canal est TTC (`NetPriceCalculator`, arrondi au centime par unité) ; `Total` = somme des `quantité × prix unitaire` ; `Tax` / `Shipping` uniquement si l'acheteur les active. Devise = devise de base du canal.

## Alternatives considérées

- Utiliser `getTotal()` de la commande : écarté (port estimé, taxes).

## Conséquences

- Écart possible de quelques centimes entre le total renvoyé et le total Sylius (arrondis par unité) : documenté, testé.
- Comportement identique en cXML et OCI.
