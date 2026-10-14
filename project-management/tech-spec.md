# Spécification technique — tmonier/sylius-punchout-cxml-plugin

> Phase 3 (Conception) · 2026-10-14 · statut : **brouillon à valider**
> Entrées : `prd.md`, `backlog/`, `analysis/*` (T1-T17, références A cXML, B OCI, D intégration Sylius 2.3, E spike bibliothèque cXML, F champs OCI 5.0).
> Annexes : `architecture/c4.md`, `architecture/erd.md`, `architecture/api.md`, `architecture/security.md` · ADR : `../docs/adr/`.

## 1. Résumé

Plugin Sylius 2 (`^2.2.9 || ^2.3`, PHP 8.2-8.4, Symfony 6.4 / 7.4 / 8) qui rend une boutique **fournisseur PunchOut** :

1. un endpoint serveur-à-serveur **cXML** (`/punchout/cxml`) reçoit `PunchOutSetupRequest` (create / edit / inspect) et `ProfileRequest`, authentifie l'acheteur et répond une URL de démarrage à usage unique ;
2. une entrée navigateur **OCI** (`/punchout/oci/{buyerCode}`) authentifie l'acheteur SAP et démarre directement la session ;
3. la **session boutique PunchOut** connecte le client Sylius rattaché, isole un panier dédié, bloque le checkout, s'affiche en iframe chez l'acheteur ;
4. le **transfert** renvoie le panier (cXML `PunchOutOrderMessage` en `cXML-base64`, ou champs OCI `NEW_ITEM-*`) par formulaire auto-soumis vers l'URL de retour validée ;
5. l'admin configure les acheteurs, la classification (UNSPSC, unités) et consulte le journal ; des commandes assurent purge, effacement RGPD et diagnostic.

Aucune modification des entités de l'application hôte (pas de trait à ajouter) : toutes les données vivent dans des tables `tmonier_punchout_*`.

## 2. Conventions

| Élément | Valeur |
|---|---|
| Package / namespace | `tmonier/sylius-punchout-cxml-plugin` · `Tmonier\SyliusPunchoutCxmlPlugin\` |
| Bundle / alias de config | `TmonierSyliusPunchoutCxmlPlugin` · `tmonier_sylius_punchout_cxml` |
| Préfixes | tables `tmonier_punchout_`, routes `tmonier_punchout_`, services `tmonier_punchout.`, traductions `tmonier_punchout.` |
| Ressources Sylius | `tmonier_punchout.buyer`, `tmonier_punchout.session`, `tmonier_punchout.message_log` (+ classifications) |
| Socle qualité | ECS (`sylius-labs/coding-standard`), PHPStan level max, Rector ^2.0, PHPUnit (unit / integration / functional), CI matrice Sylius 2.2 / 2.3 × MySQL / PostgreSQL |
| Langues | en, fr, de, es, it, nl, pl |
| Environnement local | MySQL 3334, MailHog 8053 / 1053, serveur de dev 8101 |

## 3. Architecture

Vue C4 et diagrammes de séquence : `architecture/c4.md`. Découpage `src/` :

| Module | Responsabilité | Classes principales |
|---|---|---|
| `Cxml/` | Chargement sûr, lecture des requêtes, écriture des réponses et messages | `SafeXmlLoader`, `SetupRequestReader`, `ProfileRequestReader`, `CxmlRequestDispatcher`, `SetupResponseWriter`, `ProfileResponseWriter`, `OrderMessageWriter`, `CxmlStatus`, `PayloadIdGenerator` |
| `Oci/` | Lecture de l'entrée OCI, construction du formulaire de retour | `OciEntryRequest`, `OciReturnFieldsBuilder`, `OciFieldLimits` |
| `Buyer/` | Configuration acheteur, identités, secrets | entités `Buyer`, `BuyerIdentity`, `BuyerCredential` ; `BuyerResolver`, `CredentialHasher`, `CredentialGenerator`, `CredentialVerifier` |
| `Security/` | Anti-rejeu, liste blanche, limitation, voter | `ReplayGuard`, `ReturnUrlValidator`, `SetupRateLimiter`, `PunchoutSessionVoter` |
| `Session/` | Cycle de vie de la session PunchOut | entité `PunchoutSession` ; `SessionFactory`, `StartTokenIssuer`, `StartTokenConsumer`, `ShopperAuthenticator`, `CurrentPunchoutSession`, `SessionCloser` |
| `Cart/` | Panier isolé, reconstruction (edit) | `PunchoutCartContext` (`#[AsCartContext(priority: 0)]`), `PunchoutCartFactory`, `CartRebuilder`, `CartLinesAdderInterface` + `CoreCartLinesAdder` |
| `Transfer/` | Extraction des lignes et des montants, construction des retours | `TransferLineExtractor`, `TransferLine` (DTO), `NetPriceCalculator`, `CxmlTransferBuilder`, `OciTransferBuilder`, `TransferResponder` |
| `Classification/` | UNSPSC et unités | entités `TaxonClassification`, `ProductClassification` ; `ItemClassificationResolverInterface` (taguée, chaîne), `UnitResolverInterface`, `UnitCodeMapper` (UN/ECE ↔ SAP) |
| `EventListener/` | Intégration HTTP / workflow | `CheckoutBlockerListener` (`kernel.request`, priorité 4), `FrameHeadersListener` (`kernel.response`, priorité -16), `OrderCompleteGuard` (+ variante winzou) |
| `Log/` | Journal et masquage | entité `MessageLog` ; `MessageLogger`, `CxmlMasker` |
| `Gdpr/` | Rétention et effacement | `PunchoutDataPurger`, `CustomerPunchoutDataEraser` |
| `Integration/CompanyAccounts/` | Rattachement entreprise (chargé si le bundle est présent) | `CompanyMemberCustomerValidator`, extension de formulaire |
| `Controller/` | Contrôleurs invokables | `CxmlEndpointAction`, `StartAction`, `OciEntryAction`, `TransferAction`, `CancelAction`, `InspectAction` |
| `Command/` | Console | `PurgeCommand`, `EraseCustomerCommand`, `DoctorCommand` |
| `Event/` | Points d'extension | `SetupRequestAcceptedEvent`, `SessionStartedEvent`, `TransferLineBuiltEvent`, `TransferMessageBuiltEvent`, `SessionClosedEvent` |

Principes : contrôleurs fins (orchestration), une classe par responsabilité, interfaces pour tout ce qui est remplaçable (lecteur / écrivain cXML, résolveurs de classification, ajout groupé au panier), `final` par défaut, DTO immuables (`readonly`).

## 4. Modèle de données

Détail et diagramme : `architecture/erd.md`.

| Entité | Rôle | Points clés |
|---|---|---|
| `Buyer` | Acheteur PunchOut | code unique, protocole `cxml`/`oci`, canal, **client obligatoire**, entreprise (id, optionnel), domaines de retour autorisés, origines iframe, unité / UNSPSC par défaut, schéma d'unités OCI, options taxes / port, locale |
| `BuyerIdentity` | Paires `From` / `Sender` (cXML) | unique (`role`, `domain`, `identity`) **globalement** (une identité désigne un seul acheteur) |
| `BuyerCredential` | Secret cXML / mot de passe OCI | empreinte Argon2id, libellé, `createdAt`, `revokedAt` ; au plus **2 actives** (rotation) |
| `PunchoutSession` | Une ouverture PunchOut | opération, `BuyerCookie`, URL de retour, contexte OCI, jeton de démarrage (empreinte SHA-256 + expiration), état, panier, `ItemOut` reçus (edit / inspect), `Extrinsic` minimisés, horodatages |
| `SeenPayload` | Anti-rejeu | unique (`buyer`, empreinte du `payloadID`), `expiresAt` ; purgé |
| `MessageLog` | Journal | direction, protocole, type, `payloadID`, statut, empreinte du corps, corps masqué (optionnel), acheteur, session |
| `TaxonClassification` / `ProductClassification` | UNSPSC et unité | une ligne par taxon / produit, sans modifier les entités Sylius |

États de `PunchoutSession` : `pending` (setup accepté, jeton émis) → `active` (jeton consommé) → `transferred` | `cancelled` ; `pending` → `expired` (jeton non consommé) ; `active` → `expired` (inactivité, purge).

## 5. Flux

### 5.1 Setup cXML (US-007/008/009/010)

1. `CxmlEndpointAction` : `POST`, `Content-Type` XML, corps ≤ `max_request_bytes` (défaut 256 Kio), limitation par IP. **Ne touche jamais la session** (aucun `Set-Cookie`).
2. `SafeXmlLoader` charge le document (voir § 7 et ADR-002) ; échec → `406`.
3. `CxmlRequestDispatcher` route selon l'élément de requête : `PunchOutSetupRequest`, `ProfileRequest` (`OrderRequest` en v1.1) ; autre → `450`.
4. `BuyerResolver` trouve l'acheteur par les identités `From` **et** `Sender` ; `CredentialVerifier` vérifie `Sender/Credential/SharedSecret` contre les empreintes actives (≤ 2). Échec, acheteur inconnu ou désactivé → `401` générique (même message, même temps : vérification factice si l'acheteur est inconnu).
5. `ReplayGuard` : `timestamp` dans ± `replay_window` (défaut 15 min) et `payloadID` jamais vu pour cet acheteur → sinon `400`.
6. Setup : opération ∈ {create, edit, inspect} sinon `450` ; `BrowserFormPost/URL` validée par `ReturnUrlValidator` → sinon `403`. `SessionFactory` crée la session `pending` (BuyerCookie, URL de retour, `ItemOut`, `Extrinsic` autorisés, `ShipTo` ignoré en v1), `StartTokenIssuer` émet le jeton.
7. Réponse `PunchOutSetupResponse` (`StartPage/URL` = `/punchout/start/{token}`), statut `200`, `Cache-Control: no-store`. Chaque échange est journalisé.

### 5.2 Démarrage de session (US-013)

`GET /punchout/start/{token}` : jeton cherché par empreinte SHA-256, non expiré (TTL 5 min), session `pending` ; consommé atomiquement (`UPDATE … WHERE status = 'pending'`) pour résister aux doubles requêtes. Les requêtes `HEAD` et les pré-chargements (`Purpose: prefetch`, `Sec-Purpose`) **ne consomment pas** le jeton.

`ShopperAuthenticator` : client rattaché présent, utilisateur `enabled`, `verifiedAt` non nul si le canal exige la vérification, canal de l'acheteur actif → `Security::login($user, 'form_login', 'shop')` ; puis régénération de l'id de session, écriture de la clé `_tmonier_punchout.session` (id + empreinte), création du panier par `PunchoutCartFactory` (canal, devise de base, locale, `setCustomer()` sans autorisation → `createdByGuest = true`) et `CartStorage::setForChannel`. Si un autre client était connecté dans ce navigateur, il est déconnecté avant.

Redirection : edit → panier ; inspect → `InspectAction` ; create → page d'accueil du canal (ou URL `Extrinsic` de démarrage, si un produit est ciblé : hors v1). En-têtes : `Referrer-Policy: no-referrer`.

### 5.3 Entrée OCI (US-012)

`GET|POST /punchout/oci/{buyerCode}` : limitation par IP ; acheteur OCI actif ; identifiants vérifiés (noms de paramètres configurables par acheteur, défaut `USERNAME` / `PASSWORD` : OCI n'en définit pas ; même vérification qu'en cXML) ; `HOOK_URL` validée par `ReturnUrlValidator` ; `returntarget`, `http_content_charset` et, s'ils sont présents, `~OkCode`, `~TARGET`, `~CALLER` conservés ; `FUNCTION` non vide → page d'erreur en v1 (VALIDATE / DETAIL en v1.1). Pas de jeton : la session est créée et démarrée dans la même requête (étapes de 5.2), puis redirection 303 (les identifiants ne restent pas dans l'historique). Anti-rejeu non applicable (pas de `payloadID`) : la limitation par IP et par acheteur fait office de protection.

### 5.4 Session boutique (US-014/015/016/017)

- `PunchoutCartContext` (priorité 0, avant -555 / -777) : actif seulement si la clé de session pointe une session `active` du client connecté ; sinon `CartNotFoundException`.
- `PunchoutSessionVoter` (`PUNCHOUT_SESSION`) alimente les conditions twig-hooks : bandeau, boutons « Transférer » et « Annuler » (formulaires POST avec CSRF **hors** du `<form>` du Live Component panier), masquage du bouton checkout et du lien du panier latéral.
- `CheckoutBlockerListener` (`kernel.request`, priorité 4) : en session PunchOut, `sylius_shop_cart_checkout`, `sylius_shop_checkout_*`, `sylius_shop_order_*` → redirection vers le panier avec message flash. `OrderCompleteGuard` refuse la transition `complete` pour un panier PunchOut (défense en profondeur ; variante winzou par listener Doctrine).
- `FrameHeadersListener` (`kernel.response`, priorité -16, après `XFrameOptionsSubscriber`) : en session PunchOut, retire `X-Frame-Options` et pose `Content-Security-Policy: frame-ancestors 'self' <origines de l'acheteur>`.
- Fin de session (`SessionCloser`) après transfert ou annulation : état final, clé de session supprimée, panier détaché (`CartStorage::removeForChannel`) puis supprimé ou conservé selon `keep_transferred_carts` (défaut : supprimé à la purge). Le client **reste connecté** au shop (pas de déconnexion forcée) ; son panier habituel redevient le panier courant.

### 5.5 Transfert (US-018/019/020/021)

1. `TransferAction` (POST + CSRF) : `process()` du panier, puis `TransferLineExtractor` produit des `TransferLine` (code de variante, `SupplierPartAuxiliaryID` = `v1:<code variante>`, description, quantité, prix unitaire net HT, devise, unité, UNSPSC, fabricant si disponible). Événement `TransferLineBuiltEvent` (extension : extrinsics, champs OCI personnalisés).
2. `NetPriceCalculator` (ADR-005) : prix unitaire = `getFullDiscountedUnitPrice()` (remises unitaires + part de la promotion de commande), taxe incluse retirée si le canal est TTC ; `Total` = somme des `quantité × prix unitaire` (hors taxes et port) ; `Tax` / `Shipping` ajoutés seulement si l'acheteur les active.
3. cXML : `OrderMessageWriter` (BuyerCookie, `operationAllowed` = `edit` pour create/edit, `inspect` pour inspect, `Total`, `ItemIn`) → `cXML-base64` ; OCI : `OciReturnFieldsBuilder` (`NEW_ITEM-DESCRIPTION/QUANTITY/UNIT/PRICE/CURRENCY/PRICEUNIT/VENDORMAT/EXT_PRODUCT_ID[n]`, `EXT_SCHEMA_TYPE` + `EXT_CATEGORY_ID` si un UNSPSC est connu, point décimal, troncature multioctet selon `OciFieldLimits` — DESCRIPTION 40, UNIT 3, VENDORMAT 40… —, description complète dans `NEW_ITEM-LONGTEXT_n:132[]`, champs `~` renvoyés s'ils ont été reçus ; page encodée en `http_content_charset`).
4. L'URL de retour est **revalidée** juste avant l'envoi. `TransferResponder` rend une page minimale : formulaire `method="post"` vers l'URL de retour, `target="_top"` (cXML) ou `returntarget` / `~TARGET` (OCI, défaut `_top`), auto-soumis (script inline avec nonce CSP) + bouton de secours sans JavaScript, `Cache-Control: no-store`.
5. Journalisation du message émis, puis `SessionCloser`.

Annulation (`CancelAction`) : même chemin avec zéro ligne (cXML sans `ItemIn`, OCI sans `NEW_ITEM`).

### 5.6 Re-punchout (US-022/023)

- **edit** : au démarrage, `CartRebuilder` lit les `ItemOut` de la session : variante par `SupplierPartAuxiliaryID` (`v1:<code>`), sinon par `SupplierPartID` ; contrôles canal / disponibilité / prix comme quick-order ; ajout groupé par `CartLinesAdderInterface` avec **un seul** `process()` ; les lignes écartées sont signalées par message flash. Ne dépend pas de l'ancien panier (expiré après 2 jours).
- **inspect** : pas de panier ; `InspectAction` affiche une page en lecture seule des `ItemOut` résolus et un bouton « Retour » qui renvoie un message sans `ItemIn`. Aucun blocage de route à maintenir.

### 5.7 Exploitation (US-024/025/027/028)

- `MessageLog` : entrée pour chaque requête reçue et chaque message émis ; corps stocké seulement si `log_bodies: true` (défaut `false` en prod), **toujours** masqué par `CxmlMasker` (`SharedSecret`, `PASSWORD`, e-mails, `Contact`, `Extrinsic` non autorisés). Grid admin filtrable (acheteur, type, statut, date).
- `tmonier:punchout:purge [--dry-run]` : supprime journaux, sessions closes ou expirées et `SeenPayload` au-delà de `retention_days` (défaut 90) ; paniers PunchOut abandonnés.
- `tmonier:punchout:erase-customer <email> [--dry-run]` : efface les données personnelles des sessions et des journaux d'un client (pattern timeline).
- `tmonier:punchout:doctor` : cookie de session (`samesite`, `secure`), HTTPS / `trusted_proxies`, acheteurs sans domaine autorisé ni secret actif, client rattaché désactivé.

## 6. Configuration du bundle

```yaml
tmonier_sylius_punchout_cxml:
    supplier_identity: { domain: 'NetworkId', identity: '%env(PUNCHOUT_SUPPLIER_ID)%' }  # From des messages émis
    start_token_ttl: 300          # secondes
    replay_window: 900            # secondes
    max_request_bytes: 262144
    retention_days: 90
    log_bodies: false
    keep_transferred_carts: false
    rate_limits:
        setup: { limit: 30, interval: '1 minute' }   # par IP
        oci_entry: { limit: 30, interval: '1 minute' }
    allowed_extrinsics: [UserEmail, UniqueName, CostCenter]   # les autres sont ignorés
```

Règle de `prepend()` : ne préparer que les nœuds nécessaires (twig-hooks, routes, rate limiter) pour ne pas résoudre les placeholders `%env()%` de l'application.

## 7. Sécurité (résumé, détail dans `architecture/security.md`)

- **XML non fiable** : refus de tout `DOCTYPE` comportant un sous-ensemble interne (`<!ENTITY`), aucun chargement de DTD externe ni réseau, pas de `LIBXML_NOENT`, taille plafonnée, profondeur limitée (`LIBXML_PARSEHUGE` interdit) ; le `DOCTYPE` externe standard cXML est toléré mais jamais résolu.
- **Authentification acheteur** : identités `From` + `Sender` + secret ; empreintes Argon2id (ADR-004), vérification en temps homogène, `401` générique, limitation par IP.
- **Rejeu** : `payloadID` unique par acheteur + fenêtre de `timestamp`.
- **Exfiltration du panier** : liste blanche de domaines HTTPS par acheteur, refus des IP littérales privées / réservées, revalidation à l'envoi.
- **Session** : jeton 256 bits à usage unique, TTL 5 min, stocké en SHA-256, consommation atomique ; régénération d'id ; contrôles manuels du compte avant `login()` (pré-auth 2.3 permissif).
- **Iframe** : `frame-ancestors` limité aux origines de l'acheteur, uniquement en session PunchOut ; documentation du cookie `SameSite=None; Secure`.
- **Données** : secrets jamais journalisés, masquage, rétention, effacement.
- **Tests** : aucun secret ni identifiant réaliste en dur (GitGuardian) — valeurs générées à l'exécution.

## 8. Points d'extension

| Point | Usage |
|---|---|
| `ItemClassificationResolverInterface` (tag `tmonier_punchout.classification_resolver`, priorité) | UNSPSC par marque, par attribut, etc. |
| `UnitResolverInterface` | unités depuis un attribut produit |
| `CartLinesAdderInterface` | ajout groupé (adaptateur cœur ; bascule quick-order si présent, plus tard) |
| `TransferLineBuiltEvent` / `TransferMessageBuiltEvent` | extrinsics propres à un acheteur, champs OCI `CUST_FIELD*` |
| `SetupRequestAcceptedEvent` | lecture des `Extrinsic` (centre de coût…) |
| `SessionStartedEvent` / `SessionClosedEvent` | intégrations (analytics, CRM) |
| Lecteur / écrivain cXML derrière interfaces | remplacement de la sérialisation (ADR-002) |

## 9. Stratégie de test

| Niveau | Contenu | Outils |
|---|---|---|
| Unit | lecteurs / écrivains cXML (fixtures XML), `SafeXmlLoader` (XXE, billion laughs, DOCTYPE interne, taille), `ReturnUrlValidator`, `NetPriceCalculator` (HT / TTC, arrondis, promotions), `OciReturnFieldsBuilder` (troncatures, décimales), `ReplayGuard`, `CredentialVerifier` | PHPUnit |
| Conformité | chaque document émis validé contre la **DTD cXML 1.2.071 locale** (`tests/Resources/dtd/`) via `DOMDocument::validate()` dans les tests uniquement | PHPUnit |
| Integration | repositories (unicité identités, `SeenPayload`), purge, effacement, contexte panier, voter | KernelTestCase, MySQL + PostgreSQL |
| Functional | aller-retour complet cXML (create, edit, inspect, annulation) et OCI depuis le **simulateur**, blocage checkout, en-têtes iframe, absence de `Set-Cookie` sur l'endpoint, CSRF du transfert | WebTestCase + simulateur |
| Sécurité | rejeu, secret faux / révoqué, URL de retour hors liste, jeton réutilisé / expiré, compte désactivé / non vérifié | functional |

Le **simulateur** vit dans `tests/TestApplication` (jamais chargé en production) : formulaire d'envoi de setup cXML / OCI, page de réception qui décode et valide le message reçu. Les secrets du simulateur sont générés à l'installation des fixtures.

Couverture cible ≥ 80 % (lignes) sur `src/`, 100 % sur `Cxml/`, `Security/`, `Transfer/NetPriceCalculator`.

## 10. Traçabilité exigences → conception

| Exigence | Conception |
|---|---|
| FR-1 à FR-4 (acheteurs, identités, secrets, domaines, mappings) | § 4, `Buyer/`, `Classification/`, admin |
| FR-5 (journal) | § 5.7, `Log/` |
| FR-6 à FR-8 (setup, profil, statuts) | § 5.1, `Cxml/`, `Security/` |
| FR-9 (OCI) | § 5.3, `Oci/` |
| FR-10 à FR-14 (session, panier, bandeau, checkout, iframe, fin) | § 5.2, § 5.4, `Session/`, `Cart/`, `EventListener/` |
| FR-15 à FR-17 (retour cXML / OCI, annulation) | § 5.5, `Transfer/` |
| FR-18 à FR-20 (edit / inspect) | § 5.6 |
| FR-21 / FR-22 (simulateur, diagnostic) | § 5.7, § 9 |
| FR-23 / FR-24 (v1.1) | § 11 |
| NFR-1 à NFR-8 | § 2, § 7, § 9, ADR-004 (performance) |

## 11. v1.1 (cadrage)

- `OrderRequest` sur le même endpoint : `OrderRequestReader`, création de commande par transitions de checkout (adresse `BillTo` / `ShipTo`, méthode de paiement « sur compte » configurée par acheteur), idempotence par `orderID` + acheteur, `ConfirmationRequest` hors périmètre.
- OCI `FUNCTION=VALIDATE` / `DETAIL` sur l'entrée OCI.

## 12. Décisions et points ouverts

| ADR | Sujet | Statut |
|---|---|---|
| ADR-001 | Données du plugin dans des tables propres, aucune modification des entités hôte | Accepté |
| ADR-002 | Sérialisation cXML : DOM maison en lecture et en écriture (`friendsofcxml/cxml-php` écartée, spike E) | Accepté |
| ADR-003 | Session PunchOut : jeton de démarrage, connexion du client rattaché, contexte panier dédié | Accepté |
| ADR-004 | Empreintes des secrets acheteurs (Argon2id) et budget de performance | Accepté |
| ADR-005 | Prix et totaux renvoyés (HT, hors port) | Accepté |
| ADR-006 | Iframe : en-têtes par session, cookie à la charge de l'application | Accepté |
| ADR-007 | inspect sans panier (page en lecture seule) | Accepté |

Points ouverts :
1. **Secure OCI** (`FUNCTION=INITIALIZE`, échange serveur-à-serveur) : hors v1, à ajouter au backlog (Could, après v1.1).
2. **Critères US-012 / US-020 à corriger** : `returntarget` et `http_content_charset` sont les paramètres standard ; `~OkCode` / `~TARGET` / `~CALLER` ne sont renvoyés que s'ils sont reçus (`analysis/F-oci-field-reference.md`).
3. **Saisie d'un secret existant** : certains outils d'achat imposent le secret (saisi par Marc). US-002 prévoit seulement la génération ; ajouter « saisir un secret fourni » (même empreinte Argon2id) — à confirmer.
