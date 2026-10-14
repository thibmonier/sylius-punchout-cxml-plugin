# C. Écosystème, positionnement et bibliothèques

> Phase Analyse · 2026-10-14 · recherches GitHub (`gh api`), Packagist (API JSON), docs officielles.

## 1. Sylius Plus / B2B Suite : PunchOut absent

- Aucune occurrence de PunchOut, cXML, OCI, Ariba, Coupa, SAP, e-procurement ou EDI sur sylius.com/plus, addons.sylius.com (B2B Suite), docs.sylius.com, old-docs.sylius.com, ni dans l'article « Sylius Plus module overview: B2B Suite ».
- B2B Suite 4.0.0-ALPHA.6 (2026-08-18, Sylius 1.11 à 2.2) : organisations, listes de prix, quick order, RFQ, wishlists multiples, import/export ERP/PIM **générique**, RBAC, Elasticsearch. Approbations / bons de commande : non mentionnés publiquement.
- **Positionnement : complémentaire** (couche e-procurement absente du produit commercial). Conforme à la règle de communication : catégorie « comble un manque de Plus B2B » → promotion possible, en le présentant comme une intégration procurement et non comme une copie de feature Plus.

## 2. Plugins Sylius existants

`gh api search/repositories` (sylius punchout / sylius cxml) : 0 ; Packagist (sylius punchout, punchout) : aucun paquet Sylius ; addons.sylius.com : aucune catégorie PunchOut / EDI. **Niche libre.**

## 3. Bibliothèques PHP

| | friendsofcxml/cxml-php | hscheffknecht/cxml (herbert/cxml) | beethoven/phpcxml | mathielen/cxml |
|---|---|---|---|---|
| Licence | MIT | MIT | MIT | MIT |
| Dernière release | **2.2.5 (2026-09-16)**, 40+ releases depuis 2022 | aucune version (dev-main) | dev-master (2016) | abandonné |
| PHP | **≥ 8.2** | non déclaré | ≥ 5.6 | — |
| Dépendances | ext-dom/simplexml/libxml, beberlei/assert, **jms/serializer ^3.30**, psr/log, psr/event-dispatcher | aucune | guzzle 6… | — |
| Couverture | PunchOutSetupRequest/Response, PunchOutOrderMessage, OrderRequest, ProfileRequest, Confirmation, ShipNotice, StatusUpdate, CatalogUpload… | setup + order message | générique | — |
| Qualité | tests, PHPStan, Rector, 0 issue | tests | — | — |
| Téléchargements | ~30 000 / mois | ~2 900 / mois | 7 au total | — |

**Recommandation de l'analyse C : `friendsofcxml/cxml-php`** (plutôt qu'un sérialiseur maison, estimé à 3 à 5 jours).
Point à arbitrer en conception (ADR) : dépendance transitive à `jms/serializer` (Sylius 2 n'utilise plus JMS par défaut) et façon dont la lib parse le XML (options libxml → vérifier l'absence de `LIBXML_NOENT`). Aucune lib OCI PHP : OCI = formulaires plats, implémentation maison simple.

## 4. Passerelles SaaS

Modèle : système d'achat ↔ passerelle (traduction des formats propriétaires, mapping des champs) ↔ **boutique exposant des endpoints cXML standard**. Un plugin cXML conforme est donc compatible (et même prérequis) avec TradeCentric (ex-PunchOut2Go), PunchCommerce, Abeta, PunchOut Catalogs. Nuance : Ariba, Coupa et Jaggaer ajoutent des extrinsics et validations propres → prévoir des points d'extension (mapping des `Extrinsic`, champs par acheteur).

## 5. Autres plateformes (aucune n'a de PunchOut natif, sauf SAP Commerce Cloud)

| Plateforme | Offre |
|---|---|
| Shopware 6 | PunchCommerce (freemium + abonnement), AGIQON SAP OCI (payant), Abeta (MIT, lié à sa plateforme) |
| OroCommerce | Vurbis PunchOut Connector (propriétaire) |
| Spryker | spryker-eco/punchout-gateway (propriétaire, Early Access), punchout-catalogs (abandonné) |
| Magento / Adobe Commerce | Webkul (999 $), Takeoff, PunchOut Catalogs, Greenwing, tradecentric/module-punchout — tous commerciaux |
| PrestaShop | rien |
| SAP Commerce Cloud | cXML natif (OCI 4 déprécié) |

→ Un plugin Sylius **open source MIT** serait le seul PunchOut libre parmi les plateformes PHP majeures.

## Sources

sylius.com/plus · addons.sylius.com/en_US/products/b2b-suite · old-docs.sylius.com · plugins.sylius.com/blog/sylius-plus-module-overview-b2b-suite · packagist.org (friendsofcxml/cxml-php, herbert/cxml, beethoven/phpcxml, mathielen/cxml, abeta-io/shopware6, spryker-eco/punchout-gateway, punchout-catalogs/punchout-catalog-spryker) · github.com/FriendsOfCXML/cxml-php · github.com/hscheffknecht/cxml · tradecentric.com · store.shopware.com (PunchCommerce) · docs.spryker.com (punchout-gateway) · store.webkul.com · commercemarketplace.adobe.com · iweb.co.uk (différences Coupa / Ariba / Jaggaer).
