# ADR-0006 — Iframe : en-têtes par session, cookie à la charge de l'application

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Ariba et d'autres ouvrent le PunchOut en iframe. Sylius pose `X-Frame-Options: sameorigin` partout et le cookie de session est `SameSite=Lax` par défaut, donc non envoyé en iframe cross-site. Un plugin ne peut pas changer proprement la configuration du cookie de session de l'application requête par requête.

## Décision

En session PunchOut uniquement, `FrameHeadersListener` (`kernel.response`, priorité -16) retire `X-Frame-Options` et pose `Content-Security-Policy: frame-ancestors 'self' <origines de l'acheteur>`. La configuration du cookie (`cookie_samesite: none`, `cookie_secure: true`) est documentée et contrôlée par `tmonier:punchout:doctor`. Le cookie partitionné (CHIPS) est noté comme évolution.

## Alternatives considérées

- Modifier le cookie de session par listener : fragile (cookie déjà émis, autres sessions de l'application).

## Conséquences

- Protection anti-clickjacking conservée hors PunchOut.
- Prérequis d'installation supplémentaire, rendu visible par `doctor`.
