# Intégrations Git

Les contrats métier `GitReader` et `GitIssueWriter` séparent la lecture des opérations d'issues. `GitProvider` les agrège pour les adaptateurs initiaux GitHub et GitLab. Leurs DTO utilisent l'identifiant externe immuable, jamais le chemin renommable comme identité. L'application doit associer cet identifiant à la connexion pour éviter les collisions entre instances.

## Configuration et secrets

`GitProviderFactory::forConnection()` sélectionne l'adaptateur et déchiffre le jeton uniquement en mémoire. Le service `exopulse.git_http_client` est créé par `Symfony\Component\HttpClient\HttpClient::create`, sans décoration de journalisation ou profiler : désactiver seulement `trace_content` ne masque pas les en-têtes d'authentification du profiler Symfony.

`EXOPULSE_ENCRYPTION_KEY` contient exactement 32 octets aléatoires encodés en base64, conservés hors base et hors dépôt. Une valeur absente ou invalide interdit le chiffrement/déchiffrement, sans empêcher l'ouverture des écrans sans connexion. Sodium secretbox chiffre et authentifie chaque jeton avec un nonce aléatoire indépendant. Les chaînes secrètes sont marquées `SensitiveParameter`. L'extension PHP sodium est requise.

La rotation automatique multi-clés n'est pas implémentée. Pour changer de clé, arrêter les écritures et les workers, sauvegarder la base et conserver l'ancienne clé dans le gestionnaire de secrets, déchiffrer puis rechiffrer les identifiants avec les deux clés en mémoire dans une transaction contrôlée, injecter la nouvelle clé, puis redémarrer et tester les connexions. Ne jamais remplacer directement la clé sans rechiffrer les données. Restaurer ensemble la sauvegarde et l'ancienne clé si la rotation échoue.

## Contrôle réseau

`GitEndpointPolicy::validate($url, true)` contrôle une URL d'instance sans requête réseau : HTTPS obligatoire, hôte exact autorisé, aucun identifiant dans l'URL, port explicite, query ou fragment. Les sous-chemins GitLab sont conservés. `EXOPULSE_GIT_HOSTS` est une liste CSV contrôlée par l'administrateur, par défaut `github.com,api.github.com,gitlab.com`. Aucun wildcard n'est interprété.

`EXOPULSE_INTERNAL_GIT_HOSTS` autorise explicitement des hôtes d'entreprise sur réseau privé. Ils sont également admis comme hôtes de connexion. Chaque requête passe par `NoPrivateNetworkHttpClient`, qui fixe l'adresse résolue et vérifie l'adresse réellement connectée. Les réseaux privés restent interdits pour les hôtes ordinaires ; l'exception privée ne s'applique qu'à l'hôte interne explicitement configuré. Toutes les redirections sont refusées, y compris les redirections entre deux hôtes autorisés. Les liens externes retournés par les fournisseurs doivent être HTTPS sans identifiants.

GitHub Cloud utilise `https://api.github.com`. GitHub Enterprise utilise `<instance>/api/v3`. GitLab utilise `<instance>/api/v4`. Les certificats TLS sont vérifiés ; aucun contournement pour les certificats internes n'est prévu. Installer l'autorité de confiance de l'organisation dans l'environnement d'exécution si nécessaire.

## Opérations et limites

La découverte utilise des pages numérotées de 100 repositories. Avec des scopes, GitHub interroge les organisations, GitLab les groupes avec sous-groupes. Sans scopes, les dépôts accessibles à l'utilisateur GitHub et les projets dont l'utilisateur est membre sur GitLab sont listés. L'adaptateur transmet les projets archivés et les branches par défaut absentes, sans les exclure. Les filtres d'inclusion/exclusion appartiennent au cas d'utilisation de synchronisation.

Une page vide ou incomplète termine la pagination ; une limite de 10 000 pages empêche une boucle infinie. Les listes de branches sont également paginées. L'analyse doit lire les fichiers au SHA retourné par `head()` pour garantir la cohérence d'un scan. Les fichiers sont lus par les API de contenu en base64, jamais exécutés. Une réponse 404 sur un fichier donne `null` ; l'absence du repository, le refus d'accès ou le quota ne deviennent pas des fichiers vides.

Chaque requête possède un timeout d'inactivité de 15 secondes et une durée maximale de 30 secondes. Une réponse JSON est limitée à 8 Mio. Les corps non JSON, trop grands ou inattendus produisent une erreur exploitable sans exposer le corps distant. Une source de version indépendante doit gérer ses propres limites.

Les issues se créent uniquement par appel explicite à `createIssue()`. L'application reste responsable de la prévisualisation, de l'autorisation, de l'unicité locale et de la prévention des doubles soumissions. L'adaptateur ne rejoue jamais automatiquement un POST : une réponse perdue après création présente un résultat incertain et nécessite une réconciliation avant nouvelle tentative. `issue()` récupère le statut ; `opened` de GitLab est normalisé en `open`.

`ProviderException` expose un message générique sûr, le statut HTTP quand disponible et un délai `retryAfter` issu de `Retry-After` (secondes ou date) ou de `X-RateLimit-Reset` uniquement lorsque `X-RateLimit-Remaining` vaut explicitement zéro. Un refus 403 avec quota restant reste un refus de permission, sans délai de quota inféré. Le planificateur/Messenger décide du report ; aucun sleep ni retry immédiat ne bloque le processus. Une erreur réseau ou TLS n'expose ni message d'origine ni exception précédente. Aucun token ou corps distant n'est journalisé par ces classes.

## Vérification

Les tests `tests/Git/` utilisent `MockHttpClient` : découverte paginée, scopes et chemins encodés, branches, SHA, lecture de fichier absent ou confirmé, création et statut d'issue, quota, erreur réseau, JSON invalide, redirection rejetée, chiffrement authentifié et interdiction des URLs/IPv4 privées. Ils ne requièrent ni compte fournisseur ni identifiant réel. La connectivité réelle avec un serveur distant et les permissions du token doivent être testées par l'opérateur sur chaque connexion.

Références : [API des repositories GitHub](https://docs.github.com/en/rest/repos/repos), [API des issues GitHub](https://docs.github.com/en/rest/issues/issues), [API des repositories GitLab](https://docs.gitlab.com/api/repositories/), et source installée `vendor/symfony/http-client/NoPrivateNetworkHttpClient.php` pour la protection réseau.
