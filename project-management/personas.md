# Personas — tmonier/sylius-punchout-cxml-plugin

> Phase 2 (Planification) · 2026-10-14

## P1 — Claire, acheteuse dans un grand compte

- **Contexte** : acheteuse opérationnelle chez BigCorp (5 000 salariés), passe toutes ses commandes dans SAP Ariba Buying, souvent dans l'iframe d'Ariba. Commande des fournitures et pièces récurrentes.
- **Objectifs** : trouver vite les bons articles **à ses prix contractuels**, renvoyer un panier propre dans sa réquisition, pouvoir la rouvrir pour la modifier.
- **Frustrations** : catalogues PunchOut qui perdent la session en iframe, prix publics au lieu des prix négociés, retour qui échoue sans explication, devoir se recréer un compte.
- **Ce qu'elle attend du plugin** : arriver connectée, un bandeau clair « vous êtes en session PunchOut », un bouton « Transférer » évident, un edit qui retrouve son panier.

## P2 — Marc, administrateur e-procurement du client

- **Contexte** : responsable P2P / catalogues chez BigCorp, configure les fournisseurs dans Ariba (ou Coupa, ou SAP SRM en OCI).
- **Objectifs** : activer un fournisseur rapidement et de façon sûre : URL de setup, identités, secret, test aller-retour, mise en production.
- **Frustrations** : fournisseurs qui ne connaissent pas cXML, champs obligatoires manquants (UNSPSC, unités), erreurs d'authentification opaques.
- **Ce qu'il attend** : des documents conformes à la DTD, des codes de statut cXML corrects, des unités et classifications renseignées, une procédure de test simple.

## P3 — Sophie, responsable e-commerce B2B du marchand (admin Sylius)

- **Contexte** : distributeur B2B sous Sylius, veut être référencé chez des grands comptes ; pas développeuse.
- **Objectifs** : déclarer un acheteur PunchOut en quelques minutes, le rattacher au bon client / à la bonne entreprise, transmettre les informations à Marc, suivre les échanges.
- **Frustrations** : jargon technique, peur de « casser » la boutique, ne pas savoir pourquoi un acheteur échoue.
- **Ce qu'elle attend** : un écran de configuration guidé, un secret généré et affiché une seule fois, un journal lisible, des valeurs par défaut raisonnables (unité, UNSPSC).

## P4 — Julien, intégrateur Sylius (agence)

- **Contexte** : installe et maintient les boutiques de plusieurs marchands ; doit livrer un PunchOut sans compte Ariba de test.
- **Objectifs** : installer, configurer (HTTPS, cookie de session pour l'iframe), tester de bout en bout, étendre (extrinsics propres à un acheteur, mapping des prix ou classifications).
- **Frustrations** : intégrations opaques, impossibilité de tester localement, dépendances lourdes.
- **Ce qu'il attend** : un simulateur d'acheteur, une commande de diagnostic, des points d'extension documentés, des tests et une CI fiables.

## A1 — L'outil d'achat (acteur système)

SAP Ariba, Coupa, Jaggaer, Oracle iProcurement (cXML) ou SAP SRM / S4 (OCI), éventuellement via une passerelle (TradeCentric…). Envoie le setup, attend une réponse conforme et un message de retour conforme ; ouvre la boutique en iframe ou en fenêtre.
