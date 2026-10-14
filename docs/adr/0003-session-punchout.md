# ADR-0003 — Session PunchOut : jeton de démarrage, client rattaché, panier dédié

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Après le setup, le navigateur de l'acheteuse doit arriver connecté au client Sylius rattaché (prix contractuels), dans un panier isolé de son panier habituel. Sylius 2.3 n'a plus `UserLogin` ; `Security::login()` n'exécute que la pré-authentification, permissive en 2.3 pour les comptes non vérifiés ; le contexte panier du client connecté (priorité -555) choisit le dernier panier non vide ; `login_link` ne peut pas être ajouté au firewall par un plugin.

## Décision

Jeton de démarrage de 256 bits dans `StartPage`, stocké en SHA-256, usage unique (consommation atomique `UPDATE … WHERE status = 'pending'`), TTL 5 min, `HEAD` / prefetch ignorés. À la consommation : contrôles explicites (utilisateur activé, vérifié si le canal l'exige, canal actif), `Security::login($user, 'form_login', 'shop')`, régénération de l'id de session, clé de session `_tmonier_punchout.session`. Panier créé avec `setCustomer()` sans autorisation (`createdByGuest = true`, jamais repris par le contexte -555) et servi par `PunchoutCartContext` en priorité 0, actif seulement pour une session `active` du client connecté. OCI : même démarrage, sans jeton, dans la requête d'entrée.

## Alternatives considérées

- Cookie posé par l'endpoint de setup : impossible (appel serveur à serveur).
- `login_link` Symfony : impossible par prepend.
- Session invitée sans compte : écarté par arbitrage (client rattaché obligatoire, prix et groupes).

## Conséquences

- Le panier habituel du client n'est jamais touché ; la session PunchOut est close après transfert.
- Le client reste connecté au shop après transfert (pas de déconnexion forcée).
- Dépendance à la clé de session : en iframe, le cookie doit être `SameSite=None; Secure` (ADR-006).
