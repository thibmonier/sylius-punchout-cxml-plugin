# E. Spike : bibliothèque `friendsofcxml/cxml-php`

> Phase Conception · 2026-10-14 · revue **statique**, en lecture seule, du code sur GitHub (aucun téléchargement ni exécution de code tiers). Les numéros de ligne sont approximatifs.

## Verdict

**Sérialiseur DOM maison, en lecture comme en écriture.** `friendsofcxml/cxml-php` est écartée pour la lecture de documents non fiables. L'écriture via la bibliothèque serait acceptable, mais elle ajouterait `jms/serializer` pour trois documents simples ; nous écrivons donc aussi en DOM (ADR-002).

1. Le parseur délègue à `jms/serializer` après avoir **retiré le DOCTYPE par expression régulière** (`Serializer.php` ~l. 69-74). La sûreté repose sur cette regex, pas sur un réglage libxml explicite.
2. Le parse final est `simplexml_load_string(..., options = 0)` (jms `XmlDeserializationVisitor::prepare` ~l. 97) : pas de `LIBXML_NONET` explicite. Le garde-fou DOCTYPE de jms (liste d'autorisation vide par défaut) est contourné par la regex en amont.
3. `DtdValidator`, en mode « Index », valide le document d'entrée avec son propre DOCTYPE : une DTD locale (`file:///…`) peut être chargée (`LIBXML_NONET` bloque seulement le réseau).
4. Sylius 2.3 n'embarque ni `jms/serializer` ni le bundle : ce serait une dépendance transitive nouvelle, dont la compatibilité PHP 8.4 / Symfony 8 n'est pas déclarée.
5. Le code maison reste de taille raisonnable et entièrement maîtrisé.

## 1. Sûreté du parseur

- `src/CXml/Serializer.php` : aucun appel direct à DOM / SimpleXML. Le DTD est extrait par `preg_match('/<!doctype.+"(.+)"[^>]*>/i')` (défaut `1.2.063`), le DOCTYPE est supprimé par `preg_replace('/<!doctype[^>]+?>/i', '')`, puis `jmsSerializer->deserialize()`. **Hypothèse non testée** : `[^>]+?` s'arrête au premier `>`, si bien qu'un sous-ensemble interne contenant `>` laisserait un résidu.
- jms `XmlDeserializationVisitor` : refuse tout DOCTYPE absent de `doctypeAllowList` (vide par défaut) ; `libxml_disable_entity_loader` seulement si `LIBXML_VERSION < 20900`. La protection contre le billion laughs repose sur les protections par défaut de libxml2.
- `src/CXml/Validation/DtdValidator.php` : `loadXML(…, LIBXML_NONET)`. Le mode cXML injecte un DOCTYPE local (chemin non canonicalisé) ; le mode Index valide le DOCTYPE d'entrée tel quel. `libxml_use_internal_errors` est restauré hors `finally`.

## 2. Dépendances

`php >= 8.2`, `ext-simplexml`, `ext-dom`, `ext-libxml`, `beberlei/assert ^2 || ^3`, **`jms/serializer ^3.30`**, `psr/log`, `psr/event-dispatcher`. `symfony/routing` (dev) ne déclare pas Symfony 8. Sylius 2.3 ne dépend pas de jms.

## 3. Couverture

Modèles : `PunchOutSetupRequest` / `Response`, `ProfileRequest` / `Response`, `OrderRequest`, `PunchOutOrderMessage`, `ItemIn`, `ItemDetail`, `Classification`, `Extrinsic`, `Money`, `Address`, `Contact`. `UnitOfMeasure` reste à confirmer. Builders `PunchOutOrderMessageBuilder` et `OrderRequestBuilder` ; `SimpleSharedSecretAuthenticator`. DTD `1.2.063` par défaut, `1.2.071` possible via `dtdUri`. La gestion du `SharedSecret` dans les journaux n'a pas été vérifiée.

## 4. Maintenance

MIT, releases 2.2.1 (2025-10) à 2.2.5 (2026-09-16), 310 commits, 18 étoiles, 7 forks. Aucun avis de sécurité publié. Projet actif mais avec peu d'utilisateurs (bus factor).

## 5. Recommandation et effort

- (a) lecture et écriture via la bibliothèque : **non**.
- (b) écriture seule via la bibliothèque : possible, mais jms pour trois documents simples ne se justifie pas.
- **(c) DOM maison : retenu.** Lecture avec `DOMDocument::loadXML()`, `LIBXML_NONET`, sans `LIBXML_NOENT`, `LIBXML_DTDLOAD`, `LIBXML_DTDVALID` ni `LIBXML_PARSEHUGE` ; refus de tout DOCTYPE avec sous-ensemble interne, contrôlé **avant** chargement ; taille plafonnée ; extraction en XPath. Écriture avec `DOMDocument` (DOCTYPE `cXML 1.2.071` en SYSTEM, jamais résolu).
- Effort estimé : lecture 6 à 8 classes (500 à 700 lignes avec tests), écriture 4 à 6 classes (400 à 600 lignes), plus les tests de sécurité (XXE, billion laughs, DOCTYPE, taille).

## Non vérifié

Comportement réel de la regex face à un sous-ensemble interne contenant `>`, billion laughs dans l'environnement cible, compatibilité PHP 8.4 / Symfony 8 / jms, présence de la DTD 1.2.071, journalisation du `SharedSecret`. La décision (c) rend ces points sans objet.

## Sources

github.com/FriendsOfcXML/cxml-php (`composer.json`, `src/CXml/Serializer.php`, `src/CXml/Validation/DtdValidator.php`, `src/CXml/Jms/CXmlWrappingNodeJmsEventSubscriber.php`, releases) · `schmittjoh/serializer` master `src/XmlDeserializationVisitor.php` · `sylius/sylius` 2.3 `composer.json`.
