# Exécution et réconciliation des scans

`AnalyzeRepositoryHandler` transmet l’identifiant du message à `ScanProcessor::process()`. Le processeur retrouve le scan persistant ; un identifiant absent ou un scan `completed`, `partial` ou `cancelled` ne relance aucun appel. L’ancien statut `success` est également terminal.

## Concurrence et reprise

Un verrou partagé de ressource `scan-repository-{id}` protège toutes les branches du dépôt et les agrégats de dernière analyse. Le processeur recharge scan, dépôt et connexion après acquisition. Il reprend un état `running` lorsque le verrou n’appartient plus à un autre worker. Une contention lève une erreur Messenger récupérable, délai de 1 000 ms, sans dépasser le nombre de retries configuré (`forceRetry=false`). La cohérence entre plusieurs workers exige que leur `LockFactory` utilise le même stockage partagé.

Le TTL demandé vaut une heure ; le processeur renouvelle le verrou pendant la lecture et après l’analyse. La durée maximale d’un scan doit rester inférieure à ce TTL entre deux renouvellements. L’arrêt du worker libère le verrou selon le stockage configuré ; le transport persistant permet le rejeu du message interrompu.

Une connexion inactive, une application sortie du périmètre autorisé ou une surveillance désactivée annule le scan et inscrit la date et l’audit correspondants. Un dépôt archivé reste analysable en lecture seule. Aucune fermeture d’issue ne participe à la réconciliation des risques.

## Lecture et cache

Le fournisseur résout la tête de la branche avant toute lecture. Tous les fichiers utilisent ce commit fixé : `composer.json`, `composer.lock`, `package.json`, `npm-shrinkwrap.json`, `package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`, `.nvmrc`, `.node-version`, `.php-version`, `Dockerfile`.

Le cache Symfony identifie le snapshot de fichiers par identifiant interne du dépôt et commit, sans mélange entre connexions. Sa durée vaut trente jours. Un commit identique réutilise les contenus ; chaque nouveau scan appelle tout de même le moteur avec les poids enregistrés dans `ConfigurationStore`. Le paramètre global entier `externalEnrichment` vaut 0 par défaut : aucun catalogue public de packages ou de support n’est alors consulté et l’inventaire statique reste disponible. Une activation explicite à 1 autorise le rafraîchissement des catalogues ; les noms et coordonnées de packages peuvent alors partir aux registres publics. Lorsque cette activation existe, les caches des versions et calendriers possèdent leurs propres durées ; le code inchangé ne bloque donc pas leur renouvellement.

Un fichier absent est une absence connue. Une réponse de lecture inexploitable ou dépassant 2 Mio produit une erreur partielle expurgée. Les autres fichiers restent analysés ; le cache de contenus partiels expire immédiatement pour permettre une lecture ultérieure. Le scan incomplet conserve un score non calculable et plafonne confiance/couverture à 75. Un échec d’autorisation ou de quota interrompt le traitement et suit la politique de retry.

Les contenus de manifests et lockfiles peuvent révéler des packages privés : protéger les répertoires de cache comme les données applicatives. Ne pas activer de cache public/CDN. L’effacement d’un dépôt doit tenir compte de cette conservation, ou purger le cache applicatif selon la procédure d’exploitation.

## États et erreurs

Une analyse sans erreur, avec couverture et confiance de 100, devient `completed`. Les autres résultats exploitables deviennent `partial`, avec sources et erreurs conservées dans le snapshot. Les agrégats du dépôt reprennent le dernier scan et ses limites explicites. Un score provisoire peut rester dans le snapshot partiel ; le score agrégé du dépôt reste alors nul pour éviter un classement en bonne santé malgré des risques conservés. Une source externe indisponible ne produit pas un score artificiel.

Une erreur bloquante conserve `failed`, un message sûr, la date UTC et un événement d’audit. Un 404 lors de la résolution de tête marque le dépôt inaccessible ; cela peut aussi signaler une branche supprimée, ce que précise le message. Les 401/403 sans indication de quota et les 404 sont non récupérables. Un quota ou un `Retry-After` donne une erreur récupérable bornée avec délai entre une seconde et un jour. Les autres erreurs sont génériques, sans chaîne d’exception réseau brute, et suivent les retries configurés du transport.

Une erreur isolée de lecture de fichier reste un résultat partiel et ne provoque pas une boucle de retries sur l’ensemble du dépôt. Une synchronisation ou une relance ultérieure peut la corriger. Un message arrivé à la file d’échec conserve son dernier état `failed` pour diagnostic et reprise explicite.

## Constats

`FindingReconciler` dérive l’empreinte persistée par SHA-256 de `branche|empreinte-moteur`, limitée au dépôt par la contrainte d’unicité. Le détail conserve branche, scan, observation et provenance. Le premier constat fixe `firstSeenAt` ; les occurrences suivantes actualisent `lastSeenAt`, preuves et sévérité. Une réapparition réouvre le constat.

Le réconciliateur résout un constat absent uniquement après un scan complet de sa branche. Les erreurs de fichiers, registres ou calendrier, l’absence de lockfile ou une couverture insuffisante conservent les constats précédents. Une analyse d’une autre branche ne clôt aucun constat de la branche initiale. Les snapshots conservent l’observation historique même si le constat courant évolue.

## Vérification

`tests/Scanning/` utilise PostgreSQL isolé, transactions annulées après chaque test, un véritable moteur et des adaptateurs Git sur `MockHttpClient`. Les catalogues de packages/support sont synthétiques. Les tests couvrent cache à commit identique, réévaluation, idempotence, connexion inactive, dépôt archivé, résolution par branche, maintien des risques lors d’erreurs partielles, quotas et contention. Exécuter depuis le conteneur PHP pour utiliser la base de test du réseau Compose : `docker compose exec -T php vendor/bin/phpunit tests/Scanning --no-progress`.
