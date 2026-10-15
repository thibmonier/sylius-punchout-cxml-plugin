# Sprint 5 (v1.1) — revue de sécurité indépendante

Périmètre : `190c4a4..14dc0f6` (US-030 OrderRequest, US-031 OCI VALIDATE, US-032 OCI DETAIL). Revue en
lecture seule par un agent `security-auditor`, constats vérifiés dans le code avant correction.

| # | Gravité | Constat | Suite |
|---|---------|---------|-------|
| M1 | Majeur | Pas de contrôle de stock ni de limite : un bon peut tout mettre en attente (survente, blocage des autres clients) | Corrigé : `AvailabilityChecker` par ligne (400 « Insufficient stock »), limiteur `rate_limits.order_requests` par acheteur (60/heure) |
| M2 | Majeur | Quantités plafonnées (9999, lignes additionnées) ou tronquées (2.5 → 2) sans le dire, réponse 200 | Corrigé : entiers de 1 à 9999 seulement, quantité commandée comparée à la somme demandée (400) |
| M3 | Majeur | Anti-rejeu avant l'idempotence : un renvoi du même document après un délai dépassé reçoit 400 alors que la commande existe | Corrigé : bon connu répondu 200 avant le `ReplayGuard` ; le payloadID n'est consommé que pour un bon nouveau |
| m1 | Mineur | Pays contrôlé par regex seulement (TVA éludable via un pays hors zone) | Corrigé : pays activé et servi par le canal |
| m2 | Mineur | Exceptions imprévues en 500 HTML, message non journalisé | Corrigé : cXML 500 générique, erreur journalisée, échange journalisé |
| m3 | Mineur | Le catch d'unicité couvrait aussi le flush (EntityManager fermé non vidé) | Corrigé : catch limité à la revendication |
| m4 | Mineur | Bon revendiqué dont la commande a été supprimée : `200 Order ` sans numéro | Corrigé : 450 |
| m5 | Mineur | Idempotence sur l'orderID seul : un bon corrigé avec le même orderID était ignoré | Corrigé : empreinte SHA-256 du contenu, 400 si elle diffère |
| m6 | Mineur | Prix contrôlés avant le recalcul final | Corrigé : contrôle après le dernier `process` |
| m7 | Mineur | `deploymentMode="test"` créait une vraie commande | Corrigé : 450 |
| i1 | Info | Le secret cXML devient un moyen d'achat | Documenté (README) ; opt-in par méthode de paiement, limite par acheteur |
| i2 | Info | Les messages d'erreur citent codes et prix actuels | Accepté : destinataire authentifié, mêmes informations qu'en navigation |
| i3 | Info | VALIDATE comme oracle de prix scriptable | Accepté : mêmes informations qu'en navigation, limite par adresse et coût Argon2id par appel |
| i4 | Info | Caractères de contrôle / bidi dans notes et adresses ; commentaires imitant une note | Corrigé : caractères `\p{Cc}` / `\p{Cf}` retirés, commentaires préfixés « Buyer comments: ». Le `<Total>` du bon n'est pas comparé (port de la méthode choisie) : documenté |

Tests ajoutés : repost du même document → même commande ; même orderID, autre contenu → 400 ;
quantités 20000 / 2.5 / 0 et lignes répétées → 400 ; stock insuffisant → 400 ; pays non servi → 400 ;
bon de test → 450.
