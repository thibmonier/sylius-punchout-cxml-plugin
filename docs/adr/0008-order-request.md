# ADR-0008 — Réception des OrderRequest

- **Statut** : Accepté (2026-10-15)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, v1.1 (US-030)

## Contexte

Après approbation, l'outil d'achat envoie le bon de commande (`OrderRequest`) au même endpoint cXML. Il faut en faire une commande Sylius sans checkout navigateur, sans doublon, et sans engager le fournisseur sur un prix qu'il ne pratique plus.

## Décision

- **Opt-in par acheteur** : un acheteur reçoit des `OrderRequest` seulement si une méthode de paiement « sur compte » lui est configurée (méthode de livraison facultative, sinon la première méthode éligible). Sans elle : cXML 450.
- **Authentification et anti-rejeu** identiques au `PunchOutSetupRequest` ; seul un acheteur `cxml` est accepté.
- **Idempotence** par acheteur et `orderID` (table `tmonier_punchout_order_request`, index unique, empreinte SHA-256 du contenu) : un repost répond 200 avec la même commande, sans en créer une nouvelle, avant l'anti-rejeu (un renvoi du même document après un délai dépassé n'est pas un rejeu) ; le même `orderID` avec un autre contenu est refusé (400). Seul `type="new"` est traité ; `update` / `delete` et `deploymentMode="test"` : 450. Au plus `rate_limits.order_requests` nouveaux bons par acheteur.
- **Articles** : variante par `SupplierPartAuxiliaryID` (`v1:<code>`) sinon `SupplierPartID`, comme la réédition (US-022). Un article non vendu sur le canal, hors stock, ou une quantité hors de 1 à 9999 (lignes répétées comprises) refuse tout le bon (400) : pas de commande partielle. La machine à états ne contrôlant pas le stock, le plugin le vérifie (`AvailabilityChecker`).
- **Prix** : la commande est calculée par Sylius (prix du client, promotions, taxes), le contrôle porte sur les montants finaux (après livraison et paiement). Si le prix unitaire net d'une ligne diffère du `UnitPrice` du bon (à 0,01 près) ou si la devise diffère, le bon est refusé (400, lignes concernées dans le texte) : l'acheteur relance une réédition PunchOut. Aucun prix n'est imposé à Sylius.
- **Adresses** : `ShipTo` et `BillTo` d'en-tête obligatoires ; `Name` → société, `DeliverTo` → prénom / nom, `Street`, `City`, `PostalCode`, `Country@isoCountryCode`, `State`. Les `ShipTo` de ligne ne sont pas gérés (1.1). Le pays doit être activé et, si le canal en liste, l'un d'eux (taxes). Caractères de contrôle et de format retirés des adresses et des commentaires, repris en notes sous « Buyer comments: ».
- **Création** : transitions de checkout `address` → `select_shipping` → `select_payment` → `complete` par la machine à états de Sylius (numéro de commande, stock, e-mail habituels). L'`orderID` est repris dans les notes de la commande. `ConfirmationRequest` / `ShipNoticeRequest` hors périmètre.

## Alternatives considérées

- Imposer les prix du bon (articles immuables) : écarté, engagement silencieux sur des prix périmés, interaction fragile avec taxes et promotions.
- Accepter un bon partiel : écarté, l'outil d'achat croirait la commande complète.

## Conséquences

- Le bon reflète exactement une commande que la boutique accepte ; un écart de prix coûte une réédition à l'acheteur.
- Les synergies trade-credit / order-hold passent par la méthode de paiement configurée.
