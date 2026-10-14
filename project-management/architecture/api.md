# Interfaces externes (routes et messages)

> Phase 3 (Conception) · 2026-10-14

## Routes

| Route | Méthode | Chemin | Appelant | Auth | Session PHP |
|---|---|---|---|---|---|
| `tmonier_punchout_cxml` | POST | `/punchout/cxml` | outil d'achat (serveur) | identités + secret cXML | **jamais ouverte** |
| `tmonier_punchout_start` | GET | `/punchout/start/{token}` | navigateur | jeton à usage unique | ouverte, id régénéré |
| `tmonier_punchout_oci` | GET, POST | `/punchout/oci/{buyerCode}` | navigateur (depuis SAP) | identifiants OCI | ouverte, id régénéré |
| `tmonier_punchout_transfer` | POST | `/{_locale}/punchout/transfer` | navigateur | session PunchOut + CSRF | |
| `tmonier_punchout_cancel` | POST | `/{_locale}/punchout/cancel` | navigateur | session PunchOut + CSRF | |
| `tmonier_punchout_inspect` | GET | `/{_locale}/punchout/inspect` | navigateur | session PunchOut inspect | |
| admin (grids Sylius) | | `/admin/punchout/buyers…`, `/admin/punchout/logs…` | Sophie | `ROLE_ADMINISTRATION_ACCESS` | |

Les chemins sans locale tombent dans le firewall shop, sans `access_control` (D § 7). Réponses : `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` sur le démarrage.

## `/punchout/cxml`

- **Requête** : `Content-Type: text/xml` ou `application/xml` (les deux sont tolérés, certaines passerelles envoient `application/x-www-form-urlencoded` avec un champ `cXML-urlencoded` : accepté), corps ≤ 256 Kio, UTF-8.
- **Dispatch** : `Request/PunchOutSetupRequest`, `Request/ProfileRequest` ; `Request/OrderRequest` en v1.1 ; autre → `450`.
- **Réponse** : document `cXML` (`payloadID` = `<horodatage>.<aléa>@<hôte>`, `timestamp` ISO 8601 avec décalage, `xml:lang`), `<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">`, HTTP 200 dans tous les cas cXML (le statut est porté par `Response/Status`), sauf `429` (limitation) et `413` (taille) qui sont des réponses HTTP.

### Statuts cXML

| Code | Texte | Cas |
|---|---|---|
| 200 | OK | succès |
| 400 | Bad Request | rejeu, horodatage hors fenêtre, champ obligatoire manquant |
| 401 | Unauthorized | identités inconnues, secret faux ou révoqué, acheteur désactivé (message identique) |
| 403 | Forbidden | URL de retour hors liste blanche ou non HTTPS |
| 406 | Not Acceptable | document illisible, DOCTYPE interdit, encodage invalide |
| 450 | Not Implemented | opération ou requête non prise en charge |
| 500 | Internal Server Error | erreur inattendue (journalisée, texte générique) |

### `PunchOutSetupResponse`

```xml
<Response><Status code="200" text="OK"/>
  <PunchOutSetupResponse><StartPage><URL>https://shop.example/punchout/start/{token}</URL></StartPage></PunchOutSetupResponse>
</Response>
```

### `ProfileResponse`

Transactions : `PunchOutSetupRequest` (URL de l'endpoint, option `operationAllowed` = `create edit inspect`) ; `OrderRequest` en v1.1.

## `PunchOutOrderMessage` (retour cXML)

Champ de formulaire `cXML-base64` (base64 du document UTF-8), `action` = `BrowserFormPost/URL`, `target="_top"`.

```xml
<Header><From>{supplier_identity}</From><To>{From du setup}</To><Sender>{supplier_identity}…</Sender></Header>
<Message><PunchOutOrderMessage>
  <BuyerCookie>{reçu}</BuyerCookie>
  <PunchOutOrderMessageHeader operationAllowed="edit"><Total><Money currency="EUR">763.20</Money></Total></PunchOutOrderMessageHeader>
  <ItemIn quantity="3">
    <ItemID><SupplierPartID>{code variante}</SupplierPartID><SupplierPartAuxiliaryID>v1:{code variante}</SupplierPartAuxiliaryID></ItemID>
    <ItemDetail>
      <UnitPrice><Money currency="EUR">254.40</Money></UnitPrice>
      <Description xml:lang="fr">{nom}</Description>
      <UnitOfMeasure>EA</UnitOfMeasure>
      <Classification domain="UNSPSC">56101504</Classification>
    </ItemDetail>
  </ItemIn>
</PunchOutOrderMessage></Message>
```

`Tax` et `Shipping` dans l'en-tête seulement si l'acheteur les active. Annulation : aucun `ItemIn`, `Total` = 0.

## `/punchout/oci/{buyerCode}` et retour OCI

Entrée : `HOOK_URL` (obligatoire), identifiants (noms configurables), `returntarget`, `http_content_charset`, `FUNCTION` (v1 : absente), champs `~` facultatifs. Retour : formulaire POST vers `HOOK_URL` avec `NEW_ITEM-*[n]` (table dans `analysis/F-oci-field-reference.md`).

## Commandes console

| Commande | Options |
|---|---|
| `tmonier:punchout:purge` | `--dry-run` |
| `tmonier:punchout:erase-customer <email>` | `--dry-run` |
| `tmonier:punchout:doctor` | code de sortie ≠ 0 si un problème bloquant est détecté |
