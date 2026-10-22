# Spécifications techniques

## Cible et compatibilité

La mission fixe Symfony 8, PHP 8.4 minimum sous réserve de compatibilité, Doctrine/PostgreSQL, Twig/UX, Tailwind v4 et Messenger. Les versions réellement installées et leurs contraintes appartiennent à `composer.lock` et au lockfile frontend ; ne pas déduire leur support de ce document. Vérifier les sources officielles avant chaque montée de version. Aucun engagement de compatibilité ne repose sur les versions fictives des maquettes.

## HTTP

Les routes prévues sont `/`, `/applications`, `/applications/{id}`, leurs dépendances et historique, `/findings`, `/connections`, `/settings`, et les actions POST de test/synchronisation/scan/issues. Les routes sont des vues et formulaires Symfony, sans API JSON publique au MVP. Les GET ne modifient pas l'état métier. Les POST vérifient session, permission, validation et CSRF, puis redirigent vers un état lisible. L'identifiant d'objet ne dispense pas du contrôle d'accès.

## Persistance et traitements

Doctrine Migrations versionne chaque changement. Les unicités garantissent identité des dépôts et déduplication des constats/liens d'issues. Un scan conserve commit, branche, statut, dates et couverture, même partiel. Dates persistées en UTC ; présentation selon configuration utilisateur à préciser.

Messenger utilise un transport PostgreSQL persistant et une file d'échec. Les messages portent des identifiants internes, aucun token. La politique de retry est bornée avec backoff ; `Retry-After` ou les dates de reset des fournisseurs évitent les boucles immédiates. Verrou distribué et reprise d'un traitement interrompu doivent précéder la planification réelle.

## Contrôles requis

PHPUnit : domaine, parseurs et cas d'erreur ; tests d'intégration PostgreSQL et adaptateurs HTTP simulés ; tests fonctionnels session/CSRF/autorisations. PHPStan au niveau maximal raisonnable, PHP-CS-Fixer et build Tailwind bloquent la CI. La CI n'utilise aucun accès à un dépôt privé réel.

## Paramétrage à définir

Bornes de taille des fichiers et de pages API, timeout réseau, nombre de tentatives, concurrence, durée de cache et rétention doivent être configurables et documentés avec leurs valeurs implémentées. Aucun chiffre de performance n'est contractuel à ce stade ; le parc cible compte plusieurs centaines de dépôts.
