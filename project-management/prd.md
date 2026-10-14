# PRD — tmonier/sylius-punchout-cxml-plugin

> Phase 2 (Planification) · workflow claude-craft Enterprise · SDD · 2026-10-14 · statut : **brouillon à valider**
> Sources : `analysis/*` (synthèse, contraintes, risques, options techniques, références A-D).
> Personas : `personas.md` · Backlog : `backlog/`.

## 1. Vision

Permettre à n'importe quel marchand Sylius de vendre aux grands comptes qui achètent via leur outil d'e-procurement (SAP Ariba, Coupa, Jaggaer, Oracle, SAP SRM/S4…) : l'acheteur ouvre la boutique depuis son outil, retrouve ses prix négociés, remplit son panier et le **renvoie dans son système d'achat** — le tout sans développement spécifique, avec un plugin open source (MIT).

## 2. Problème

- Les acheteurs des grands comptes ne commandent pas sur le site du fournisseur : leurs achats passent obligatoirement par leur outil, avec circuit d'approbation, budgets et centres de coût.
- Sans PunchOut, un fournisseur sous Sylius n'est pas éligible (ou doit passer par une passerelle SaaS payante, ~14 500 $/an chez Ariba).
- Sylius (y compris Plus / B2B Suite) n'offre pas de PunchOut, et aucun plugin n'existe.

## 3. Objectifs et non-objectifs

**Objectifs v1**
1. Accepter un **PunchOut cXML** (create, edit, inspect) et un **PunchOut SAP OCI** depuis une plateforme d'achat configurée.
2. Ouvrir une **session boutique isolée**, connectée au **client Sylius rattaché à l'acheteur** (prix, groupes, tier-pricing), utilisable en **iframe** (Ariba) comme en fenêtre.
3. **Renvoyer le panier** dans l'outil d'achat (cXML `PunchOutOrderMessage` / OCI `NEW_ITEM-*`) avec des prix et des classifications conformes, et empêcher tout checkout Sylius pendant la session.
4. Configuration **sans code** par l'admin Sylius ; **journal** des échanges ; sécurité de niveau production.
5. **Testable sans compte Ariba / Coupa** (simulateur d'acheteur, documents conformes à la DTD).

**Non-objectifs v1** (cf. §7) : réception du bon de commande final (`OrderRequest`, v1.1), RFQ (`operation="source"`), Ariba Level 2 (catalogue indexé), Direct PunchOut (MAC / certificat), OCI `VALIDATE` / `DETAIL` / `BACKGROUND_SEARCH`, OCI JSON (S/4HANA Cloud), confirmations / avis d'expédition / factures cXML, connecteurs propriétaires de passerelles SaaS.

## 4. Utilisateurs

Voir `personas.md` : **Claire** (acheteuse grand compte), **Marc** (administrateur e-procurement du client), **Sophie** (responsable e-commerce B2B du marchand, admin Sylius), **Julien** (intégrateur Sylius), et l'**outil d'achat** (acteur système).

## 5. Parcours cible

1. Sophie crée l'acheteur « BigCorp — Ariba » : protocole cXML, identités `From`/`Sender`, secret, client Sylius rattaché, domaines autorisés, mappings. Elle transmet à Marc l'URL de setup, les identités et le secret.
2. Claire clique sur le fournisseur dans Ariba → Ariba envoie un `PunchOutSetupRequest` → Sylius l'authentifie et répond une URL de démarrage unique.
3. Le navigateur de Claire (iframe Ariba) ouvre l'URL → Claire est connectée au compte client de BigCorp, voit ses prix, un bandeau « Session PunchOut », et remplit son panier.
4. À la place du checkout, Claire clique « Transférer vers mon outil d'achat » (ou « Annuler ») → le `PunchOutOrderMessage` est posté vers Ariba → la session est close.
5. Plus tard, Claire rouvre sa réquisition (edit) : le panier est reconstruit avec ses articles ; ou la consulte (inspect) en lecture seule.
6. OCI : même parcours depuis SAP (entrée avec `HOOK_URL`, retour `NEW_ITEM-*`).

## 6. Exigences fonctionnelles

### Configuration (admin Sylius)
- **FR-1** Ressource « Acheteur PunchOut » (CRUD + grid admin) : nom, actif, canal, protocole (cXML / OCI), client Sylius rattaché (**obligatoire**), entreprise company-accounts (optionnelle, si le plugin est installé), domaines autorisés pour l'URL de retour, origines autorisées pour l'iframe (`frame-ancestors`), options de prix (taxes / port inclus ou non), unité et classification par défaut.
- **FR-2** Identification cXML : une ou plusieurs paires (domaine, identité) pour `From` et `Sender`, secret partagé **stocké haché**, rotation avec deux secrets actifs, régénération affichée une seule fois.
- **FR-3** Identification OCI : identifiants (`USERNAME` / `PASSWORD`) ou jeton d'URL propre à l'acheteur, secret haché.
- **FR-4** Mapping classification et unités : code UNSPSC par taxon (surcharge par produit), unité UN/CEFACT (cXML) et unité OCI (UN/ECE ou SAP T006 selon l'acheteur), valeurs par défaut ; extensible par service tagué.
- **FR-5** Journal des échanges (grid admin) : direction, protocole, type de message, `payloadID`, acheteur, statut, horodatage, corps **masqué** ; rétention configurable ; commande de purge ; effacement des données d'un client (RGPD).

### Setup
- **FR-6** Endpoint cXML de setup (POST, `text/xml`) : parsing sûr, authentification de l'acheteur (`From` + `Sender` + secret), anti-rejeu (`payloadID`, fenêtre de `timestamp`), rate limiting, réponse `PunchOutSetupResponse` avec URL de démarrage **unique, à usage unique, TTL court** ; statuts cXML (`200`, `400`, `401`, `403`, `406`, `450` pour `source`, `500`).
- **FR-7** `ProfileRequest` → `ProfileResponse` (transactions supportées, `operationAllowed`).
- **FR-8** Validation de `BrowserFormPost/URL` contre les domaines autorisés de l'acheteur (HTTPS, pas d'IP privée) ; refus sinon.
- **FR-9** Entrée OCI (GET / POST) : authentification, validation de `HOOK_URL` contre les domaines autorisés, conservation de `~OkCode`, `~TARGET`, `~CALLER`, `returntarget`, puis démarrage de session.

### Session boutique
- **FR-10** Consommation de l'URL de démarrage : connexion automatique du client rattaché (contrôles : compte actif, vérifié si le canal l'exige, client du canal), panier PunchOut dédié et isolé du panier habituel, régénération de la session.
- **FR-11** Bandeau « Session PunchOut — <acheteur> » et boutons « Transférer » / « Annuler » sur la page panier et le panier latéral ; bouton checkout masqué.
- **FR-12** Blocage du checkout, du paiement et des pages de commande pendant la session (redirection vers le panier) ; aucune commande Sylius ne peut être créée depuis un panier PunchOut.
- **FR-13** Fonctionnement en iframe : en-têtes `X-Frame-Options` / CSP `frame-ancestors` adaptés à l'acheteur pendant la session ; documentation de la configuration cookie de l'application.
- **FR-14** Fin de session : après transfert ou annulation, session PunchOut close (panier transféré détaché, accès refusé à une réutilisation).

### Retour du panier
- **FR-15** cXML : construction du `PunchOutOrderMessage` (BuyerCookie, `operationAllowed`, `Total` hors taxes et port, une ligne `ItemIn` par article avec `SupplierPartID`, `SupplierPartAuxiliaryID` stable, prix unitaire, description, unité, classification, délai optionnel ; taxes / port optionnels selon l'acheteur), envoi par formulaire auto-soumis `cXML-base64` (`target="_top"`).
- **FR-16** OCI : envoi des champs `NEW_ITEM-*[n]` (DESCRIPTION, QUANTITY, UNIT, PRICE, PRICEUNIT, CURRENCY, VENDORMAT, MATGROUP, LEADTIME…) vers `HOOK_URL`, avec `~OkCode`, cible `~TARGET` / `returntarget`.
- **FR-17** Annulation : message vide (cXML) / retour sans article (OCI).

### Re-punchout
- **FR-18** `edit` : panier reconstruit depuis les `ItemOut` (via `SupplierPartAuxiliaryID`, sinon `SupplierPartID`), articles indisponibles signalés.
- **FR-19** `inspect` : panier reconstruit en lecture seule (aucune modification, transfert = message sans changement ou fermeture).
- **FR-20** `operationAllowed` du message reflète la configuration de l'acheteur (`edit` par défaut).

### Outillage
- **FR-21** Simulateur d'acheteur dans l'application de test / démo : formulaire d'envoi de setup cXML et OCI, page de réception affichant le message reçu (décodé, validé contre la DTD).
- **FR-22** Commande de diagnostic (`punchout:doctor`) : configuration cookie / HTTPS / en-têtes / acheteurs incomplets.

### v1.1 (hors v1)
- **FR-23** Réception `OrderRequest` → commande Sylius (adresses `BillTo` / `ShipTo`, paiement sur compte, lien avec la session d'origine) + `Response`.
- **FR-24** OCI `VALIDATE` et `DETAIL`.

## 7. Exigences non fonctionnelles

- **NFR-1 Compatibilité** : Sylius `^2.2.9 || ^2.3`, PHP 8.2-8.4, Symfony 6.4 / 7.4 / 8, MySQL 8.4 / MariaDB 11.4 / PostgreSQL 17 ; aucune modification d'entité du core ; aucune modification de `security.yaml` requise.
- **NFR-2 Sécurité** (OWASP 2025) : HTTPS obligatoire ; secrets hachés (Argon2id) ; comparaisons sûres ; anti-rejeu ; jetons à usage unique ; liste blanche des URL de retour ; parsing XML sans entités externes, taille max ; erreurs génériques côté acheteur ; journal des événements de sécurité sans secret ni donnée personnelle en clair ; rate limiting.
- **NFR-3 Conformité** : documents conformes à la DTD cXML **1.2.071** (émission), entrée tolérante (1.2.0xx) ; OCI 4.0 / 5.0 (champs de base).
- **NFR-4 RGPD** : minimisation des `Extrinsic`, masquage dans le journal, rétention configurable, purge, effacement par client.
- **NFR-5 Qualité** : socle claude-craft (ECS, PHPStan level max, Rector, tests unit + functional + integration, CI matrice complète) ; aucun secret ni identifiant réaliste dans les tests.
- **NFR-6 Performance** : setup < 300 ms (hors réseau et hors vérification Argon2id du secret, ADR-0004) ; construction du message de retour < 500 ms pour 200 lignes ; un seul recalcul de commande lors de la reconstruction d'un panier.
- **NFR-7 i18n** : libellés dans les 7 langues (en, fr, de, es, it, nl, pl) ; `xml:lang` des messages selon la locale.
- **NFR-8 Extensibilité** : interfaces taguées pour la classification / les unités, les `Extrinsic`, le prix exporté, la résolution acheteur → client ; intégrations optionnelles company-accounts / quick-order chargées selon `kernel.bundles`.

## 8. Indicateurs de succès

| KPI | Cible v1 |
|---|---|
| Aller-retour complet cXML (create / edit / inspect) et OCI dans le simulateur | 100 % des scénarios Gherkin verts |
| Documents émis valides contre la DTD 1.2.071 | 100 % |
| CI (matrice complète, GitGuardian) | verte |
| Vulnérabilités connues (revue sécurité dédiée) | 0 critique / élevée |
| Temps de configuration d'un acheteur par l'admin | < 10 min, sans code |
| Retour terrain : au moins un aller-retour réel (Ariba, Coupa ou passerelle) avant 1.0 | ≥ 1 |

## 9. Hypothèses et dépendances

- L'application expose la boutique en **HTTPS** et accepte de configurer le cookie de session pour l'iframe.
- Bibliothèque cXML : `friendsofcxml/cxml-php` si le spike de conception la valide, sinon sérialiseur DOM maison (derrière nos interfaces).
- Longueurs exactes des champs OCI à confirmer dans le PDF SAP OCI 5.0 (conception).
- Intégrations optionnelles : `tmonier/sylius-company-accounts-plugin`, `tmonier/sylius-quick-order-plugin`.

## 10. Jalons

| Jalon | Contenu |
|---|---|
| Sprint 1 — Walking skeleton | Acheteur minimal, setup cXML `create`, session connectée, transfert cXML basique, simulateur |
| Sprint 2 — Sécurité & configuration | Admin complet, secrets / rotation, anti-rejeu, rate limit, liste blanche, journal, iframe |
| Sprint 3 — Re-punchout & conformité | edit / inspect, mappings UNSPSC / unités, prix HT / taxes / port, ProfileRequest, DTD |
| Sprint 4 — OCI & finition | OCI entrée / retour, doctor, RGPD (purge / effacement), docs, démo |
| 1.0 | Revue sécurité + retour terrain + publication |
| 1.1 | OrderRequest → commande Sylius, OCI VALIDATE / DETAIL |
