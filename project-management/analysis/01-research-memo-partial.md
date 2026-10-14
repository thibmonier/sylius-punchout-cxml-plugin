# Mémo de recherche (partiel) — tmonier/sylius-punchout-cxml-plugin

> Phase : Analyse (workflow claude-craft, track Enterprise, SDD) · 2026-10-14
> Statut : **partiel**. Les points marqués NON VÉRIFIÉ ou « à compléter » sont repris dans
> `02-gap-analysis.md`. Ce document ne doit pas servir de spécification tant que les lacunes
> ne sont pas comblées.

## 1. Protocoles

### 1.1 cXML
- Version courante : **cXML 1.2.071** (2026-08-14). Guide : https://xml.cxml.org/current/cXMLReferenceGuide.pdf · DTD : https://xml.cxml.org/current/cXML_DTDs.zip
- Les acheteurs citent des DTD anciennes (1.2.008 chez Optimizely, 1.2.014 chez Coupa) → viser une DTD fixe et tester la compatibilité. Choix de la version cible : à compléter.
- Repères du guide (table des matières uniquement) : enveloppe / header / request / response p. 24-41 ; exigences PunchOut p. 56-57 ; séquence p. 59-62 ; documents PunchOut p. 63-69 ; transaction détaillée p. 78-84 ; pages du site fournisseur (launch, start, sender, order receiver) p. 71-77 ; cookies p. 78 ; Direct PunchOut et authentification p. 96-97 ; Purchase Requisitions p. 98-103 ; Purchase Orders p. 104.
- `PunchOutSetupRequest` : `operation` (create / edit / inspect), `BuyerCookie`, `BrowserFormPost/URL`, `Extrinsic` (email, nom, id utilisateur), `SelectedItem`, `ShipTo` (confirmé partiellement par Optimizely et Coupa).
- `PunchOutSetupResponse` : `StartPage/URL`.
- Authentification : `Header` (`From`, `To`, `Sender/UserAgent`) + `Credential` + `SharedSecret` ; échecs fréquents = incohérence From/To/secret (Ariba). Détail des attributs : NON VÉRIFIÉ.
- Le fournisseur conserve `BuyerCookie`, `BrowserFormPost`, `To`, `From` pour le retour (Unimarket).
- `PunchOutOrderMessage` : `BuyerCookie`, `operationAllowed`, `ItemIn` (`SupplierPartID`, `SupplierPartAuxiliaryID`, `ItemDetail` : `UnitPrice/Money`, `Description`, `UnitOfMeasure`, `Classification`). Structure exacte, taxes, frais de port : à compléter.
- Transport du retour : auto-submit POST, `cxml-urlencoded` / `cxml-base64` : NON VÉRIFIÉ.
- `OrderRequest`, `OrderRequestResponse`, `ProfileRequest`, codes de statut, payloadID, timestamps, signature : NON VÉRIFIÉ.

### 1.2 SAP OCI 4.0 / 5.0
- `HOOK_URL` généré par SAP et transmis au catalogue, qui renvoie le panier par POST vers cette URL.
- Champs de retour indexés `NEW_ITEM-<CHAMP>[n]` (quantité, prix, unité, devise…).
- Longueurs maximales contradictoires (15 vs 255 caractères), `~TARGET`, `~OkCode`, `USERNAME/PASSWORD`, doc officielle OCI 5.0 : NON VÉRIFIÉ. Ancien piège SRM 7.0 : `~HTTP_CONTENT_CHARSET=utf-8`.

### 1.3 Plateformes
- Ariba : compte de test sur Ariba Network (test et prod), URL HTTPS, secret obligatoire, catalogue CIF publié puis activé par l'acheteur. Certains acheteurs exigent UNSPSC + re-punchout edit/inspect (ex. NC eProcurement). Certification officielle : NON VÉRIFIÉ.
- Coupa : doc fournisseur Coupa Compass ; exigences détaillées NON VÉRIFIÉ. Jaggaer, Ivalua, SAP SRM : non recherchés.
- Iframe vs nouvelle fenêtre, cookies SameSite tiers : NON VÉRIFIÉ (défaut prudent : pleine fenêtre).

## 2. Écosystème
- **Sylius Plus / B2B Suite** (page publique) : quick buy, recherche, commandes par organisation, listes de prix par groupe, wishlists, RFQ, RBAC, partial shipment, multi-source inventory. **Aucune mention de PunchOut / cXML / OCI / Ariba / Coupa.** Doc technique complète non parcourue → absence non garantie. B2B Suite 3.1.1, compatible Sylius 1.11 à 2.2 (2.3 non vérifié).
- Plugins Sylius : aucun PunchOut trouvé chez BitBag / Synolia ; recherche exhaustive du Marketplace non faite.
- Libs PHP : `herbert/cxml` (dédiée PunchOut, 18 étoiles), `friendsofcxml/cxml-php` (utilisée par Spryker punchout-gateway), `beethoven/phpcxml` (probablement obsolète) — maintenance / licence / releases NON VÉRIFIÉ. Aucune lib OCI PHP identifiée.
- Passerelles SaaS : TradeCentric (ex-PunchOut2Go, ~14 500 USD/an + 6 000 USD setup chez SAP Ariba), PunchOut Catalogs, Abeta. Interfaçage possible : NON VÉRIFIÉ.
- Autres plateformes : Magento sans PunchOut natif (modules Takeoff, Greenwing, SCommerce, payants) ; Shopware : extension SAP OCI payante ; PrestaShop / Oro non recherchés.

## 3. Design Sylius 2 (proposition non vérifiée contre le code)
- Endpoints : `POST /punchout/cxml` (setup → `StartPage`), `GET|POST /punchout/oci`, route de sortie auto-submit du `PunchOutOrderMessage`.
- Sécurité : parsing XML sans entités externes (XXE), limite de taille, `hash_equals` sur le secret, secret par acheteur avec rotation (2 secrets actifs), anti-rejeu sur `payloadID` + fenêtre de temps, journal sans secret, masquage des `Extrinsic`.
- Acheteur → client Sylius (email / id), rattachement optionnel à un compte d'entreprise (company-accounts).
- Session PunchOut liée au panier (BuyerCookie, From/To, mode, URL de retour) ; edit = repeupler depuis `ItemOut` ; inspect = lecture seule ; « Transférer le panier » à la place du checkout, aucune commande Sylius créée dans ce parcours.
- Données : configuration par acheteur, sessions PunchOut, journal des messages.
- Points d'extension : mapping UNSPSC / UOM / classification, `Extrinsic`, stratégie de prix contractuel.
- `OrderRequest` optionnel → commande Sylius + `OrderRequestResponse`.

## 4. Périmètre proposé (provisoire)
- v1 (M) : setup cXML, panier → `PunchOutOrderMessage` auto-POST, authentification par secret, configuration par acheteur, journal, tests functional.
- v1.1 (M) : edit / inspect complets, mapping UNSPSC configurable.
- v2 (L) : OCI 4.0/5.0, `OrderRequest`, passerelles SaaS, company-accounts.
- Risques : certification Ariba probablement inaccessible en OSS ; tests sans compte acheteur ; divergence de versions cXML ; iframe/cookies.

## 5. Positionnement vs Sylius Plus (provisoire)
Complémentaire (couche d'intégration procurement, aucune feature Plus documentée équivalente), **à confirmer** sur la doc technique Plus avant toute communication.

## Sources
Voir la liste complète dans le rapport de recherche : cxml.org, guide cXML 1.2.071, Optimizely, Coupa Compass, Unimarket, Intel SIP (Ariba), NC eProcurement, SAP Community / Blogs, Unite, sylius.com/plus, docs.sylius.com, addons.sylius.com, Packagist (herbert/cxml, beethoven/phpcxml), SAP partner pages (TradeCentric), Adobe Commerce Marketplace (Takeoff, Greenwing, SCommerce, Abeta, PunchOut Catalogs), Shopware Store.
