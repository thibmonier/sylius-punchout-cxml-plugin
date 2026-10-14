# Contraintes — tmonier/sylius-punchout-cxml-plugin

> Phase 1 (Analyse) · 2026-10-14

## Techniques

- **Cible** : Sylius `^2.2.9 || ^2.3`, PHP `^8.2` (2.3 ⇒ 8.3), Symfony 6.4 / 7.4 / 8 ; CI matrice habituelle (MySQL 8.4, MariaDB 11.4, PostgreSQL 17 ; exclusions 2.2+Sf8 et 2.3+PHP 8.2). Chaque hook utilisé doit exister en 2.2 **et** 2.3.
- **Aucune modification d'entité du core** : tables du plugin uniquement, migrations via la Schema API (3 SGBD).
- **Aucune modification de `security.yaml` exigée du plugin** : impossible d'ajouter un firewall ou `login_link` par prepend → connexion via `Security::login()` + contrôles manuels (enabled, verifiedAt, canal).
- **Iframe** : la configuration du cookie de session (`SameSite=None; Secure`) relève de **l'application** (documentation + éventuellement une commande de diagnostic) ; le plugin gère `X-Frame-Options` / `frame-ancestors` par réponse.
- **Endpoint de setup serveur-à-serveur** : hors locale, sans session ni cookie, corps brut limité en taille, réponse `text/xml` sans BOM ni espace initial.
- **XML non fiable** : jamais `LIBXML_NOENT` / `LIBXML_DTDLOAD` / `LIBXML_DTDVALID` sur l'entrée ; validation DTD uniquement contre des DTD locales et après parsing sûr.
- **Conformité cXML 1.2.071** : `timestamp` avec décalage (pas de `Z`), `payloadID` unique, DOCTYPE vers cxml.org, champs obligatoires de l'`ItemIn`, `SharedSecret` jamais exposé au navigateur.
- **Panier PunchOut isolé** du panier habituel du client (contexte dédié, `createdByGuest = true`) et inaccessible au checkout Sylius.
- **Montants** : entiers ×100 côté Sylius, décimaux à point côté cXML / OCI ; `Total` cXML hors taxes et port.
- **Socle qualité claude-craft** : ECS, PHPStan level max, Rector, PHPUnit unit + functional/integration, `prepend()` ne traitant que les nœuds nécessaires, traductions dans 7 langues si `TranslationsTest`.

## Business

- OSS **MIT**, paquet `tmonier/sylius-punchout-cxml-plugin`, dépôt `thibmonier`. Publié en 0.x / 1.0 puis politique de compatibilité v1.x (pas de BC break en mineure).
- Un mainteneur ; périmètre v1 resserré, extensible.
- **Communication** : positionnement « complémentaire de Sylius Plus » établi (Plus n'a pas de PunchOut) → présenter le plugin comme une **intégration e-procurement**, jamais comme une alternative à Plus.
- Pas de dépendance à une passerelle SaaS ; compatible avec elles.

## Réglementaires

- **RGPD** : les `Extrinsic` / `Contact` portent des données personnelles (e-mail, nom, centre de coût) → minimisation, masquage dans les journaux, durée de conservation configurable des sessions et messages, purge (commande), effacement par client.
- **Secrets** : SharedSecret par acheteur jamais en clair dans le code ni les logs ; stockage haché (Argon2id, règle sécurité du projet) puisque seule la vérification est nécessaire.
- **Sécurité** (OWASP 2025) : contrôle d'accès (identité acheteur, liste blanche des URL de retour), injection XML (XXE), rejeu, fixation de session, fuite d'informations (erreurs génériques), journalisation des événements de sécurité.

## Ressources / test

- **Aucun compte Ariba / Coupa** : tests via fixtures XML conformes au guide, validation DTD locale (`xmllint` / DOM), et un **simulateur d'acheteur** dans l'application de test (page de réception du formulaire de retour).
- Tests sans fixtures aléatoires (créer les données dans la transaction de test), sans identifiant ni secret réaliste en dur (GitGuardian).
- Ports locaux réservés : MySQL 3334, MailHog 8053/1053, serveur 8101.
