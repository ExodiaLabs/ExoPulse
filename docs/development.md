# Développement

## Démarrer

Prérequis : Docker Engine, Compose v2.24+ (env_file optionnel), Make. Aucun PHP ni Node hôte requis pour les commandes Make.

```sh
make install
```

Cette commande construit PHP 8.4.26, démarre PostgreSQL 17/FPM/nginx, installe Composer, compile Tailwind v4 et initialise les transports Doctrine Messenger. Le worker démarre séparément avec `make worker`. Les synchronisations, analyses et actualisations d’issues sont routées sur le transport persistant. Ouvrir http://localhost:8080 ; si le port est occupé : `HTTP_PORT=18080 make install`, puis http://localhost:18080.

Configurer l’accès selon [authentication.md](authentication.md). `.env.local` est ignoré par Git et chargé par Compose ; après modification, recréer PHP avec `make up` (et worker si actif). Le hash vide fourni par défaut ne permet aucune connexion. Ne pas placer les credentials synthétiques de `.env.test` dans une configuration déployée.

`LOCAL_UID` et `LOCAL_GID` valent 1000 par défaut ; les ajuster à l’utilisateur hôte si nécessaire. Le code et les dépendances sont montés depuis le dépôt pour ce premier environnement Linux. PostgreSQL dispose d’un volume nommé ; le code reste monté en développement. La base n’expose aucun port ; les commandes DB passent par Compose. Les assets construits sont ignorés dans Git.

## Commandes

`make help` décrit les cibles. `make up`, `make down` et `make build` gèrent les services ; down conserve le volume. `make migrate` applique les migrations et prépare async/failed. Deux migrations initialisent les huit tables métier et leurs champs de suivi.

`make test` crée et migre la base suffixée `_test`. `make quality` exécute lint, PHPStan maximal, style, PHPUnit et build frontend ; `make cs-fix` applique le style. Les tests HTTP utilisent une authentification synthétique dans `.env.test`. PHPUnit force APP_ENV=test dans les variables PHP et système ; le bootstrap recharge les fixtures pour ne pas employer les identifiants locaux. Les tests DB utilisent le suffixe `_test`, jamais la base métier. Le smoke du worker utilise une base distincte explicitement nommée.

## Variables

`make env-checker` compare uniquement les noms dans les fichiers `.env*` du dépôt : coche verte si présent, croix rouge sinon. Une valeur vide compte comme présente. Le script n’affiche aucune valeur et ne résout ni interpolation, surcharge système, ni syntaxe multilignes ; il ne valide ni secret, ni complétude. Il retourne 1 si aucun fichier n’est trouvé ou si un fichier est illisible. `php scripts/env-checker.php --no-ansi` fonctionne aussi hors Docker.

Compose utilise `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` (valeurs locales exopulse) et `HTTP_PORT` (8080). Ne pas réutiliser ces valeurs locales en production. `.env.local` peut fournir les variables applicatives ; DATABASE_URL est remplacée dans Compose pour joindre le service `database`.

## Frontend et contribution

Lire les références en lecture seule avant toute adaptation. Voir [theme.md](theme.md) pour les tokens natifs v4, composants et mode clair/sombre. La [navigation Turbo](turbo.md) décrit les cadres, filtres et conventions de formulaires ; le [suivi des traitements](live-monitoring.md) décrit l’actualisation et ses pauses. Le [parcours de création de connexion](connection-wizard.md) documente les étapes, la validation et le comportement sans JavaScript. Les [paramètres par sections](settings-tabs.md) décrivent la navigation clavier et la gestion des erreurs dans les onglets. Aucun script de simulation n’est inclus.

Voir aussi [l’interface mobile](mobile-layout.md) pour les breakpoints, la navigation et les tableaux accessibles.

Suivre [AGENTS.md](../AGENTS.md). Les PR empilées déclarent leur base et ne sont pas fusionnées automatiquement. Les contrôles locaux ne prouvent ni disponibilité des API Git ni qualité visuelle sur navigateur.

Voir [validation exécutée](validation.md) pour le parcours HTTP, le worker séparé et les limites des contrôles navigateur et fournisseur.

Le [diagnostic legacy](legacy-detection.md), le [modèle de lecture du patrimoine](technical-portfolio.md) et les [filtres techniques](technical-estate-ui.md) distinguent les risques établis du score global.
