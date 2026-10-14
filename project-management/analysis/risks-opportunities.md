# Risques et opportunités — tmonier/sylius-punchout-cxml-plugin

> Phase 1 (Analyse) · 2026-10-14

## Risques

| # | Risque | Impact | Prob. | Atténuation |
|---|---|---|---|---|
| R1 | Pas d'accès à Ariba / Coupa pour tester ; écarts avec des plateformes réelles (extrinsics propres, versions cXML anciennes) | Élevé | Élevée | Fixtures du guide + validation DTD, simulateur d'acheteur, tolérance en entrée (toute 1.2.0xx), points d'extension pour les extrinsics ; appel à des intégrateurs testeurs avant 1.0 |
| R2 | Iframe Ariba : session perdue (cookies SameSite / Safari ITP) | Élevé | Élevée | Démarrage par token dans l'URL ; doc de config cookie de l'application ; `frame-ancestors` dynamique ; recommandation d'ouverture en fenêtre ; CHIPS en option ; test manuel en iframe dans le simulateur |
| R3 | Exfiltration du panier via un `BrowserFormPost/URL` / `HOOK_URL` arbitraire | Élevé | Moyenne | Liste blanche de domaines par acheteur, HTTPS obligatoire, refus des IP privées |
| R4 | Usurpation d'acheteur / rejeu de setup | Élevé | Moyenne | Secret par acheteur haché (Argon2id), comparaison sûre, HTTPS, anti-rejeu `payloadID` + fenêtre de temps, token de démarrage à usage unique et TTL court, rate limiting |
| R5 | XXE / bombe XML sur l'endpoint public | Élevé | Faible | Parsing libxml sans `NOENT`/`DTDLOAD`, `LIBXML_NONET`, taille max, tests dédiés (charges XXE / billion laughs) |
| R6 | Panier PunchOut qui « fuit » : repris comme panier habituel, ou passé au checkout Sylius | Moyen | Élevée | Contexte de panier dédié, `createdByGuest = true`, listener de blocage des routes checkout/order + guard `complete`, destruction de la session après transfert |
| R7 | Prix ou totaux faux (taxes incluses, port estimé, arrondis par unité) | Élevé | Moyenne | Règle explicite (prix unitaire HT après remises, `Total` = somme articles hors taxes/port), tests sur zones TTC/HT, centimes non entiers documentés |
| R8 | Compte non vérifié / désactivé connecté via `Security::login()` (pré-auth permissif 2.3) | Élevé | Moyenne | Contrôles manuels enabled / verifiedAt / canal avant login, tests 2.2 et 2.3 |
| R9 | Dépendance `friendsofcxml/cxml-php` (jms/serializer, parseur à auditer) | Moyen | Moyenne | Spike + ADR en conception ; encapsulation derrière nos interfaces (remplaçable par un sérialiseur DOM maison) |
| R10 | Divergence 2.2 / 2.3 des hooks Sylius | Moyen | Moyenne | Matrice CI complète ; n'utiliser que des points d'extension présents dans les deux |
| R11 | Données personnelles des acheteurs dans les journaux | Moyen | Moyenne | Masquage, rétention configurable, commande de purge, effacement par client |
| R12 | Scope creep (OCI, edit/inspect, OrderRequest, Level 2, passerelles) | Moyen | Élevée | v1 resserrée et versionnée, backlog priorisé (MoSCoW) |
| R13 | Communication mal perçue par l'équipe Sylius | Moyen | Faible | Positionnement « intégration e-procurement complémentaire », pas de comparaison avec Plus |

## Opportunités

- **Seul PunchOut libre** parmi les plateformes PHP majeures : forte visibilité auprès des intégrateurs B2B enterprise.
- **Prérequis des passerelles SaaS** (TradeCentric, PunchCommerce…) : ouvre Sylius à leurs clients sans travail spécifique.
- **Synergies avec l'écosystème de l'auteur** : company-accounts (entreprise ↔ acheteur), purchase-approval (approbation côté client = déjà couverte par l'outil d'achat, mais réconciliation possible), quick-order (ajout groupé), tier-pricing (prix négociés), order-hold / trade-credit (commande finale via `OrderRequest` payée à crédit), b2b-mcp.
- **Évolutions naturelles** : OCI (SAP), `OrderRequest` → commande Sylius, `edit`/`inspect`, Ariba Level 2 (catalogue indexé), confirmations / avis d'expédition cXML (`ConfirmationRequest`, `ShipNoticeRequest`), factures cXML (lien avec Factur-X).
- Possibilité freemium (cœur MIT, connecteurs avancés / support) si l'auteur le souhaite.
