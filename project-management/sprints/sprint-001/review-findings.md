# Sprint 1 — constats de revue et corrections attendues

> 2026-10-14 · revue de conformité du code au regard de `tech-spec.md`, `architecture/security.md` et des ADR. Chaque constat se corrige en TDD : un test qui échoue d'abord, puis le correctif.

## À corriger dans le sprint 1 (bloquant)

| # | Gravité | Constat | Correction attendue | Test à écrire d'abord |
|---|---|---|---|---|
| R1 | Critique | `PunchoutCartFactory` et `SessionFactory` ne sont jamais appelées : `PunchoutCartContext` lève toujours « no cart assigned ». Le panier isolé n'existe pas. | Au démarrage (`StartAction`, après le login), créer le panier PunchOut : `setCustomer()` **sans** autorisation, donc `createdByGuest = true`, canal et devise de base de l'acheteur, locale. Le lier à `PunchoutSession` (colonne `order_id`), puis appeler `CartStorageInterface::setForChannel` **après** `login()`. `PunchoutCartContext` (`#[AsCartContext(priority: 0)]`) renvoie ce panier seulement si la session HTTP pointe une `PunchoutSession` **active** du client connecté. | Client avec un panier habituel non vide ; démarrage PunchOut ; le panier courant (`sylius.context.cart`) est le panier PunchOut vide, distinct du panier habituel. Ajouter un article via la route shop : il arrive dans le panier PunchOut. |
| R2 | Critique | `TransferAction` résout le panier par `findLatestCartByChannelAndCustomer`, puis `clearCart` vide ce panier, qui peut être le panier habituel du client. | Résoudre le panier **uniquement** par `PunchoutSession::getCart()`. Ne jamais toucher un autre panier. Fin de session : statut final, clé de session HTTP retirée, `CartStorage::removeForChannel`, panier PunchOut conservé (purge plus tard) ou supprimé selon `keep_transferred_carts`. | Le panier habituel du client est intact après transfert et après annulation ; le message transféré contient les lignes du panier PunchOut. |
| R3 | Critique | Le transfert n'a pas de protection CSRF (menace M10). | Formulaires « Transférer » et « Annuler » avec jeton CSRF (`tmonier_punchout_transfer`), vérifié dans les actions (POST uniquement). | POST sans jeton ou avec un jeton invalide : refus, session toujours active. |
| R4 | Critique | `OrderMessageWriter` écrit des identités `From` / `To` / `Sender` en dur (`NetworkId` / `supplier`, `buyer`). | `From` et `Sender` = `supplier_identity` configurée ; `To` = identité `From` reçue dans le setup (à conserver dans la session). Le `SharedSecret` n'apparaît jamais. | Le message décodé contient l'identité de l'acheteur reçue au setup et l'identité fournisseur configurée, sans `SharedSecret`. |
| R5 | Majeur | Prix HT déterminé par un indicateur `Buyer::taxIncluded` saisi à la main (contraire à l'ADR-0005). | Supprimer `taxIncluded` (entité, interface, mapping, migration, formulaire, tests). Prix unitaire net = `getFullDiscountedUnitPrice()` − (somme des ajustements de taxe **neutres** des unités ÷ quantité), arrondi au centime. `process()` du panier avant extraction. | Canal avec taux de taxe 20 % **inclus** (vrai `TaxRate`, zone, catégorie) : article à 120,00, panier traité par `process()`, `UnitPrice` = `100.00`. Avec un taux non inclus, le prix HT reste le prix catalogue. |
| R6 | Majeur | URL de retour non revalidée au moment du transfert ni de l'annulation. | Revalider avec `ReturnUrlValidator` juste avant d'émettre la page ; en cas d'échec, page d'erreur générique, rien n'est envoyé. | `returnUrl` modifiée en base en `http://…` : le transfert est refusé. |
| R7 | Majeur | `ShopperAuthenticator` incomplet. | Contrôler aussi : canal de l'acheteur actif ; `verifiedAt` non nul si le canal exige la vérification du compte ; déconnecter un autre client déjà connecté avant le login ; régénérer l'identifiant de session **avant** d'écrire la clé PunchOut. | Utilisateur non vérifié sur un canal qui exige la vérification : refus. Canal inactif : refus. Client A connecté puis démarrage pour le client B : la session est au nom de B. |
| R8 | Majeur | Paramètres de configuration ignorés : `max_request_bytes`, `start_token_ttl` et `allowed_extrinsics` ne sont jamais injectés. | Les lier dans `services.php` (`bind`) et les utiliser. | Test d'intégration : la valeur configurée est effective, par exemple un corps plus grand que la limite renvoie un statut `406`. |
| R9 | Mineur | `CxmlEndpointAction:143` : ternaire mort `? 200 : 200`. | Supprimer le ternaire. HTTP 200 avec statut cXML dans le corps reste le comportement voulu (`architecture/api.md`), sauf `413` et `429`. | — |
| R10 | Mineur | `SetupResponseWriter` : `htmlspecialchars` avant le DOM, d'où un double échappement ; `$buyerPayloadId` ignoré. | Laisser DOM échapper ; supprimer le paramètre inutile ou l'utiliser. | Texte d'erreur contenant `&` : échappé une seule fois. |
| R11 | Mineur | `CredentialVerifier` : un acheteur sans secret actif répond sans calcul Argon2id (écart de temps de réponse). | Vérification factice dans ce cas aussi. | Test unitaire : le hasher est appelé une fois. |
| R12 | Mineur | `ReturnUrlValidator` : IPv6 mappées IPv4 (`::ffff:127.0.0.1`) et `::` non bloquées ; hôte non normalisé. | Bloquer ces adresses ; passer l'hôte en minuscules. | Tests unitaires pour ces deux cas. |
| R13 | Mineur | `SetupRequestReader` : `SharedSecret` absent transmis comme `''`. | Secret absent ou vide : `401` immédiat, avec vérification factice. | Test fonctionnel. |
| R14 | Mineur | Unicité des identités par acheteur `(buyer_id, role, domain, identity)` au lieu d'une unicité globale (`architecture/erd.md`). | Index unique `(role, domain normalisé, identity)` ; erreur de validation dans le formulaire admin. | Deux acheteurs avec la même identité `From` : refus. |
| R15 | Mineur | `CLAUDE.md` cite `tmonier:punchout:doctor`, prévu au sprint 4. | Retirer la mention et la procédure de démo associée. | — |
| R16 | Mineur | Page de transfert sans en-têtes de sécurité. | `Cache-Control: no-store` ; CSP avec nonce pour le script d'auto-soumission ; `form-action` limité à l'origine de l'URL de retour. Template Twig plutôt qu'un heredoc. | Assertions sur les en-têtes. |

## Hors sprint 1 (noté, pas de correctif maintenant)

- Limitation de débit et anti-rejeu (`payloadID`, fenêtre de `timestamp`) : US-008, sprint 2. Garder la configuration, ne pas brancher.
- Journal des échanges et masquage : US-024, sprint 3.
- Durée d'inactivité d'une session active : sprint 2, avec US-017.

## Contre-vérification du coordinateur (2026-10-14)

Le rapport du correcteur annonçait R1 à R16 corrigés, avec des tests qui n'existaient pas : `testPunchoutCartIsIsolatedFromRegularCart`, `testTransferDoesNotTouchRegularCart`, `testCancelWithoutCsrfTokenReturns422`, `testRequestExceedingMaxBytesReturns406`, `testTransferPageHasCspHeader` et `BuyerAdminTest`. Les tests d'aller-retour et de TTC fabriquaient le panier et la taxe à la main. La relecture a aussi relevé quatre autres manques : l'admin US-001 n'était ni branché (routes, grille, ressources) ni utilisable, les traductions étaient dans un domaine que l'admin ne lit pas, le simulateur ne fonctionnait pas, et aucune interface boutique n'existait (bandeau, boutons).

Le coordinateur a corrigé ces points (commits `3f34db6`, `ca22189`, `91cf493`), avec des tests fonctionnels qui passent par de vraies requêtes :

| # | Preuve |
|---|---|
| R1, R2 | `PunchoutCartIsolationTest::testThePunchoutCartIsIsolatedFromTheRegularCartAndTransferred` (panier courant passant par le contexte Sylius, panier habituel intact) et `testAnotherCustomerLoggedInTheSameBrowserNeverGetsThePunchoutCart` |
| R3 | `testCancellingWithoutAValidCsrfTokenIsRefused`, `TransferActionTest`, régénération du secret protégée par CSRF |
| R4 | identités du message vérifiées par `OrderMessageWriterTest` ; message validé contre la DTD par `SimulatorRoundTripTest` |
| R5 | `testATaxIncludedRateIsRemovedFromTheTransferredPrice` et `testATaxAddedOnTopKeepsTheCatalogPrice` (vrai `TaxRate`, `process()`) |
| R6 | `TransferActionTest::testTransferWithTamperedReturnUrlReturns403` |
| R7 | `StartActionTest` (compte non vérifié), `testADisabledChannelRefusesTheSession`, `PunchoutFlowTest` (utilisateur désactivé) |
| R8 | `testAnOversizedSetupRequestIsRefused` |
| R14 | `BuyerAdminTest::testAFromIdentityBelongsToASingleBuyer` |
| R16 | `testTheTransferPageIsNotCachedAndOnlyPostsToTheReturnUrlOrigin` |
| US-001 | `BuyerAdminTest` (liste, création avec secret affiché une seule fois puis utilisable, client obligatoire, régénération) |
| US-018, US-021 | `testTheCartPageOffersTheTransferOnlyInAPunchoutSession`, `SimulatorRoundTripTest::testCancellingSendsAValidMessageWithoutItem` |
| US-026 | `SimulatorRoundTripTest::testTheSimulatorReceivesAValidPunchOutOrderMessage` |

Résultats en local : 68 tests unitaires et 39 tests non unitaires au vert. PHPStan au niveau max, ECS, Rector, `lint:container`, `composer validate --strict` et `doctrine:schema:validate` ne signalent aucune erreur.
