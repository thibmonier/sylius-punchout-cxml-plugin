# ADR-0007 — inspect sans panier

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

L'opération `inspect` doit montrer une réquisition sans permettre de la modifier. Reconstruire un panier en lecture seule obligerait à bloquer toutes les routes et actions Live Component qui modifient le panier.

## Décision

`inspect` ne crée pas de panier : `InspectAction` affiche une page dédiée listant les `ItemOut` résolus (variante, image, quantité, prix reçu) et un bouton « Retour » qui renvoie un message sans `ItemIn`.

## Alternatives considérées

- Panier en lecture seule : écarté (surface de blocage large et fragile entre versions).

## Conséquences

- Simple et sûr ; la page n'a pas l'apparence complète de la boutique (acceptable pour une consultation).
