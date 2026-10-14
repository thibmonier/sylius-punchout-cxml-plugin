# Sprint 4 — revue de sécurité indépendante

Périmètre : commits `5e4ce16..6038c3b` (OCI US-003/012/020, purge et effacement US-025, doctor US-028,
company-accounts US-006). Revue en lecture seule par un agent `security-auditor`, constats vérifiés
dans le code avant correction.

| # | Gravité | Constat | Suite |
|---|---------|---------|-------|
| M1 | Majeur | Entrée OCI acceptée en GET : mot de passe dans les access logs, l'historique, les APM ; URL rejouable | Corrigé : POST seul par défaut (405 en GET), option `oci_allow_get`, `Cache-Control: no-store` sur la redirection de démarrage |
| M2 | Majeur | Budget d'échecs par identité (toutes adresses) vérifié avant l'authentification : n'importe qui connaissant le code ou l'identité bloque l'acheteur (DoS) | Corrigé (cXML et OCI) : `AuthenticationThrottle` ne bloque d'avance que par identité + adresse ; le budget par identité ne transforme que les échecs suivants en 429, de bons identifiants passent |
| m1 | Mineur | Login CSRF : un acheteur peut ouvrir sa session PunchOut dans le navigateur d'un tiers | Accepté, documenté : inhérent au PunchOut (même principe que l'URL de démarrage cXML) ; checkout bloqué, l'attaquant expose ses propres identifiants |
| m2 | Mineur | Les 429 ne laissent aucune trace | Corrigé : avertissements PSR (`PunchOut authentication blocked`, échecs d'une identité depuis plusieurs adresses) |
| m3 | Mineur | Appartenance à l'entreprise vérifiée seulement à l'enregistrement du formulaire | Corrigé : vérifiée par `ShopperAuthenticator` à chaque ouverture de session (403) |
| i1 | Info | Seul `PASSWORD` masqué dans le journal ; query de HOOK_URL conservée | Corrigé : toute clé `pass|pwd|secret|token` masquée, query de HOOK_URL retirée |
| i2 | Info | Plafond des champs `~` mal compté | Corrigé : 20 champs `~` au plus |
| i3 | Info | Le résolveur cXML ne filtrait pas le protocole : un acheteur OCI avec identités pouvait s'authentifier en cXML | Confirmé et corrigé : seul un acheteur `cxml` s'authentifie sur `/punchout/cxml` |

Ajout hors revue : l'effacement RGPD laisse une trace d'audit (notice PSR avec le SHA-256 de l'adresse).

Tests ajoutés : GET → 405 ; budget d'identité épuisé + bons identifiants → 303 (OCI) / 200 (cXML) et
mauvais → 429 ; acheteur OCI sur l'endpoint cXML → 401 ; membre d'entreprise désactivé → 403.
