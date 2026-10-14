# Synthèse de recherche — tmonier/sylius-punchout-cxml-plugin

> Phase 1 (Analyse) · workflow claude-craft Enterprise · SDD · 2026-10-14
> Détails : `A-cxml-protocol-reference.md` (guide cXML 1.2.071), `B-oci-platforms-browser-security.md`,
> `C-ecosystem-positioning.md`, `D-sylius-integration-points.md` (code Sylius 2.3).

## 1. Domaine

**Problème.** Les grands comptes (groupes, collectivités) n'achètent pas sur le site du fournisseur : leurs acheteurs passent par un outil d'e-procurement (SAP Ariba, Coupa, Jaggaer, SAP SRM/S4…). Le **PunchOut** permet à l'acheteur d'ouvrir le catalogue du fournisseur depuis cet outil, de remplir un panier, puis de **renvoyer le panier dans son système** sous forme de demande d'achat, qui suit le circuit d'approbation du client. La commande définitive peut ensuite revenir au fournisseur (`OrderRequest`). Sans PunchOut, un marchand Sylius est exclu de ces comptes.

**Parties prenantes.**
- Marchand B2B (vendeur Sylius) : veut être référencé chez des grands comptes.
- Acheteur chez le client : veut le catalogue et les prix négociés sans quitter son outil.
- Service achats / admin e-procurement du client : configure le fournisseur (URL, identités, secret).
- Intégrateur / agence Sylius : déploie, configure, doit tester sans compte Ariba.
- Mainteneur OSS (l'auteur).

**Moteurs business.** Lever le verrou n°1 de l'adoption de Sylius par les grands comptes ; aucune solution Sylius existante ; alternatives payantes (passerelles SaaS ~14 500 $/an chez Ariba, modules Magento 999 $+).

**Succès.** Un acheteur Ariba/Coupa (ou un simulateur conforme) fait l'aller-retour complet setup → navigation avec les prix du client → retour d'un panier valide dans son outil ; configuration par l'admin Sylius sans code ; sécurité sans faille connue ; CI verte sur Sylius 2.2/2.3.

## 2. Marché

- **Sylius Plus / B2B Suite : pas de PunchOut** (aucune mention dans la doc officielle). **Aucun plugin Sylius** PunchOut/cXML/OCI (GitHub, Packagist, Marketplace). → plugin **complémentaire** de Plus, niche libre.
- Aucune plateforme PHP majeure n'a de PunchOut natif ni libre : Shopware, Oro, Spryker, Magento n'ont que des extensions commerciales ; SAP Commerce Cloud a du cXML natif.
- Les passerelles SaaS (TradeCentric, PunchCommerce, Abeta) parlent **cXML standard côté boutique** → le plugin leur est compatible (et en est même le prérequis).
- Les grandes plateformes ajoutent des `Extrinsic` et validations propres → besoin de points d'extension.

## 3. Technique (faits vérifiés)

- **cXML 1.2.071** : setup request (`create|edit|inspect|source`, `BuyerCookie`, `BrowserFormPost`, `Extrinsic`, `ShipTo`, `ItemOut`) → setup response (`StartPage` unique et temporaire, statuts dont `450` opération non supportée) → **PunchOutOrderMessage** (One-Way, `Total` hors taxes/port, `ItemIn` avec 4 champs obligatoires `UnitPrice`, `Description`, `UnitOfMeasure` UN/CEFACT, `Classification` UNSPSC, `SupplierPartAuxiliaryID` stable) renvoyé par **formulaire auto-soumis** (`cXML-base64` préféré). `ProfileRequest` obligatoire pour un serveur ≥ 1.1. `OrderRequest` = bon de commande final. `timestamp` sans `Z`. SharedSecret uniquement dans `Sender`, jamais dans un document qui passe par le navigateur.
- **SAP OCI 4.0/5.0** : entrée `HOOK_URL` (+ `~OkCode`, `~TARGET`, `~CALLER`, `USERNAME`/`PASSWORD`), retour formulaire `NEW_ITEM-<CHAMP>[n]` (DESCRIPTION, QUANTITY, UNIT, PRICE, CURRENCY, VENDORMAT…). Longueurs à confirmer dans le PDF officiel.
- **Plateformes** : pas de certification formelle accessible (validation par l'acheteur) ; **Ariba ouvre le PunchOut en iframe par défaut** ; Coupa configurable ; SRM = OCI.
- **Navigateur** : en iframe cross-site, cookie `SameSite=None; Secure` requis (Safari plus strict ; CHIPS `Partitioned` en option) ; `frame-ancestors` à ouvrir aux domaines de l'acheteur ; démarrer la session par un token dans l'URL.
- **Bibliothèque** : `friendsofcxml/cxml-php` 2.2.5 (MIT, PHP ≥ 8.2, maintenue, couvre tous les messages ; dépend de jms/serializer) ; pas de lib OCI.
- **Sylius 2.3** : pièges sur la priorité des contextes de panier, `Security::login()` pré-auth seulement, bouton checkout dans un formulaire PATCH de Live Component, port estimé dans `getTotal()`, `X-Frame-Options: sameorigin` et `SameSite=Lax` par défaut, LoginLink impossible depuis un plugin, paniers expirés après 2 jours. Points d'extension identifiés (contexte de panier dédié, voter + conditions twig-hooks, listeners request/response, ajout groupé à un seul `process()`).

## 4. Questions à trancher en Planification

1. Périmètre v1 : cXML seul ou cXML + OCI ? `edit`/`inspect` en v1 ou v1.1 ? `OrderRequest` (création de commande Sylius) en v1, v1.1 ou v2 ?
2. Identité de l'acheteur : client Sylius connecté (prix et groupes du client) obligatoire, ou panier invité possible ? Création automatique de compte à partir des `Extrinsic` ?
3. Intégrations optionnelles v1 : company-accounts (entreprise ↔ acheteur), quick-order (ajout groupé), tier-pricing (prix par groupe) ?
4. Mapping UNSPSC / UN/CEFACT : où le stocker (attribut produit, taxon, table du plugin) et valeurs par défaut ?
5. Taxes et frais de port dans le message de retour : exclus (défaut cXML) ou optionnels par acheteur ?
