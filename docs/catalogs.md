# Catalogues de versions et de support

L’enrichissement externe reste désactivé par défaut. Dans ce mode, aucun appel aux registres ni à endoflife.date n’est effectué. Les versions récentes restent inconnues, mais une référence historique embarquée permet de signaler des séries anciennes hors support. Une date absente reste inconnue ; elle ne signifie jamais « supporté ».

La référence `src/Infrastructure/Registry/lifecycle-reference.json` a été vérifiée le 22 octobre 2026 auprès des éditeurs :

| Produit | Séries embarquées | Source primaire |
| --- | --- | --- |
| Symfony | 2.8, 3.4, 4.0 à 4.4, 5.0 à 5.4, 6.0 à 6.3, 7.0 à 7.3 | [API des releases](https://symfony.com/releases/4.4.json), une URL par série |
| PHP | 5.3 à 5.6, 7.0 à 7.4, 8.0 et 8.1 | [Branches non supportées](https://www.php.net/eol.php) |
| Node.js | majeures 4 à 21, 23 et 25 | [Calendrier officiel](https://github.com/nodejs/Release/blob/main/schedule.json) |
| Vue | 2 | [Annonce de fin de vie](https://v2.vuejs.org/eol/) |

Le catalogue est volontairement borné aux séries vérifiées. Symfony 5.4 conserve un support de sécurité jusqu’en février 2029 selon sa source officielle, malgré sa fin de maintenance active en novembre 2024 ; il ne doit pas être classé hors support de sécurité. Il ne promet pas une couverture exhaustive et ne déduit aucune échéance React. Aucun numéro de version cible ni dernier patch n’est inventé à partir de cette référence.

Symfony publie un mois, par exemple novembre 2023 pour 4.4. Le stockage utilise la fin de ce mois (`2023-11-30`) comme borne conservatrice, avec `supportDatePrecision=month`. Cette valeur représente un mois de fin de support, pas une date exacte annoncée par Symfony. Le calendrier endoflife.date peut fournir un jour différent : son URL, sa précision et sa date de vérification demeurent distinctes.

La référence primaire embarquée est prioritaire même lorsque l’enrichissement est autorisé : un agrégateur communautaire peut ne pas encore refléter une prolongation de support comme celle de Symfony 5.4. Le calendrier en ligne complète uniquement les séries absentes (cache 24 heures). Une panne reste signalée et ne supprime pas les informations locales déjà vérifiées. La référence locale ne reçoit jamais une fraîcheur artificielle lors d’un scan. `supportOrigin` vaut `online` ou `bundled` ; `supportVerifiedAt` indique respectivement la récupération réussie ou la vérification de la référence versionnée.

Pour actualiser la référence : consulter les sources primaires, modifier uniquement les séries vérifiées et leurs dates, mettre à jour `verifiedAt`, puis exécuter `vendor/bin/phpunit tests/Analysis` et PHPStan. Une nouvelle série sans source vérifiée reste absente. Les dates ne sont pas recalculées à chaque analyse.

## Adaptateurs distants

Les contrats `PackageCatalog` et `SupportCatalog` séparent les comparaisons métier des services distants. Les adaptateurs interrogent Packagist, npm et endoflife.date v1 sur trois hôtes HTTPS fixes ; redirections interdites, durée maximale 15 secondes, réponse maximale 8 Mio.

Les versions sont cachées six heures, les calendriers vingt-quatre heures. Chaque résultat conserve URL et date de consultation réussie. Un quota ou une panne active un circuit temporaire borné dans le processus ; aucune réponse indisponible ne devient une version inventée.

endoflife.date est un agrégateur communautaire, distinct des éditeurs officiels. PHP, Node.js, Symfony et Vue sont couverts lorsque la série et la date existent. Aucun calendrier React n’est supposé. Les suggestions de cible ne prouvent aucune compatibilité de migration.

Les noms de packages privés peuvent être sensibles : ces adaptateurs ne doivent être appelés qu’après activation explicite de l’enrichissement externe. Aucun code du dépôt n’est envoyé aux registres. Le paramètre `externalEnrichment`, administrable dans `/settings`, est vérifié par le processeur avant tout enrichissement.

Tests : `vendor/bin/phpunit tests/Analysis/RegistryCatalogTest.php` utilise uniquement des réponses HTTP synthétiques ; vérifier cache, quotas, formats, taille et refus des sources non autorisées.
