# B. SAP OCI, plateformes d'achat, navigateur et sécurité

> Phase Analyse · 2026-10-14. Les PDF SAP OCI 4.0/5.0 n'ont pas pu être extraits en texte : les
> champs OCI viennent de sources secondaires concordantes (gatebold, pragma-code, unimarket,
> SAP Community). **Vérifier les longueurs dans le PDF OCI 5.0 avant la tech spec.**

## 1. SAP OCI 4.0 / 5.0

Spec : https://help.sap.com/doc/srm/1.0/en-US/attachments/3362936504/OCI_50_EN20130121.pdf (5.0, janv. 2013) · OCI_4.0_Documentation.pdf (même dossier) · miroir https://punchoutcommerce.com/docs/sap-oci-5.pdf

**Entrée (SAP → catalogue), GET ou POST (POST recommandé)** :

| Paramètre | Rôle |
|---|---|
| `HOOK_URL` | URL absolue de retour du panier (HTTPS) ; envoyée en dernier par SAP |
| `USERNAME` / `PASSWORD` | Identifiants du compte catalogue (en clair dans la requête → HTTPS obligatoire) |
| `~OkCode` | À renvoyer tel quel (souvent `ADDI`) |
| `~TARGET` | Frame cible du retour (`_top` recommandé ; `_self` piège le retour dans l'iframe) |
| `~CALLER` | `CTLG` |
| `returntarget` | (5.0) cible du formulaire de retour |
| `~HTTP_CONTENT_CHARSET` | Encodage attendu (pièges documentés ; préférer UTF-8) |

**Retour (catalogue → `HOOK_URL`)** : formulaire POST, champs indexés à partir de 1 `NEW_ITEM-<CHAMP>[n]`, décimales avec **point**.

| Champ | Requis | Notes |
|---|---|---|
| `DESCRIPTION` | Oui | 40 car. (4.0) / 256 (5.0) |
| `QUANTITY` | Oui | décimal |
| `UNIT` | Oui | 3 car. ; UN/ECE (PCE, KGM) **ou** codes SAP T006 (ST, KG) selon l'acheteur → mapping configurable |
| `PRICE` | Oui | pour `PRICEUNIT` unités (défaut 1 ; source d'erreurs ×100) |
| `CURRENCY` | Oui | ISO 4217 |
| `VENDORMAT` | — | SKU fournisseur (≈ SupplierPartID) |
| `MATNR`, `VENDOR`, `MATGROUP` (UNSPSC 8 ch.), `LEADTIME`, `DELIVERYDATE`, `MANUFACTCODE`, `MANUFACTMAT`, `CONTRACT`(+`_ITEM`), `SERVICE` (`X`), `LONGTEXT_1:132`, `CUST_FIELD1..5` | Non | |
| (5.0) `EXT_PRODUCT_ID`, `EXT_SCHEMA_TYPE`, `EXT_CATEGORY_ID`, `EXT_CATEGORY`, `ATTACHMENT*`, `EXT_QUOTE_ID` | Non | |

Fonctions optionnelles : `VALIDATE` (re-prix d'articles copiés), `DETAIL` (fiche article), `BACKGROUND_SEARCH` → post-v1. OCI 5.0 ajoute JSON sur HTTP (S/4HANA Cloud) → hors v1.

## 2. Plateformes

| Plateforme | Protocole | Activation / test | Certification |
|---|---|---|---|
| **SAP Ariba** (Business Network) | cXML ; ANID (`NetworkId`), ANID de test suffixé `-T` ; HTTPS ; SharedSecret | catalogue test sur compte acheteur test, puis prod | **Aucune certification formelle accessible à un OSS : validation par l'acheteur** |
| **Coupa** | cXML (setup, response, order message, OrderRequest) ; credentials sensibles à la casse | appel de cadrage → credentials → config (Supplier + Contract) → tests → prod | idem, validation acheteur ; edit/inspect et OCI : NON VÉRIFIÉ |
| **Jaggaer** | cXML conforme cxml.org (OCI selon tiers) | site PunchOut créé par l'admin acheteur + test aller-retour | NON VÉRIFIÉ |
| **Ivalua, Oracle iProcurement** | cXML (doc derrière portails) | — | NON VÉRIFIÉ |
| **SAP SRM** (historique) | **OCI** | config SPRO, GET/POST | — |

Ariba **Level 1** (l'acheteur arrive sur le site) = cible v1 ; **Level 2** (index produits dans la recherche Ariba) = v2.

## 3. Navigateur : iframe et cookies

| Plateforme | Ouverture | Fiabilité |
|---|---|---|
| SAP Ariba | **iframe par défaut** | KB Ariba KB0392847 (officiel) |
| Coupa | configurable (iframe / fenêtre) | secondaire |
| Jaggaer | nouvelle fenêtre (tiers) | NON VÉRIFIÉ |
| SAP SRM | même fenêtre | KBA 2599304 |

- Chrome : cookies sans `SameSite=None; Secure` bloqués en iframe cross-site depuis Chrome 80 ; abandon de la suppression totale des cookies tiers (juillet 2024) mais **les règles SameSite restent**.
- Safari ITP : bloque les cookies tiers en iframe ; **CHIPS** (`Partitioned`, Chrome 114+, Firefox 141+, implémentation Safari propre) isole le cookie par site de premier niveau (10 Ko).
- Conséquences pour le plugin :
  - session PunchOut démarrée par **token unique dans l'URL `StartPage`** (aucune dépendance au cookie pour le démarrage) ;
  - cookie de session `SameSite=None; Secure` (+ `Partitioned` en option) **uniquement pour les sessions PunchOut** si possible (ne pas dégrader la boutique) ;
  - `Content-Security-Policy: frame-ancestors` **dynamique** sur les pages de la session PunchOut, à partir des domaines autorisés de l'acheteur (pas `X-Frame-Options ALLOW-FROM`, obsolète) ;
  - recommander l'ouverture en fenêtre de premier niveau quand la plateforme le permet ;
  - retour par formulaire auto-soumis `target="_top"` (OCI `~TARGET`) pour sortir de l'iframe.

## 4. Sécurité

| Risque | Mesure |
|---|---|
| Usurpation d'acheteur | SharedSecret par acheteur, `hash_equals`, HTTP 401 si invalide, HTTPS obligatoire ; secrets hors code (chiffrés en base ou env) ; rotation (2 secrets actifs) |
| Rejeu | `payloadID` mémorisé (TTL) + fenêtre de `timestamp` ; token de démarrage **à usage unique**, TTL court (≈ 5 min) |
| Fixation de session | nouveau token à chaque setup, régénération de l'id de session à la consommation, jamais de token fourni par l'utilisateur final |
| **Exfiltration du panier** | `BrowserFormPost/URL` et `HOOK_URL` validés contre une **liste blanche de domaines par acheteur** ; HTTPS ; refuser IP privées / localhost |
| XXE / bombes XML | libxml ≥ 2.9 : entités externes désactivées par défaut ; **ne jamais passer `LIBXML_NOENT` / `LIBXML_DTDLOAD` / `LIBXML_DTDVALID`** sur une entrée non fiable ; limite de taille du corps ; `LIBXML_NONET` ; validation DTD faite sur une DTD **locale** séparément si besoin |
| Fuite d'information | erreurs génériques côté acheteur, détail en log ; journal sans secrets, données personnelles des `Extrinsic` masquées |
| Panier lisible après envoi | destruction / invalidation de la session PunchOut après le `PunchOutOrderMessage` |

## 5. Indispensable v1 vs plus tard (proposition de l'analyse B)

**v1** : setup cXML HTTPS + SharedSecret (401) ; token de démarrage unique TTL court ; `StartPage` ; conservation `BuyerCookie` / `BrowserFormPost` ; formulaire de retour ; cookies `SameSite=None; Secure` + `frame-ancestors` dynamique ; liste blanche des URL de retour ; parsing XML sûr ; OCI de base (`HOOK_URL`, `~OkCode`, `~TARGET`, `~CALLER`, `NEW_ITEM-*` minimaux) ; cXML Level 1 (Ariba, Coupa, Jaggaer) ; TLS 1.2+.

**Plus tard** : OCI `VALIDATE`/`DETAIL` (v1.1), `BACKGROUND_SEARCH` et Ariba Level 2 (v2), edit/inspect (v1.1 si demandé), champs OCI 5.0 étendus, CHIPS, rotation multi-secrets, anti-rejeu `payloadID`.

## Sources principales

SAP Help (OCI 4.0/5.0 PDF), gatebold.com/en/tools/oci-fields, SAP Community (HOOK_URL, OCI parameters), SAP Blogs (OCI 5.0 S/4HANA), tradecentric.com (SRM OCI), pragma-code.de, unimarket, help.sap.com (Developing PunchOut Site), Ariba Level 1/2 guides, sandcastle-web.com, Coupa Compass (cXML enablement, PunchOut ordering), Jaggaer Supplier Enablement Terms, support.ariba.com KB0392847, punchoutcommerce.com (Chrome 80, setup request, RFC return link), punchoutrocket.com (iframe), Chromium CHIPS intent, symfony.com (SameSite), OWASP ASVS, php.watch / php.net (libxml), typo3 forge, laminas-feed, ordercloud-api/punchout, docs.vendure.io (PunchOut), Optimizely (iframe limitations).
