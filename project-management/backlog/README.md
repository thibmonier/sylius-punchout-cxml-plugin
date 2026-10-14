# Backlog — tmonier/sylius-punchout-cxml-plugin

> Phase 2 (Planification) · 2026-10-14 · estimations en points (Fibonacci), stories ≤ 8 points

- **v1** : 28 stories, **111 points** — Must 102 · Should 6 · Could 3
- **v1.1** : 3 stories, 16 points

## Epics

| Epic | Titre | Version | Stories | Points |
|---|---|---|---|---|
| [EPIC-01](epics/EPIC-01.md) | Configuration des acheteurs PunchOut | v1 | 6 | 24 |
| [EPIC-02](epics/EPIC-02.md) | Setup cXML | v1 | 4 | 16 |
| [EPIC-03](epics/EPIC-03.md) | Setup OCI | v1 | 1 | 5 |
| [EPIC-04](epics/EPIC-04.md) | Session boutique PunchOut | v1 | 5 | 19 |
| [EPIC-05](epics/EPIC-05.md) | Retour du panier | v1 | 4 | 18 |
| [EPIC-06](epics/EPIC-06.md) | Re-punchout (edit / inspect) | v1 | 2 | 8 |
| [EPIC-07](epics/EPIC-07.md) | Exploitation, conformité et outillage | v1 | 6 | 21 |
| [EPIC-08](epics/EPIC-08.md) | Commande définitive et OCI avancé | v1.1 | 3 | 16 |

## Plan de sprints (proposition)

### Sprint 1 — Walking skeleton — 34 points

- [US-001](user-stories/US-001.md) Créer un acheteur PunchOut (Must, 5 pts)
- [US-007](user-stories/US-007.md) Recevoir un PunchOutSetupRequest (create) (Must, 8 pts)
- [US-013](user-stories/US-013.md) Démarrer la session boutique (Must, 8 pts)
- [US-018](user-stories/US-018.md) Renvoyer le panier en cXML (Must, 8 pts)
- [US-026](user-stories/US-026.md) Simulateur d'acheteur (Must, 5 pts)

### Sprint 2 — Sécurité, session et iframe — 25 points

- [US-002](user-stories/US-002.md) Identités cXML et secret partagé (Must, 5 pts)
- [US-004](user-stories/US-004.md) Domaines autorisés (Must, 3 pts)
- [US-008](user-stories/US-008.md) Anti-rejeu et limitation de débit (Must, 3 pts)
- [US-009](user-stories/US-009.md) Parsing sûr et statuts d'erreur cXML (Must, 3 pts)
- [US-014](user-stories/US-014.md) Bandeau et boutons de transfert (Must, 3 pts)
- [US-015](user-stories/US-015.md) Bloquer le checkout pendant la session (Must, 3 pts)
- [US-016](user-stories/US-016.md) Fonctionnement en iframe (Must, 3 pts)
- [US-017](user-stories/US-017.md) Clore la session (Must, 2 pts)

### Sprint 3 — Re-punchout, conformité, journal — 25 points

- [US-005](user-stories/US-005.md) Classification UNSPSC et unités (Must, 5 pts)
- [US-010](user-stories/US-010.md) ProfileRequest (Must, 2 pts)
- [US-019](user-stories/US-019.md) Taxes et frais de port optionnels (Should, 3 pts)
- [US-021](user-stories/US-021.md) Annuler la session (Must, 2 pts)
- [US-022](user-stories/US-022.md) Modifier une réquisition (edit) (Must, 5 pts)
- [US-023](user-stories/US-023.md) Consulter une réquisition (inspect) (Must, 3 pts)
- [US-024](user-stories/US-024.md) Journal des échanges (Must, 5 pts)

### Sprint 4 — OCI, RGPD, outillage, docs — 27 points

- [US-003](user-stories/US-003.md) Identifiants OCI (Must, 3 pts)
- [US-012](user-stories/US-012.md) Entrée OCI (Must, 5 pts)
- [US-020](user-stories/US-020.md) Renvoyer le panier en OCI (Must, 5 pts)
- [US-006](user-stories/US-006.md) Rattachement à une entreprise (company-accounts) (Could, 3 pts)
- [US-025](user-stories/US-025.md) Rétention, purge et effacement RGPD (Must, 3 pts)
- [US-027](user-stories/US-027.md) Conformité DTD des documents émis (Must, 2 pts)
- [US-028](user-stories/US-028.md) Commande de diagnostic (Should, 3 pts)
- [US-029](user-stories/US-029.md) Documentation (Must, 3 pts)

### v1.1 — 16 points

- [US-030](user-stories/US-030.md) Recevoir un OrderRequest (Must, 8 pts)
- [US-031](user-stories/US-031.md) OCI VALIDATE (Should, 5 pts)
- [US-032](user-stories/US-032.md) OCI DETAIL (Could, 3 pts)

### Après v1.1

- [US-033](user-stories/US-033.md) Secure OCI (Could, 5 pts)

## Couverture des exigences

Chaque exigence FR-1 à FR-22 du PRD est couverte par au moins une story v1 ; FR-23 et FR-24 par les stories v1.1 (EPIC-08).
Le numéro US-011 est volontairement vacant (le refus de `operation="source"` est intégré à US-009).
