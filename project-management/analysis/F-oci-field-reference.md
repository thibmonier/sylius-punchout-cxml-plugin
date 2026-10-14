# F. Référence des champs SAP OCI 5.0

> Phase Conception · 2026-10-14
> **Source lue** : PDF SAP OCI Release 5.0 (SAP Online Help, daté du 04.10.2012), https://help.sap.com/doc/srm/1.0/en-US/attachments/3362936504/OCI_50_EN20130121.pdf. Pages lues : 1 à 20 sur environ 52 (appels § 2, retour formulaire § 3, fenêtres § 4, Secure OCI § 5.1).
> **Non lu** : § 6 (mass upload JSON, ajout 5.0) et § 6.8 (règles de format). Ils sont hors périmètre v1.

## 1. Champs de retour `NEW_ITEM-*` (§ 3.2)

Syntaxe : `NEW_ITEM-<Nom>[<index>]`. Tous les champs sont de type CHAR et l'index démarre à 1.

| Champ | Long. max | Obligatoire | Description / format | Usage dans le plugin |
|---|---|---|---|---|
| DESCRIPTION[n] | 40 | oui si MATNR absent (l'un ou l'autre) | description de l'article | nom de la variante, tronqué |
| MATNR[n] | 40 | oui si DESCRIPTION absent | numéro produit **dans le SRM de l'acheteur** | non utilisé |
| QUANTITY[n] | 15 | **toujours** | 11 chiffres + 3 décimales, point, sans séparateur de milliers | quantité |
| UNIT[n] | 3 | oui si MATNR absent | code ISO | unité (schéma au choix de l'acheteur) |
| PRICE[n] | 15 | non | prix **par unité de prix**, même format que QUANTITY | prix unitaire net HT |
| CURRENCY[n] | 5 | oui si PRICE rempli | code ISO | devise de base du canal |
| PRICEUNIT[n] | 5 | non (défaut 1) | entier | `1` |
| LEADTIME[n] | 5 | non | délai en jours, entier | via un événement |
| LONGTEXT_n:132[] | illimitée | non | texte long, syntaxe d'index spéciale (note 4) | description complète |
| VENDOR[n] | 10 | non | partenaire d'affaires dans le SRM | option par acheteur (n° fournisseur côté client) |
| VENDORMAT[n] | 40 | non | référence fournisseur | code de variante |
| MANUFACTCODE[n] | 10 | non | numéro fabricant dans le SRM | non utilisé |
| MANUFACTMAT[n] | 40 | non | référence fabricant | via un événement |
| MATGROUP[n] | 10 | non | groupe de marchandises SRM | via un événement (mapping propre au client) |
| SERVICE[n] | 1 | non | indicateur service | non utilisé |
| CONTRACT[n] / CONTRACT_ITEM[n] | 10 / 5 | CONTRACT si CONTRACT_ITEM | contrat SRM | via un événement |
| EXT_QUOTE_ID[n] / EXT_QUOTE_ITEM[n] | 35 / 10 | EXT_QUOTE_ID si EXT_QUOTE_ITEM | devis externe | synergie quote-request (plus tard) |
| EXT_PRODUCT_ID[n] | 40 | recommandé, requis pour DETAIL / VALIDATE | clé unique de l'article au catalogue | `v1:<code variante>` (même clé que `SupplierPartAuxiliaryID`) |
| ATTACHMENT[n] / ATTACHMENT_TITLE[n] / ATTACHMENT_PURPOSE[n] | 255 / 255 / 1 | non | pièce jointe | non utilisé |
| EXT_SCHEMA_TYPE[n] | 10 | oui si EXT_CATEGORY_ID ou EXT_CATEGORY | nom du schéma (ex. `UNSPSC`) | `UNSPSC` si un code est connu |
| EXT_CATEGORY_ID[n] | 60 | non | clé de catégorie indépendante de la version | code UNSPSC |
| EXT_CATEGORY[n] | 40 | non | clé de catégorie dépendante de la version | non utilisé |
| SLD_SYS_NAME[n] | 60 | non | système SLD | non utilisé |
| CUST_FIELD1..3[n] | 10 | non | champs libres | via un événement |
| CUST_FIELD4[n] | 20 | non | champ libre | via un événement |
| CUST_FIELD5[n] | 50 | non | champ libre | via un événement |
| ITEM_TYPE[n] / PARENT_ID[n] | 1 / 5 | non | hiérarchie (SRM 7.0) | non utilisé |

Règles de dépendance (§ 3.2.1) : QUANTITY est toujours requis, ainsi qu'exactement un champ parmi DESCRIPTION et MATNR. L'include client `CI_OCI_CUSTOMER_EXTENSION` permet d'ajouter des champs, que le plugin expose par événement.

## 2. Paramètres d'appel (§ 2.3, § 2.4)

| Paramètre | Rôle | Plugin |
|---|---|---|
| `HOOK_URL` | URL de retour, à placer dans l'`action` du formulaire, sans query string | obligatoire, validé contre la liste blanche |
| `returntarget` | `target` du formulaire de retour (ex. `_parent`) | utilisé s'il est fourni, sinon `_top` |
| `http_content_charset` | charset du SRM (ex. `iso-8859-1`) | page de retour encodée et déclarée dans ce charset |
| `FUNCTION` | `DETAIL`, `VALIDATE`, `SOURCING`, `BACKGROUND_SEARCH`, `INITIALIZE` | v1 : absente ou vide = navigation ; autre valeur = page d'erreur. v1.1 : VALIDATE / DETAIL |
| `PRODUCTID`, `QUANTITY` | DETAIL / VALIDATE | v1.1 |
| `SEARCHSTRING`, `VENDOR` | recherche, sourcing | hors périmètre |
| `OCI_VERSION`, `OPI_VERSION` | informatifs | journalisés |

**Écarts avec l'analyse précédente :**
- `~OkCode`, `~TARGET` et `~CALLER` **ne figurent pas dans la spec OCI 5.0**. Ce sont des conventions des anciens SRM / ITS, encore envoyées par certaines installations. Le plugin les **renvoie tels quels s'ils sont reçus** (`~TARGET` sert de repli à `returntarget`), sans les exiger.
- `USERNAME` / `PASSWORD` ne sont **pas standard** : chaque fournisseur définit son authentification. Le plugin accepte les noms de paramètres configurables par acheteur (défaut `USERNAME` / `PASSWORD`). Secure OCI (§ 5.1 : `SECURE_AUTH_URL`, `FUNCTION=INITIALIZE`, échange serveur-à-serveur avec réponse `<SecureOCI>`) est **hors v1** et noté au backlog.

## 3. Règles

- **Retour** : formulaire HTML POST (recommandé, GET limité en longueur), champs `hidden`.
- **Décimales** : point, sans séparateur de milliers ; QUANTITY et PRICE limités à 3 décimales. Le plugin formate les montants à 2 décimales et les quantités en entiers.
- **Unités et devises** : codes ISO (note 3). Aucune table T006 ni UN/ECE n'est donnée dans la spec. Le plugin envoie l'unité résolue (défaut `EA`) et l'acheteur peut définir une table de correspondance (`UnitCodeMapper`).
- **Troncature** : la spec ne donne que des maxima. Le plugin tronque proprement (multioctet, sans couper une entité) chaque champ à sa longueur, et place la description complète dans `LONGTEXT_n:132[]`.
- **Fenêtres** : ne pas renommer la fenêtre d'origine ; fermer les fenêtres secondaires avant le retour.
- **Nombre maximal de lignes** : non précisé dans les sections lues. Le plugin ne fixe pas de limite propre et documente ce point.

## 4. OCI 4.0 et 5.0

Le transfert par formulaire et les champs de base sont inchangés, 5.0 restant compatible 4.0. Ajouts 5.0 : mass upload JSON et réplication pour Enterprise Search (SRM UI Add-On), hors périmètre. Secure OCI est mentionné tantôt comme 4.0, tantôt comme 5.0 (incohérence du document).

## 5. Impacts sur la conception

1. `OciFieldLimits` : table ci-dessus (DESCRIPTION 40, QUANTITY 15, UNIT 3, PRICE 15, CURRENCY 5, VENDORMAT 40, EXT_PRODUCT_ID 40, EXT_SCHEMA_TYPE 10, EXT_CATEGORY_ID 60, CUST_FIELD1-5 : 10 / 10 / 10 / 20 / 50).
2. US-012 et US-020 : critères à corriger (`returntarget` / `http_content_charset` ; `~OkCode` renvoyé seulement s'il est reçu).
3. Backlog : ajouter « Secure OCI » (Could, après v1.1).
