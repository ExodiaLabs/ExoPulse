# Sécurité implémentée

## Accès et secrets

Authentification de session, CSRF et limitation des tentatives protègent les écrans métier. Le compte initial ne possède aucun mot de passe par défaut ; voir [authentification](authentication.md). ROLE_ADMIN contrôle connexions, paramètres, scans et issues ; ROLE_USER permet la consultation. Les tests refusent les mutations d’un lecteur même si la requête est forgée.

Les tokens Git sont chiffrés par libsodium secretbox avec nonce aléatoire et authentification. `EXOPULSE_ENCRYPTION_KEY` contient 32 octets en base64 et reste hors PostgreSQL/Git. Le formulaire ne réaffiche ni token ni ciphertext ; un champ vide en édition conserve l’ancien token. `initialize-secrets.php` génère les secrets locaux absents et protège `.env.local` en 0600, sans afficher leur valeur.

Les appels Git utilisent un client non tracé afin que le profiler n’enregistre pas les en-têtes d’authentification. Les exceptions opérationnelles excluent corps distants et erreurs réseau brutes. Les audits conservent acteur, action, cible et date, sans token ni clé.

## Réseau et entrées

Une allowlist d’hôtes contrôlée par l’exploitant autorise les instances HTTPS. Credentials d’URL, ports arbitraires, redirections et hôtes non déclarés sont refusés. Le client Symfony NoPrivateNetworkHttpClient contrôle DNS et IP effectivement connectée ; les réseaux privés demandent un hôte exact dans `EXOPULSE_INTERNAL_GIT_HOSTS`. Cette exception ne désactive pas la validation TLS.

Les contenus et réponses réseau sont bornés. Les noms de packages sont validés avant construction d’URL sur les trois hôtes fixes des catalogues. L’enrichissement est désactivé par défaut ; l’administrateur doit autoriser la transmission de coordonnées aux registres publics.

Twig échappe les données distantes. Les recherches Doctrine sont paramétrées et les tris sur liste autorisée. JSON/YAML sont lus comme données ; ni Composer, ni npm, ni Dockerfile distant ne sont exécutés. Les caches de manifests peuvent contenir des noms privés : accès et sauvegardes doivent être protégés.

## Périmètres et concurrence

L’identité d’une connexion fournisseur/instance est immuable. Changer les organisations retire la supervision jusqu’à nouvelle découverte. Synchronisation, scan et création/suivi d’issue revérifient l’autorisation côté serveur ; les états en mémoire sont rechargés sous verrou. Une découverte dont les groupes changent est annulée, y compris pour les groupes GitLab numériques.

Les réservations d’issues sont enregistrées avant POST externe. Un résultat incertain bloque les créations suivantes jusqu’à réconciliation ; ne jamais traiter un timeout comme preuve d’absence d’issue. La déduplication couvre les issues gérées par ExoPulse, sans recherche de toutes les issues externes.

## Rotation et incidents

Pour tourner un token : émettre un nouveau token aux permissions minimales, remplacer via le formulaire, tester et synchroniser, puis révoquer l’ancien chez le fournisseur. Consigner l’action sans secret.

La commande de [rotation de clé](key-rotation.md) vérifie par défaut et rechiffre uniquement avec `--execute`, pendant une maintenance documentée avec sauvegarde DB et ancienne clé. Changer seulement la variable rend les tokens illisibles.

En cas de suspicion de fuite : désactiver la connexion, révoquer le token côté Git, préserver les traces expurgées et appliquer la procédure d’incident de l’organisation. Une sauvegarde DB sans clé séparément conservée ne suffit pas à restaurer les accès.

## Vérifications et limites

Des revues ciblées ont corrigé périmètres obsolètes, concurrence des groupes numériques, score partiel et distinction permission/quota. Les tests couvrent SSRF local, chiffrement, CSRF, rôles et sorties sans secrets. Cela ne constitue pas un audit exhaustif ni un test d’intrusion.

Configurer un proxy HTTPS, les hôtes internes, permissions des tokens et sauvegardes pour l’environnement réel. Les certificats et politiques d’une instance Enterprise n’ont pas été vérifiés avec des identifiants privés réels. Le compte unique et l’absence de multi-tenant sont des limites explicites du produit actuel.
