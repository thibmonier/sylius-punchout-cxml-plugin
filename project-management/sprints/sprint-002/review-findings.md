# Sprint 2 — revue de sécurité indépendante

> 2026-10-14 · commits `95b24f7`, `b93cacc`, `d730c29`, `539f448` · aucun constat critique. Chaque correction est couverte par un test écrit d'abord.

| # | Gravité | Constat | Suite donnée | Test |
|---|---|---|---|---|
| M1 | Majeur | La limite par adresse IP se contourne avec plusieurs adresses ou un `X-Forwarded-For` forgé, et bloque tout le monde derrière un proxy non déclaré. | Ajout d'une limite des échecs d'authentification par identité acheteur, quelle que soit l'adresse (aucun calcul Argon2id au-delà). `trusted_proxies`, `trusted_headers` et le stockage partagé du limiteur sont documentés dans le README. | `CxmlProtectionTest::testRepeatedAuthenticationFailuresOfAnIdentityAreLimitedWhateverTheAddress` |
| M2 | Majeur | Une fois la session PunchOut terminée, le navigateur reste connecté au compte client partagé de l'acheteur, et le checkout redevient accessible. | Déconnexion au transfert et à l'annulation. À l'expiration, ou si l'acheteur est désactivé, la requête suivante passe par la déconnexion de la boutique. | `PunchoutHardeningTest::testTheShopperIsLoggedOutWhenThePunchoutSession{Ends,Expires}`, `testDisablingTheBuyerEndsItsOpenSessions` |
| m1 | Mineur | 1 ou 2 calculs Argon2id selon l'acheteur, donc un oracle de temps sur les identités. | Accepté : les identités cXML ne sont pas secrètes, et la limite des échecs borne le coût. | — |
| m2 | Mineur | L'horodatage accepte des formes relatives (`now`). | Corrigé : ISO 8601 absolu avec fuseau horaire obligatoire. | `testATimestampWithoutAnAbsoluteDateIsRefused` |
| m3 | Mineur | Aucune purge des `payloadID` expirés. | Corrigé : purge à chaque vérification (colonne indexée). | — |
| m4 | Mineur | Un doublon concurrent ferme l'EntityManager. | Corrigé : insertion DBAL, sans `flush()`. | `testAReplayedSetupIsRefused` |
| m5 | Mineur | `x` couvrait aussi les sous-domaines ; un suffixe public est acceptable. | Corrigé : `x` désigne exactement cet hôte, `*.x` ses sous-domaines. Les suffixes publics sont signalés dans la documentation, sans dépendance PSL. | `testOnlyAReturnUrlOfAnAllowedDomainOpensASession` |
| m6 | Mineur | Le validateur accepte un acheteur nul. | Non exploitable : `TransferAction` passe par `getCurrent()`, qui garantit l'acheteur. Conservé. | — |
| m7 | Mineur | La CSP est construite sans revalider les origines stockées. | Corrigé : origines filtrées en sortie. | — |
| m8 | Mineur | La session est lue sur chaque réponse. | Corrigé : aucune lecture sans session existante. | suite fonctionnelle |
| m9 | Mineur | Deux générations simultanées peuvent laisser trois secrets actifs. | Accepté, risque faible (action admin) ; corrigé à la génération suivante. | — |
| m10 | Mineur | Routes des secrets sans permission explicite. | Les routes sont sous le pare-feu admin, et le cœur Sylius n'a pas de RBAC fin (fonction Plus). | — |
| m11 | Mineur | Politique du secret imposé trop faible. | Corrigé : 16 à 256 caractères sans les espaces de bord, et pas un seul caractère répété. | `testASecretImposedByTheProcurementSystemCanBeEntered` |
| m12 | Mineur | `SameSite=None` s'applique à toute la session. | Compromis documenté : protection par les jetons CSRF uniquement, avec une option pour isoler l'admin. | — |

Également corrigé pendant le sprint : la compatibilité CI. La clé `condition` des hooks Twig est inconnue de Sylius 2.2 ; elle est remplacée par des templates qui encapsulent ceux de Sylius. Le paramètre `Vote` est désormais exigé par Symfony 8.
