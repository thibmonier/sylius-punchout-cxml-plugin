# Brief builder — sprint 1 (walking skeleton)

> 2026-10-14 · Plugin `tmonier/sylius-punchout-cxml-plugin` · répertoire `/Users/tmonier/Projects/sylius_plugins/sylius-punchout-cxml-plugin`
> Réponds et documente en français (code, identifiants, messages de commit, README en anglais comme les autres plugins).

## Mission

Construire le **squelette du plugin** puis livrer en **TDD** les stories du sprint 1 :
US-001 (créer un acheteur), US-007 (setup cXML create), US-013 (démarrage de session), US-018 (retour cXML), US-026 (simulateur). Objectif : aller-retour cXML `create` complet démontrable dans le simulateur, CI-ready.

## Sources de vérité (à lire d'abord)

- `project-management/tech-spec.md` (conception validée) et `architecture/{c4,erd,api,security}.md`
- `docs/adr/0001` à `0007` (ADR-0002 : **DOM maison**, aucune bibliothèque cXML ; ADR-0003 : session ; ADR-0004 : Argon2id ; ADR-0005 : prix HT)
- `project-management/backlog/user-stories/US-001,007,013,018,026.md` (critères Gherkin = tests d'acceptation)
- `project-management/sprints/sprint-001/sprint-goal.md`
- `project-management/analysis/A-cxml-protocol-reference.md` et `D-sylius-integration-points.md` (pièges Sylius 2.3 : contexte panier priorité 0, `Security::login` pré-auth permissif, X-Frame-Options, endpoint sans session…)

Périmètre sprint 1 (voir sprint-goal) : authentification par secret simple (une empreinte Argon2id, pas de rotation / anti-rejeu / liste blanche avancée — mais **refuser au minimum les URL de retour non HTTPS** et garder les interfaces prêtes), ouverture en fenêtre (iframe au sprint 2), pas de blocage checkout (sprint 2). Ne pas déborder sur les stories des sprints suivants, sauf ce qui est nécessaire au fonctionnement (ex. entités `BuyerIdentity`, `BuyerCredential` minimales).

## Squelette

Plugin modèle : `../sylius-quote-request-plugin` (CI, compose, Makefile, ecs.php, phpstan.neon, rector.php, phpunit.xml.dist, `tests/TestApplication`, assets, `.gitattributes`, `.gitignore`, `composer.json`). Reprendre sa structure et ses conventions (voir son `CLAUDE.md`), en remplaçant noms, namespace (`Tmonier\SyliusPunchoutCxmlPlugin\`), bundle `TmonierSyliusPunchoutCxmlPlugin`, préfixes `tmonier_punchout`.
- `composer.json` : `php ^8.2`, `sylius/sylius ^2.2.9 || ^2.3`, `symfony/rate-limiter` + `symfony/lock` (patron quote-request), `ext-dom`, `ext-libxml`. **Pas** de jms/serializer ni de lib cXML. Mêmes require-dev que quote-request (sans knp-snappy, winzou seulement si nécessaire).
- CI : copier `.github/workflows/build.yaml` de quote-request (matrice identique).
- Ports locaux réservés : MySQL **3334**, MailHog **8053/1053**, serveur de dev **8101** ; bases `tmonier_sylius_punchout_cxml_dev` / `_test`.
- **Disque presque plein** (≈ 4 Gio libres) : cloner le vendor en copy-on-write APFS au lieu d'un install complet : `cp -cR ../sylius-quote-request-plugin/vendor ./vendor` puis `composer update`/`install` limité aux différences (les dépendances sont quasi identiques) ; ne pas lancer d'autres installations lourdes ; surveiller `/bin/df -h /System/Volumes/Data` ; purger `var/cache` si besoin.
- Commiter les assets du squelette `assets/{admin,shop}/{entrypoint.js,controllers.json}`.
- DTD : `tests/Resources/dtd/1.2.071/cXML.dtd` est déjà en place (licence cXML.org : garder l'en-tête, mentionner dans le README que les DTD de test viennent de cxml.org).
- `CLAUDE.md` du plugin (format des autres plugins), `README.md` (EN), `SPEC.md` court qui renvoie à `project-management/`, `LICENSE` MIT, `CHANGELOG.md`.

## Exigences techniques (rappel des points critiques)

1. **`SafeXmlLoader`** : refuser tout DOCTYPE avec sous-ensemble interne (`[` dans le DOCTYPE / `<!ENTITY`) **avant** chargement ; `DOMDocument::loadXML($xml, LIBXML_NONET)` sans `LIBXML_NOENT`/`DTDLOAD`/`DTDVALID`/`PARSEHUGE` ; taille max ; erreurs libxml capturées et restaurées dans un `finally`. Tests : XXE `file://` et `http://`, entité paramètre, billion laughs réduit, document trop gros, DOCTYPE cXML externe standard accepté.
2. Endpoint `/punchout/cxml` : POST, **ne jamais toucher la session** (test : aucun `Set-Cookie`), `Cache-Control: no-store`, réponses cXML avec `Status` (200/401/406/450…), `401` générique, vérification factice si acheteur inconnu, rate limiter par IP (patron quote-request).
3. Écrivains DOM : `PunchOutSetupResponse`, `PunchOutOrderMessage` avec DOCTYPE `cXML 1.2.071` SYSTEM ; `payloadID` unique ; `timestamp` ISO 8601 avec décalage (jamais `Z`). **Chaque document émis est validé contre `tests/Resources/dtd/1.2.071/cXML.dtd` dans les tests** (charger le document en remplaçant l'identifiant système par le chemin local — dans les tests uniquement).
4. Démarrage : jeton 32 octets base64url, stocké en SHA-256, TTL 5 min, usage unique (consommation atomique), `HEAD`/prefetch ne consomment pas ; contrôles `enabled` / `verifiedAt` (si le canal exige la vérification) / canal ; `Security::login($user, 'form_login', '%sylius_shop.firewall_context_name%')` ; régénération de l'id de session ; panier via `setCustomer()` (createdByGuest = true) servi par `PunchoutCartContext` `#[AsCartContext(priority: 0)]` uniquement en session PunchOut active ; `CartStorageInterface::setForChannel` ; `Referrer-Policy: no-referrer`.
5. Transfert : POST + CSRF, `process()` du panier, prix unitaire HT (`getFullDiscountedUnitPrice()`, taxe incluse retirée si canal TTC), `Total` hors taxes et port, `cXML-base64`, formulaire auto-soumis `target="_top"` + bouton de secours, URL de retour revalidée (HTTPS), journalisation minimale, session close (`transferred`), panier détaché. Bouton « Transférer » + bandeau minimal via twig-hooks (hors du `<form>` du Live Component panier) — le blocage du checkout complet est au sprint 2.
6. Admin (US-001) : ressource `tmonier_punchout.buyer` + grid + formulaire (client **obligatoire**, canal, protocole, identités From/Sender, secret généré affiché une seule fois, domaines de retour), menu admin, traductions **en, fr, de, es, it, nl, pl** (test de complétude comme les autres plugins). Migrations Schema API (MySQL, MariaDB, PostgreSQL), tables du plugin uniquement.
7. Simulateur (US-026) : **dans `tests/TestApplication` uniquement** (routes + contrôleur + templates de test), formulaire d'envoi d'un setup cXML vers l'endpoint, page de réception qui décode `cXML-base64` et affiche le message + résultat de validation DTD. Fixture de démo : un acheteur rattaché à un client de démo ; **le secret est généré au chargement de la fixture** (jamais en dur) et affiché/consigné pour le simulateur.
8. Tests : unit + integration + functional ; les tests functional créent leurs propres données dans la transaction (fixtures Sylius aléatoires : ne jamais dépendre d'une méthode de livraison/paiement présélectionnée) ; **aucun secret ni couple identifiant/mot de passe réaliste en dur** (GitGuardian, push protection) — générer à l'exécution.
9. Qualité : ECS, PHPStan **level max** (src + tests), Rector (`make rector`), `lint:container`, `composer validate --strict`. Compatibilité Sylius 2.2 et 2.3, PHP 8.2-8.4, Symfony 6.4/7.4/8 (éviter les API propres à 2.3 sans repli). Docker : `ghcr.io/sylius/sylius-php:8.4-alpine` pour PHPStan/ECS/tests si PHP hôte insuffisant (l'image locale n'a pas pcntl : ne pas laisser ECS retirer le backslash de `\SIGTERM` si utilisé).

## Livraison

- Dépôt git local initialisé sur `main`, commits conventionnels **par story** (+ un commit squelette), terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Ne pas** créer de dépôt GitHub, ne pas pousser, ne pas créer de release : le coordinateur s'en charge.
- Committer aussi `project-management/` et `docs/adr/` (déjà présents).
- Rapport final : commits, résultats (unit / non-unit / PHPStan / ECS / Rector / lint), écarts avec la conception et décisions prises, points ouverts, état du disque, commande de démo (serveur :8101, comptes, URL du simulateur).
