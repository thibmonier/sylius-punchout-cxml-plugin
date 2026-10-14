# Sprint 1 — Walking skeleton

> 34 points · objectif : un aller-retour cXML `create` complet de bout en bout, démontrable dans le simulateur.

## Objectif de sprint

Un acheteur configuré dans l'admin envoie un setup depuis le simulateur, l'acheteuse arrive connectée dans un panier PunchOut, ajoute des articles et les transfère ; le simulateur affiche un PunchOutOrderMessage valide contre la DTD.

## Stories

- [US-001](../../backlog/user-stories/US-001.md) Créer un acheteur PunchOut (5 pts)
- [US-007](../../backlog/user-stories/US-007.md) Recevoir un PunchOutSetupRequest (create) (8 pts)
- [US-013](../../backlog/user-stories/US-013.md) Démarrer la session boutique (8 pts)
- [US-018](../../backlog/user-stories/US-018.md) Renvoyer le panier en cXML (8 pts)
- [US-026](../../backlog/user-stories/US-026.md) Simulateur d'acheteur (5 pts)

## Hors sprint 1 (volontairement)

Rotation des secrets, anti-rejeu, liste blanche, iframe, blocage du checkout, journal : sprint 2 et suivants. Le sprint 1 utilise une authentification par secret simple et l'ouverture en fenêtre.
