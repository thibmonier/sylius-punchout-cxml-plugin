# ADR-0002 — Sérialisation cXML par DOM maison

- **Statut** : Accepté (2026-10-14)
- **Contexte projet** : tmonier/sylius-punchout-cxml-plugin, phase Conception

## Contexte

Le plugin lit des documents cXML non fiables envoyés par Internet et émet trois documents simples. La bibliothèque `friendsofcxml/cxml-php` a été évaluée (`project-management/analysis/E-spike-cxml-library.md`) : son parseur retire le DOCTYPE par expression régulière avant de déléguer à `jms/serializer` (`simplexml_load_string` sans option), son validateur DTD peut résoudre une DTD locale fournie par l'entrée, et elle ajoute `jms/serializer`, absent de Sylius 2.3.

## Décision

Lecture et écriture en DOM natif, derrière nos interfaces (`CxmlRequestReaderInterface`, `CxmlDocumentWriterInterface`). `SafeXmlLoader` refuse tout DOCTYPE comportant un sous-ensemble interne avant chargement, puis charge avec `LIBXML_NONET`, sans `LIBXML_NOENT`, `LIBXML_DTDLOAD`, `LIBXML_DTDVALID` ni `LIBXML_PARSEHUGE`, après un contrôle de taille ; les champs sont extraits en XPath vers des DTO immuables. L'écriture construit les documents en `DOMDocument` avec le DOCTYPE SYSTEM 1.2.071 (jamais résolu). La conformité DTD est vérifiée **dans les tests** contre la DTD 1.2.071 locale.

## Alternatives considérées

- `friendsofcxml/cxml-php` en lecture et en écriture : écarté (sûreté du parseur, dépendance jms).
- La bibliothèque en écriture seule : écarté (jms/serializer pour trois documents).

## Conséquences

- Aucune dépendance XML tierce, comportement de sécurité maîtrisé et testé (XXE, billion laughs, taille).
- Environ 1 000 à 1 300 lignes à maintenir (lecteurs, écrivains, tests) ; périmètre limité aux messages utilisés.
- Remplaçable grâce aux interfaces si une bibliothèque sûre émerge.
