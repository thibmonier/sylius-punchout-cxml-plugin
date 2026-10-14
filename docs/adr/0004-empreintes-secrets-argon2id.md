# ADR-0004 — Empreintes des secrets acheteurs en Argon2id

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Le secret partagé cXML et le mot de passe OCI ne servent qu'à vérifier l'acheteur. Ils peuvent être imposés par l'outil d'achat (faible entropie possible). La règle projet impose Argon2id (128 Mio, t = 3, p = 1). Le PRD fixe un setup < 300 ms (NFR-6).

## Décision

Stockage `password_hash(PASSWORD_ARGON2ID, memory_cost 131072, time_cost 3, threads 1)`, vérification `password_verify`. L'acheteur est d'abord identifié par ses identités `From` / `Sender` (cXML) ou son code (OCI) : seules ses empreintes actives (au plus 2) sont vérifiées. Pour un acheteur inconnu, une vérification factice contre une empreinte fixe homogénéise le temps de réponse. Le budget NFR-6 s'entend **hors vérification du secret** (environ 100 à 250 ms par empreinte) ; l'ordre de vérification met le secret le plus récemment utilisé en premier.

## Alternatives considérées

- HMAC-SHA256 avec la clé de l'application : rapide, adapté aux secrets générés, mais non conforme à la règle projet et faible si le secret est imposé et court.
- Secret chiffré (réversible) : inutile, la valeur n'est jamais relue.

## Conséquences

- Conforme à la règle projet, robuste même pour un secret imposé.
- Coût CPU par setup : la limitation de débit par IP borne l'exposition au déni de service.
- NFR-6 à reformuler dans le PRD (« hors vérification du secret »).
