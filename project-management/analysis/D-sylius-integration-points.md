# D. Points d'intégration Sylius 2.3

> Phase Analyse · 2026-10-14 · exploré dans le vendor installé : Sylius v2.3.0, Symfony 7.4.20,
> twig-hooks 0.14.1 (via `sylius-quick-order-plugin/vendor`). Préfixe `S/` =
> `vendor/sylius/sylius/src/Sylius/`. Références de patterns : quote-request (QR), quick-order (QO),
> b2b-mcp (MCP), company-accounts (CA).

## 1. Panier courant

- `CompositeCartContext` (`S/Component/Order/Context/CompositeCartContext.php:34-36`) : priorité la plus haute d'abord, premier qui ne lève pas `CartNotFoundException` gagne. Tag `sylius.context.cart`, attribut `#[AsCartContext(priority: N)]`.
- Ordre réel : `-333` API (token) · **`-555` client connecté** (`CustomerAndChannelBasedCartContext` → dernier panier **non vide**, `createdByGuest = false`, tri `createdAt DESC`, `OrderRepository.php:270-289`) · **`-777` session** (`SessionAndChannelBasedCartContext`) · `-999` nouveau panier, décoré par `ShopBasedCartContext` (cache mémoire `:32`/`reset()` `:74`, devise de base `:57`, adresse par défaut du client en facturation `:87`, `setCustomerWithAuthorization` `:94`).
- Session : clé `_sylius.cart.<channelCode>` (`CartSessionStorage.php:52,64`), alias `CartStorageInterface` ; `SessionCartSubscriber` réécrit l'id en `kernel.response` (si session démarrée) ; vidé au logout (`ShopUserLogoutHandler.php:32`).
- **Piège** : pour un client connecté, -555 passe avant -777 → un panier PunchOut neuf/vide perd face à un ancien panier non vide.
- **Recommandation** : contexte dédié `#[AsCartContext(priority: 0)]`, actif uniquement en session PunchOut (clé de session propre au plugin), sinon `CartNotFoundException` ; appeler aussi `CartStorage::setForChannel`. Pattern de création : `QR/src/Checkout/QuoteCartManager.php:38-83`.

## 2. Connexion du ShopUser

- `UserLogin` / `sylius.security.user_login` **n'existent plus en 2.x**.
- Pattern 2.3 : `$security->login($user, 'form_login', '%sylius_shop.firewall_context_name%')` (= `shop`), cf. `UserRegistrationListener.php:77`. Ignorer la réponse du success handler, renvoyer sa propre redirection.
- `Security::login()` n'exécute que **`checkPreAuth`** ; en 2.3 `VerificationAwareEnabledUserChecker::checkPreAuth` retourne sans vérifier `enabled` si le canal exige la vérification et que `verifiedAt` est null (UPGRADE-2.3.md:5-12). **Le plugin doit vérifier lui-même `isEnabled()`, `verifiedAt`, client rattaché au canal.**
- `security.interactive_login` déclenche `ShopCartBlamerListener` (section shop, panier sans client), `UserCartRecalculationListener`, last login ; la session migre (attributs conservés) → **fixer le panier après `login()`**.
- **LoginLink inutilisable** : impossible d'ajouter `login_link` à un firewall par prepend (`disallowNewKeysInSubsequentConfigs`).
- Invité possible (session seule, `setCustomer()` sans user → `createdByGuest = true`) ; mais les prix par groupe (tier-pricing, groupe d'une entreprise) supposent un client sur le panier ; `/account` exige `ROLE_USER`.
- Astuce : `setCustomer()` sans authorization garde `createdByGuest = true` → le panier PunchOut n'est **jamais repris** par le contexte -555 ni ne devient le panier habituel du client.

## 3. Checkout : bouton et blocage

- Routes : `sylius_shop_cart_summary` (GET), **`sylius_shop_cart_checkout` (PATCH)** → `sylius_shop_checkout_start` ; `/checkout` : `_start`, `_address`, `_select_shipping`, `_select_payment`, `_complete` ; `/order` : `_thank_you`, `_pay`, `_after_pay`, `_show`.
- Bouton : hookable `checkout` dans `sylius_shop.cart.index.content.form.sections.general#right` (`cart/index.yaml:55-64`), **à l'intérieur du `<form>` PATCH du Live Component `sylius_shop:cart:form`** → pas de formulaire imbriqué.
- Offcanvas : `sylius_shop.base.offcanvas.cart` hookable `footer` avec un lien direct vers `sylius_shop_checkout_start` (`base.yaml:251-260`).
- twig-hooks : `condition: "@=…"` avec `is_granted()` et `user` (pas `service()`).
- **Recommandation** : voter `PUNCHOUT_SESSION` ; `condition: "@=not is_granted('PUNCHOUT_SESSION')"` sur `checkout` et sur le `footer` offcanvas ; bouton « Transférer » dans `sylius_shop.cart.index.content` (hors formulaire, comme QR) ou `<button formaction formmethod="post">`.
- Blocage : **listener `kernel.request` priorité 1-7** (après firewall 8 et routeur 32, avant `CheckoutResolver` 0) sur `sylius_shop_cart_checkout`, `sylius_shop_checkout_*`, `sylius_shop_order_*` → redirection vers le transfert. **Ne pas bloquer `address` par guard** (boucle de redirection, `CheckoutResolver.php:70-74`). Défense en profondeur : guard `workflow.sylius_order_checkout.guard.complete` + variante Doctrine `onFlush` pour winzou (patterns QR).
- Les paniers de session n'ont pas de `tokenValue` → l'API shop ne peut pas les atteindre.

## 4. Prix et totaux

- Montants entiers ×100. Ligne : `getUnitPrice()` (prix canal), `getDiscountedUnitPrice()`, `getFullDiscountedUnitPrice()` (+ part de promo commande), `getSubtotal()` (hors taxes ajoutées), `getTotal()` (promos + taxes non neutres), `getTaxTotal()`.
- Taxe incluse (`isIncludedInPrice`) → ajustement neutre, `unitPrice` TTC ; sinon HT + taxe dans `total`. Répartition par unité avec distributeur d'entiers → `total / quantity` pas toujours entier.
- Zone de taxe : adresse de facturation (ou livraison) sinon zone par défaut du canal ; en pratique l'adresse par défaut du client.
- Commande : `getItemsTotal()`, `getItemsSubtotal()`, `getTaxTotal()`, `getTaxExcludedTotal()`, `getShippingTotal()`, **`getOrderAndItemPromotionTotal()` (nouveau 2.3)**, `getTotal()`.
- **`getTotal()` contient un port estimé** (expédition créée avec la méthode par défaut même sans adresse) → message de retour basé sur les articles (`Total` cXML = hors taxes et port), choix configurable pour taxes/port.
- Devise = devise de base du canal. Toujours `process()` avant lecture. Processeurs : 70 préchargement (2.3), 60, 50 prix, 40 expédition, 30 frais, 20 promos, 10 taxes, 0 paiement.

## 5. Re-punchout (edit)

- `OrderModifier` fusionne mais `process()` à chaque ajout (O(N²)) ; `sylius.adder.cart_item` (2.3) : events `CART_ITEM_ADD` / `CART_ITEM_POST_ADD`, persist + flush.
- Pattern à reprendre : `QO/src/Adder/BulkCartAdder.php` (lookup groupé des variantes, contrôles canal/prix/stock/quantité, fusion `equals()`, **un seul `process()`**, diff avant/après, tout-ou-rien, un `flush`) ; adaptateur cœur + bascule optionnelle vers quick-order (pattern `MCP/src/Cart/CoreCartLinesAdder.php`).
- Paniers expirés après 2 jours (`sylius:remove-expired-carts`) → un edit doit pouvoir **reconstruire le panier depuis les `ItemOut`** (via `SupplierPartAuxiliaryID`).

## 6. Session, cookies, iframe

- Test-application : `cookie_samesite: lax` (défaut Symfony 7.4), `cookie_secure: auto`, pas d'option `partitioned`.
- `X-Frame-Options: sameorigin` sur toutes les réponses principales (`XFrameOptionsSubscriber.php:37`, priorité 0) ; aucune CSP.
- En iframe cross-site, le cookie `Lax` n'est pas envoyé → l'**application** doit configurer `cookie_samesite: none` + `cookie_secure: true` (à documenter ; un plugin ne peut pas le faire proprement par requête). Le plugin pose, en session PunchOut, un listener `kernel.response` (priorité négative) qui retire `X-Frame-Options` et ajoute `Content-Security-Policy: frame-ancestors <origines acheteur>`.
- Firewall shop : `context: shop`, `remember_me`, `logout.invalidate_session: false`.

## 7. Endpoint serveur-à-serveur

- `shop_regex` = `^/(?!admin|api/.*|api$|media/.*)[^/]++` → `/punchout/…` (sans locale) tombe dans le firewall shop, sans `access_control` (public), sans session ouverte tant qu'on n'y touche pas, sans CSRF automatique.
- Pattern MCP : routes sans locale, contrôleurs invokables, rate limiting, `Cache-Control: no-store`, corps brut via `$request->getContent()`.
- Pièges : **ne pas toucher la session dans l'endpoint de setup** (sinon cookie) ; `http_method_override: true` (un `_method` dans un corps form-encoded change la méthode) ; limiter la taille du corps ; `hash_equals`.
- Jeton de démarrage : pattern `QR/src/Token/TokenGenerator.php` (32 octets base64url) + recherche avec `hash_equals` ; usage unique, TTL court, consommé en GET puis redirection (attention aux pré-chargements / scanners de liens), `Referrer-Policy: no-referrer`.

## 8. company-accounts (optionnel)

- `CompanyMember` (customer, company, rôle admin/buyer/approver, enabled ; `isActive()`), un client ↔ une entreprise max (`findOneByCustomer`).
- Côté serveur-à-serveur : `tmonier_company_accounts.repository.company_member` ; après login : `CurrentMemberResolverInterface` ; voters `CompanyPermissions::*` ; le groupe client par défaut de l'entreprise influe sur les prix.
- Chargement conditionnel par `kernel.bundles` (pattern MCP). L'entité `Company` n'a aucun identifiant PunchOut → **le mapping identité acheteur → client/entreprise vit dans le plugin**.

## Points d'extension recommandés

1. `#[AsCartContext(priority: 0)]` limité à la session PunchOut + `CartStorageInterface::setForChannel`.
2. `Security::login(…, 'form_login', '%sylius_shop.firewall_context_name%')` après contrôles manuels (enabled, verifiedAt, canal).
3. Voter `PUNCHOUT_SESSION` + `condition:` twig-hooks (bouton checkout, footer offcanvas) + bouton « Transférer ».
4. Listener `kernel.request` (priorité 1-7) sur les routes checkout/order + guard `complete` (+ Doctrine pour winzou).
5. Listener `kernel.response` : `X-Frame-Options` / CSP `frame-ancestors`.
6. Ajout groupé avec un seul `process()` (adaptateur cœur, bascule quick-order si présent).
7. Événements `sylius.cart_post_change` / `CART_ITEM_POST_ADD` (2.3) pour suivre le panier.
8. Services optionnels selon `kernel.bundles` (company-accounts, quick-order, quote-request `quote_cart_checker`).

## Différences 2.2 / 2.3 à surveiller

User checkers du shop remplacés (pré-auth permissif) · `getOrderAndItemPromotionTotal()` · `CatalogPricesCalculatorInterface` (prix catalogue vs panier) · processeur de préchargement 70 · `AddToCartFormComponent::addToCart()` → `?Response` · Symfony UX `^2.36 || ^3.0` · `sylius.adder.cart_item`. Le CI couvrant 2.2 et 2.3, chaque hook utilisé doit exister dans les deux.
