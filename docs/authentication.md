# Accès initial

L’incrément utilise un administrateur unique configuré par `ADMIN_IDENTIFIER` et `ADMIN_PASSWORD_HASH`. Aucun compte utilisable n’est fourni par défaut. Le hash vide interdit une connexion valide. Ce choix convient au premier déploiement monoorganisation ; gestion de plusieurs utilisateurs et SSO restent hors périmètre.

Générer un hash avec `php bin/console security:hash-password` (saisie masquée) puis le définir dans `.env.local` entre apostrophes pour préserver les caractères `$`, ou dans le gestionnaire de secrets du déploiement. Ne jamais fournir le mot de passe dans la ligne de commande. Générer également `APP_SECRET` avec `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` puis le placer uniquement dans la configuration locale ou le gestionnaire de secrets.

Le formulaire de connexion et la déconnexion POST exigent un jeton CSRF lié à la session. Le cookie est HttpOnly, SameSite Lax, Secure sous HTTPS. Une limite de 5 tentatives par 15 minutes utilise le limiteur Symfony ; les caches et sessions doivent être partagés si plusieurs instances servent l’application. Le déploiement initial vise une instance. Seuls `/login`, les assets statiques et `/healthz` sont accessibles sans session. Utiliser HTTPS en production.

Les vues métier sont protégées par ROLE_USER ; l’administrateur hérite de ce rôle. Les mutations de connexion, paramètres, scans et issues demandent ROLE_ADMIN ainsi qu’un jeton CSRF. La rotation du hash impose de fermer les sessions actives (purger le stockage de sessions pendant une maintenance) ; aucune commande de révocation centralisée n’est encore livrée.
