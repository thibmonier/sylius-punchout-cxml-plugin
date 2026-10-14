# A. Référence d'implémentation cXML PunchOut

> Phase Analyse · 2026-10-14 · Source primaire : **cXML Reference Guide 1.2.071** (SAP SE,
> 2026-08-14), pages citées ; DTD `cXML/1.2.071/cXML.dtd` (cxml.org). [NON VÉRIFIÉ] = non lu
> dans la source primaire.

## 1. Enveloppe `<cXML>` (p. 24-27)

| Attribut | Obligatoire | Règle |
|---|---|---|
| `payloadID` | Oui | Unique ; format recommandé `datetime.process_id.random@hostname` ; identique lors d'une relance |
| `timestamp` | Oui | ISO 8601 **avec décalage** `YYYY-MM-DDThh:mm:ss±hh:mm` ; **`Z` interdit** ; identique lors d'une relance |
| `xml:lang` | Recommandé | Locale des textes ; répondre dans la même locale |
| `version` | Déprécié (1.2.007) | Ne pas émettre ; la version est dans le SYSTEM de la DOCTYPE |
| `signatureVersion` | Non | `1.0` si signé |

- DOCTYPE émis : `<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">` — les documents pointent vers cxml.org, les DTD sont **mises en cache localement** pour la validation (p. 15).
- UTF-8 ; `Content-Type: text/xml; charset="UTF-8"` ; **aucun caractère avant `<?`** (p. 26).

## 2. Header (p. 28-31, 67-68, 80)

- `From` (originateur logique), `To` (destinataire logique), `Sender` (partie qui ouvre la connexion HTTP ; remplacé par chaque hub relais ; = `From` à l'émission initiale).
- `Credential@domain` obligatoire (`NetworkId`, `DUNS`, `AribaNetworkUserId`, `BusinessPartnerId`…), contient `Identity` + optionnellement `SharedSecret` ou `CredentialMac`. Plusieurs `Credential` possibles ; valider tous les domaines reconnus.
- **`SharedSecret` uniquement dans `Sender`** ; jamais dans un document qui transite par le navigateur (visible dans le source de la page, p. 30).
- `UserAgent` : « Nom_Logiciel Version », unique par produit/version.
- **Flux hub (Ariba Network)** : le hub authentifie l'acheteur (`From` + secret acheteur), identifie le fournisseur (`To`), insère **le secret du fournisseur** dans `Sender`, transmet. Le fournisseur compare ce secret à sa configuration (p. 67-68).

## 3. PunchOutSetupRequest (p. 60, 65-67, 81-83)

DTD : `PunchOutSetupRequest (BuyerCookie, Extrinsic*, BrowserFormPost?, Contact*, SupplierSetup?, ShipTo?, SelectedItem?, ItemOut*)`, attribut `operation` ∈ `create | edit | inspect | source`.

| Élément | Card. | Rôle |
|---|---|---|
| `BuyerCookie` | 1 | Opaque, **renvoyé inchangé** dans le `PunchOutOrderMessage` ; change à chaque session (ne pas l'utiliser comme identifiant de session) |
| `Extrinsic` | 0..* | `UserEmail`, `UniqueName`, `CostCenter`, `Region`, `Supervisor`… (depuis 1.1, `Contact` remplace `CostCenter`/`User`) |
| `BrowserFormPost/URL` | 0..1 | Destination du formulaire de retour ; absent ⇒ pas de retour par formulaire |
| `Contact` | 0..* | Coordonnées de l'utilisateur |
| `SupplierSetup` | 0..1 | **Déprécié** (1.1), à ignorer |
| `ShipTo` | 0..1 | Adresse ; peut servir aux prix / délais |
| `SelectedItem` | 0..1 | PunchOut ciblé (rayon / produit) ; pas pour `source` |
| `ItemOut` | 0..* | `edit` / `inspect` : articles existants (`SupplierPartID` + `SupplierPartAuxiliaryID`) |

Sémantique : `create` = nouveau panier ; `edit` = réouverture modifiable (ItemOut fournis) ; `inspect` = lecture seule ; `source` = RFQ (hors périmètre).

## 4. PunchOutSetupResponse (p. 34-36, 69, 84)

```xml
<Response><Status code="200" text="success"/>
  <PunchOutSetupResponse><StartPage><URL>https://shop.example/punchout/start/7f3a…</URL></StartPage></PunchOutSetupResponse>
</Response>
```
- Pas de `Header` dans une `Response` (même connexion HTTP).
- `StartPage/URL` **unique par session, durée de vie limitée**, porte l'état liant la session au `BuyerCookie`.
- Statuts : `200` OK · `400` erreur permanente · `401` authentification · `403` interdit · `406` parsing · `412` session d'édition non appropriée · **`450` opération non supportée** · `500` transitoire (relances) · `550` serveur injoignable.

## 5. PunchOutOrderMessage (p. 70, 85-94)

Modèle **One-Way** : `<Message>` (pas `<Request>`), avec `Header` (From/To/Sender **sans SharedSecret**).

- `BuyerCookie` (copie exacte).
- `PunchOutOrderMessageHeader@operationAllowed` ∈ `create | inspect | edit` (sessions ultérieures permises) ; `quoteStatus` (RFQ, hors périmètre).
  - `Total/Money` **obligatoire**, **hors taxes et frais de port** ; `Shipping`, `Tax`, `ShipTo` optionnels.
- **Panier vide** : en `create` = session annulée ; en `edit` = supprimer les articles de la réquisition ; en `inspect` = aucun `ItemIn` ; HTTP `204` = fin sans changement.
- `ItemIn@quantity` obligatoire (décimal ≥ 0) ; autres attributs optionnels (`lineNumber`, `itemType`, `itemClassification`…).
- `ItemID` : `SupplierPartID` **obligatoire** ; `SupplierPartAuxiliaryID` = « numéro de devis » du fournisseur, **stable en edit/inspect**, renvoyé dans l'`OrderRequest`, peut encoder la configuration (échapper le XML).
- `ItemDetail` — **minimum obligatoire** : `UnitPrice/Money@currency`, `Description@xml:lang` (option `ShortName` 30 car. recommandés, 50 max), `UnitOfMeasure` (code **UN/CEFACT**, ex. `EA`), `Classification@domain` (**UNSPSC**). Optionnels : `ManufacturerPartID`, `ManufacturerName`, `URL`, `LeadTime` (jours), `PriceBasisQuantity`, `Extrinsic`…

## 6. Retour navigateur (p. 37-41, 76-77)

- Formulaire HTML auto-soumis (POST) vers `BrowserFormPost/URL`, **un seul** champ caché : `cXML-base64` (XML complet en base64, UTF-8, **préférer**) ou `cXML-urlencoded` (XML échappé HTML, guillemets simples dans le XML, us-ascii, le navigateur URL-encode ; ne pas URL-encoder côté serveur). Noms insensibles à la casse ; le récepteur lit d'abord `cXML-base64`.
- Bouton « Annuler » = `PunchOutOrderMessage` sans `ItemIn`.

## 7. Pages fournisseur et sessions (p. 58-62, 71-78)

1. **Launch page** : reçoit le setup, valide (secret dans `Sender`), extrait `BuyerCookie` / `BrowserFormPost` / identité, répond `StartPage` temporaire ; gère edit/inspect.
2. **Start page** : authentifie l'utilisateur dans son compte, démarre la navigation. **Cookies de session détruits après l'envoi du `PunchOutOrderMessage`.**
3. **Sender page** : panier → « Check out » → formulaire `PunchOutOrderMessage`.
4. **Order receiver page** : reçoit les `OrderRequest` (cf. §8).

Recommandations : valider contre la DTD ; `xml:lang` pour la langue ; `From` identifie l'organisation acheteuse ; URL unique et temporaire ; **ne pas persister les cookies navigateur** ; UN/CEFACT + UNSPSC sur chaque article ; implémenter edit et inspect ; en inspect, afficher le statut.

## 8. ProfileRequest / OrderRequest (p. 46-55, 104-112)

- **`ProfileRequest` obligatoire pour tout serveur cXML ≥ 1.1** (élément vide ; ping + capacités). `ProfileResponse@effectiveDate` (obligatoire, pas dans le futur), `Transaction@requestName` + `URL` + `Option` (pour `PunchOutSetupRequest` : `operationAllowed`, `Direct.URL`, `Direct.AuthenticationMethod.*` ; pour `OrderRequest` : `attachments`, `changes`).
- **`OrderRequest`** (bon de commande définitif après approbation) : `OrderRequestHeader@orderID` et `@orderDate` obligatoires, `type` `new|update|delete`, `orderType`… ; éléments `Total` et **`BillTo` obligatoires**, `ShipTo`, `Shipping`, `Tax`, `Payment`, `PaymentTerm`, `Contact`, `Comments`, `Extrinsic` ; lignes `ItemOut` (mêmes `SupplierPartID` / `SupplierPartAuxiliaryID` que les `ItemIn`). Réponse : `Response/Status` (`200 OK`).

## 9. Direct PunchOut (p. 96-97)

Acheteur → fournisseur sans hub ; **SharedSecret interdit** ; authentification par `CredentialMac` (`FromSenderCredentials`, HMAC-SHA1-96, dates de validité) ou par certificat (`AuthRequest` au hub) ; annoncé via `Option name="Direct.URL"` dans le `ProfileResponse`. Hors périmètre v1.

## 10. Versions

DTD par famille (`cXML.dtd`, `Fulfill.dtd`, `InvoiceDetail.dtd`, `Catalog.dtd`…) sous `/schemas/cXML/<VERSION>/`. Repères : 1.1 (`SelectedItem`, `Contact`, `SupplierSetup` déprécié), 1.2.007 (`version` déprécié), 1.2.071 courante. Politique de compatibilité ascendante : [NON VÉRIFIÉ] (pratique : les mineures ajoutent de l'optionnel) → accepter en entrée toute 1.2.0xx, émettre la DTD cible fixe.

## 11. Outils de test sans compte acheteur [sources secondaires]

- Validation : `xmllint --dtdvalid <cXML.dtd local> --noout doc.xml` (DTD en cache dans le dépôt de tests).
- Setup : POST brut (`curl -H 'Content-Type: text/xml' -d @setup.xml`), fixtures issues des exemples du guide.
- Retour : page HTML de réception locale (simulateur d'acheteur dans l'app de test) ; tunnel (ngrok) pour un vrai acheteur.
- Sandboxes de passerelles (TradeCentric / PunchOut2Go) : [NON VÉRIFIÉ].

## Exemples (adaptés du guide p. 65-70)

> Adaptations volontaires : e-mail réaliste au lieu de `jsmith`, secret masqué, prix unitaire
> cohérent avec le total (3 × 254.40 = 763.20), code UNSPSC à 8 chiffres. L'exemple original du
> guide met `UnitPrice` = `Total` = 763.20 pour une quantité de 3.

**Setup request** (hub → fournisseur) :
```xml
<cXML xml:lang="en-US" payloadID="933694607118.1869318421@jlee" timestamp="2002-08-15T08:36:47-07:00">
  <Header>
    <From><Credential domain="DUNS"><Identity>65652314</Identity></Credential></From>
    <To><Credential domain="DUNS"><Identity>83528721</Identity></Credential></To>
    <Sender><Credential domain="NetworkId"><Identity>AN200001</Identity><SharedSecret>…</SharedSecret></Credential>
      <UserAgent>Procurement System 2.0</UserAgent></Sender>
  </Header>
  <Request><PunchOutSetupRequest operation="create">
    <BuyerCookie>1CX3L4843PPZO</BuyerCookie>
    <Extrinsic name="UserEmail">jsmith@bigbuyer.example</Extrinsic>
    <BrowserFormPost><URL>https://bigbuyer.example:2600/punchout?client=NAwl4Jo</URL></BrowserFormPost>
  </PunchOutSetupRequest></Request>
</cXML>
```

**Order message** (navigateur → acheteur) :
```xml
<cXML xml:lang="en-US" payloadID="933695160894" timestamp="2002-08-15T08:47:00-07:00">
  <Header><!-- From/To inversés, Sender sans SharedSecret --></Header>
  <Message><PunchOutOrderMessage>
    <BuyerCookie>1CX3L4843PPZO</BuyerCookie>
    <PunchOutOrderMessageHeader operationAllowed="edit"><Total><Money currency="USD">763.20</Money></Total></PunchOutOrderMessageHeader>
    <ItemIn quantity="3">
      <ItemID><SupplierPartID>5555</SupplierPartID><SupplierPartAuxiliaryID>E000028901</SupplierPartAuxiliaryID></ItemID>
      <ItemDetail><UnitPrice><Money currency="USD">254.40</Money></UnitPrice>
        <Description xml:lang="en"><ShortName>Excelsior Desk Chair</ShortName>Leather Reclining Desk Chair</Description>
        <UnitOfMeasure>EA</UnitOfMeasure><Classification domain="UNSPSC">56101504</Classification><LeadTime>12</LeadTime>
      </ItemDetail>
    </ItemIn>
  </PunchOutOrderMessage></Message>
</cXML>
```
