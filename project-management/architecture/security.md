# Conception de la sécurité

> Phase 3 (Conception) · 2026-10-14 · référentiel : OWASP Top 10:2025, règles projet `11-security.md`.

## Modèle de menaces

| # | Menace | Actif | Contre-mesure | Test |
|---|---|---|---|---|
| M1 | XXE / lecture de fichier, SSRF via DTD | serveur | `SafeXmlLoader` : refus du DOCTYPE avec sous-ensemble interne **avant** parse, `LIBXML_NONET`, ni `NOENT`, ni `DTDLOAD`, ni `DTDVALID` ; DOCTYPE externe toléré mais jamais résolu | XXE `file://`, `http://`, entité paramètre |
| M2 | Bombe XML (billion laughs, quadratic blowup) | disponibilité | même refus du sous-ensemble interne, taille ≤ 256 Kio, pas de `LIBXML_PARSEHUGE`, profondeur bornée | billion laughs réduit, chaîne de 1 Mio |
| M3 | Usurpation d'acheteur | prix, comptes clients | identités `From` + `Sender` + secret, Argon2id, vérification factice si acheteur inconnu, `401` générique, limitation par IP | secret faux, révoqué, identité d'un autre acheteur |
| M4 | Rejeu d'un setup capturé | sessions | `payloadID` unique par acheteur (contrainte DB) + fenêtre de 15 min | rejeu, horodatage ancien / futur |
| M5 | Exfiltration du panier vers une URL tierce | données commerciales | liste blanche HTTPS par acheteur, refus des IP littérales privées / réservées / loopback, revalidation à l'envoi | URL hors liste, `http://`, `https://10.0.0.5`, `https://ariba.com.attacker.example` |
| M6 | Vol / réutilisation du lien de démarrage | compte client | jeton 256 bits, usage unique (consommation atomique), TTL 5 min, empreinte SHA-256 en base, `Referrer-Policy: no-referrer`, `HEAD` / prefetch ignorés | jeton réutilisé, expiré, deux requêtes simultanées |
| M7 | Connexion d'un compte désactivé / non vérifié | comptes | contrôles explicites avant `Security::login()` (pré-auth 2.3 permissif) | compte désactivé, non vérifié |
| M8 | Fixation de session | compte client | régénération de l'id au démarrage, déconnexion d'un autre client connecté | id avant / après |
| M9 | Clickjacking hors PunchOut | boutique | `frame-ancestors` levé **seulement** en session PunchOut et seulement pour les origines de l'acheteur | en-têtes hors session |
| M10 | CSRF sur le transfert / l'annulation | session | jetons CSRF Symfony | POST sans jeton |
| M11 | Commande passée hors circuit d'achat | métier | blocage des routes checkout + guard `complete` | accès direct, transition forcée |
| M12 | Fuite de secrets / données personnelles dans les journaux | secrets, RGPD | masquage systématique, corps non stocké par défaut, rétention 90 jours, effacement par client | assertion « SharedSecret absent » |
| M13 | Énumération / saturation de l'endpoint | disponibilité | rate limiter par IP (setup, OCI), limite de taille, temps de réponse homogène | rafale → 429 |
| M14 | Override de méthode HTTP | routage | endpoint POST brut, `_method` ignoré (corps XML) | |
| M15 | Injection dans la page de retour | navigateur | valeurs échappées (Twig autoescape), base64 pour cXML, script auto-submit avec nonce, aucune donnée en `|raw` | description avec `"><script>` |

## Secrets

- Générés par `random_bytes(32)` (base64url, 43 caractères), affichés une seule fois. Saisie d'un secret imposé par l'outil d'achat : longueur minimale 16, même stockage (point ouvert 3 de la tech spec).
- Empreinte Argon2id avec les paramètres de la règle projet (128 Mio, t = 3, p = 1), via `password_hash(PASSWORD_ARGON2ID)` / `password_verify` (ADR-004).
- Rotation : deux secrets actifs au plus, révocation explicite, `last_used_at` affiché.
- Mot de passe OCI : mêmes règles.
- Aucune valeur réaliste en dur dans les tests (GitGuardian) : génération à l'exécution.

## En-têtes

| Contexte | En-têtes |
|---|---|
| Endpoint cXML | `Content-Type: text/xml; charset=UTF-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, aucun `Set-Cookie` |
| Démarrage | `Referrer-Policy: no-referrer`, `Cache-Control: no-store` |
| Session PunchOut | pas de `X-Frame-Options`, `Content-Security-Policy: frame-ancestors 'self' <origines>` |
| Page de transfert | `Cache-Control: no-store`, CSP avec nonce pour le script d'auto-soumission, `form-action` limité à l'origine de l'URL de retour |

## Prérequis côté application (documentés, vérifiés par `doctor`)

- HTTPS et `trusted_proxies` corrects (les URL `StartPage` doivent être en `https`).
- Iframe cross-site : `framework.session.cookie_samesite: none` et `cookie_secure: true`. Sans cela, la session se perd dans l'iframe d'Ariba.

## Journalisation des événements de sécurité

Échecs d'authentification, rejeux, URL refusées, jetons invalides et dépassements de débit sont journalisés (`MessageLog` + logger PSR, canal `punchout`, niveau `warning`), sans secret ni corps non masqué.
