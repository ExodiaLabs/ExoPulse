# Catalogues externes

Les contrats `PackageCatalog` et `SupportCatalog` séparent les comparaisons métier des services distants. Les adaptateurs interrogent Packagist, npm et endoflife.date v1 sur trois hôtes HTTPS fixes ; redirections interdites, durée maximale 15 secondes, réponse maximale 8 Mio.

Les versions sont cachées six heures, les calendriers vingt-quatre heures. Chaque résultat conserve URL et date de consultation réussie. Un quota ou une panne active un circuit temporaire borné dans le processus ; aucune réponse indisponible ne devient une version inventée.

endoflife.date est un agrégateur communautaire, distinct des éditeurs officiels. PHP, Node.js, Symfony et Vue sont couverts lorsque la série et la date existent. Aucun calendrier React n’est supposé. Les suggestions de cible ne prouvent aucune compatibilité de migration.

Les noms de packages privés peuvent être sensibles : ces adaptateurs ne doivent être appelés qu’après activation explicite de l’enrichissement externe. Aucun code du dépôt n’est envoyé aux registres. Le paramètre `externalEnrichment`, administrable dans `/settings`, est vérifié par le processeur avant tout enrichissement.

Tests : `vendor/bin/phpunit tests/Analysis/RegistryCatalogTest.php` utilise uniquement des réponses HTTP synthétiques ; vérifier cache, quotas, formats, taille et refus des sources non autorisées.
