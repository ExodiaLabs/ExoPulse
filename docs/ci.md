# Intégration continue

Le workflow Quality valide les PR et les push d’initialisation sur main/develop. Trois jobs indépendants :

- `php` installe PHP 8.4, les dépendances verrouillées, contrôle Composer/YAML/Twig/conteneur, prépare une base PostgreSQL de test et les transports, exécute PHPStan maximal, style, PHPUnit et audit Composer.
- `frontend` installe Node 24, exécute npm ci, vérification JS, build et audit des vulnérabilités high/critical.
- `secrets` exécute le CLI Gitleaks open source sur l’historique complet avec sorties expurgées, sans licence d’action ni credential personnel.

Aucun job n’est autorisé à échouer. Les protections GitHub doivent ensuite rendre ces trois checks obligatoires avant fusion ; ce workflow seul ne configure pas les règles du dépôt. Aucune fusion automatique et aucun déploiement automatique ne sont activés. Les PR initiales empilées avant ce workflow n’ont pas de CI propre ; le check de cette PR vérifie leur contenu cumulé.

La base de CI est isolée et les credentials de test sont publics et sans accès externe. Aucune connexion Git réelle n’est utilisée. Les futurs tests d’adaptateurs devront employer des doubles HTTP.

Le healthcheck PostgreSQL utilise des doubles quotes dans `options` : le runner transmet directement les arguments Docker et ne traite pas les simples quotes comme un shell. La première exécution distante a confirmé ce défaut avant tout test PHP.
