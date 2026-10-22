# Validation exécutée et reproductible

## Contrôles automatiques

`make quality` prépare la base de test, vérifie Composer et configurations Symfony, PHPStan maximal, style, PHPUnit, syntaxe JavaScript et build Tailwind/esbuild. GitHub Actions ajoute audits Composer/npm, Gitleaks et construction des images de production. Aucun token personnel n’est nécessaire.

Les tests PostgreSQL utilisent des transactions isolées. Les adaptateurs Git et catalogues reçoivent des réponses MockHttpClient. Les scénarios couvrent plusieurs connexions/identifiants externes identiques, legacy, absence de lock, sources indisponibles, quotas, branches, concurrence, périmètres révoqués, conservation des risques et absence de doublons d’issues.

`WorkflowHttpTest` traverse de vrais formulaires/contrôleurs, ORM et handlers : connexion, découverte, scan, écrans métier, prévisualisation et création d’issue. Les appels Git sont simulés ; un lecteur est refusé pour les actions administratives et les mutations sans CSRF échouent. `PortfolioCapacityTest` vérifie le catalogue avec 500 dépôts répartis sur cinq connexions et une pagination de 25 lignes. Il ne constitue pas un benchmark de charge ou de débit d’API.

## Worker distinct

Créer une base PostgreSQL temporaire nommée exactement `exopulse_worker_verification_test`, exporter explicitement sa DATABASE_URL puis lancer :

```sh
php scripts/verify-worker.php
```

Le script refuse tout autre nom de base. Il applique les migrations, persiste un message dans Messenger Doctrine, lance un processus PHP consommateur séparé et vérifie acquittement et annulation correcte d’une connexion inactive. Ses données sont nettoyées ; la suppression de la base temporaire reste à l’appelant. Il n’appelle aucune API Git et n’utilise pas les secrets locaux. Le test a été exécuté et la base temporaire supprimée après vérification.

## Navigateur

Un contrôle Chromium headless a été exécuté sur un serveur éphémère avec configuration synthétique : connexion réelle, dashboard vide, thème sombre/clair via Stimulus, catalogue à 390 px sans débordement, déconnexion et absence d’erreur JavaScript. Le serveur et ses données temporaires ont été arrêtés. Playwright a été utilisé depuis l’outillage disponible ; il n’est pas une dépendance obligatoire de l’application.

Les templates utilisent les références Exodia locales, laissées inchangées. Ce contrôle ciblé ne remplace pas un audit WCAG complet ni une matrice de navigateurs.

## Production et limites

Les images runtime et nginx ont été construites localement. Le smoke Compose a joué les migrations, démarré PHP/nginx/worker/planificateur/PostgreSQL, vérifié session Secure, assets, rôle DB minimal, sauvegarde et restauration isolée. La file a conservé un message après redémarrage de la base avant consommation.

Aucun déploiement public ni certificat TLS externe n’a été configuré. Aucun accès à un dépôt privé réel n’a été testé ; les permissions et particularités de chaque instance Enterprise restent à vérifier au raccordement. Les résultats CI propres à chaque commit sont visibles dans les PR ; une exécution locale ne remplace pas leur statut.
