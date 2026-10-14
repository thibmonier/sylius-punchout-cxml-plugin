# Sprint 3 — revue de sécurité indépendante

> 2026-10-15 · commits `c5f623d` à `adc2b04` · aucun constat critique. Chaque correction est couverte par un test écrit d'abord.

| # | Gravité | Constat | Suite donnée | Test |
|---|---|---|---|---|
| M1 | Majeur | Le journal stocke le corps de toute requête, même anonyme, et au-delà de `max_request_bytes`. | Corrigé : corps conservé seulement pour un acheteur authentifié et dans la limite de taille, empreinte limitée aux octets admis. La purge reste prévue avec US-025 (sprint 4). | `MessageLogTest::testAFailedAuthenticationIsLoggedWithoutBuyer`, `testAnOversizedAnonymousRequestKeepsNoBody` |
| M2 | Majeur | Le masquage par expression régulière se contourne (`<SharedSecret >`, attributs, entités, XML tronqué) ; les autres données personnelles restent en clair. | Corrigé : masquage sur le DOM. Secrets et signatures remplacés ; `Extrinsic` non autorisés, contacts et adresses supprimés ; e-mails masqués après décodage ; un corps illisible n'est jamais stocké. | `BodyMaskerTest` |
| M3 | Majeur | Reconstruction `edit` en temps quadratique, sans plafond d'`ItemOut`. | Corrigé : `max_items_out` (500 par défaut, au-delà statut 400), quantités regroupées par variante, une seule requête pour les variantes et un seul `process()`. | `testARequisitionWithTooManyItemsIsRefused`, `testRepeatedItemsOfOneVariantAreMergedAndCapped` |
| m1 | Mineur | Plafond de quantité contournable par la répétition d'articles. | Corrigé par le regroupement par variante (plafond Sylius : 9 999). Les quantités décimales sont écartées et signalées. Le stock n'est pas vérifié (choix B2B, comme le panier Sylius). | idem |
| m2 | Mineur | La page `inspect` nomme des produits non vendus sur le canal. | Corrigé : même contrôle que la reconstruction. | `testInspectNamesOnlyItemsSoldOnTheChannel` |
| m3 | Mineur | « Transférer » est affiché en consultation. | Corrigé : bouton masqué dans le bandeau et dans le panier latéral. Le panier vide reste créé pour isoler la session. | `testInspectShowsTheRequisitionReadOnlyAndGoesBackWithoutItems` |
| m4 | Mineur | Chemins XPath relatifs. | Corrigé : chemins absolus. | suite fonctionnelle |
| m5 | Mineur | Événements de sécurité sans adresse ni identité. | Corrigé : adresse et identité tentée ajoutées au contexte PSR. Les 429 ne sont toujours pas stockés, pour qu'une rafale ne remplisse pas le journal. | — |
| m6 | Mineur | `MessageLogger` vide toute l'unité de travail. | Corrigé : insertion DBAL. | suite fonctionnelle |
| m7 | Mineur | N'importe qui peut bloquer un acheteur en envoyant de faux secrets avec son identité. | Corrigé : deux budgets d'échecs, 10 par minute pour une identité et une adresse, et 50 par minute pour une identité, toutes adresses confondues. | `testRepeatedAuthenticationFailuresOfAnIdentityFromOneAddressAreLimited`, `testAuthenticationFailuresOfAnIdentityFromManyAddressesAreLimitedToo` |
| i1 | Info | L'en-tête Host est repris dans les URL de réponse. | Documenté : configurer `framework.trusted_hosts`. | — |
