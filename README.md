# ExoPulse

Gardez le pouls de votre parc applicatif.

ExoPulse vise à inventorier des dépôts GitHub et GitLab, analyser leurs manifests sans exécuter leur code et aider à prioriser les migrations.

## État du projet

Fondations proposées en PR : Symfony 8.1/PHP >=8.4, Doctrine/PostgreSQL, Messenger persistant, authentification de session, thème Exodia Tailwind v4 et dashboard vide. Docker, Makefile et contrôles de qualité sont disponibles. Les intégrations fournisseurs, analyses de dépendances, scores, planification et création d'issues décrits dans le backlog restent à développer. Les maquettes de `design-reference/` contiennent des données fictives et ne constituent pas une application opérationnelle.

La mission ExoPulse remplace le périmètre GitLab exclusif des documents de référence par un périmètre multi-fournisseurs. Les fichiers de référence restent inchangés.

## Démarrage

Exécuter `make install`, puis configurer [l’administrateur initial](docs/authentication.md) ; aucun accès n’est actif par défaut. Ouvrir http://localhost:8080. Si le port est occupé : `HTTP_PORT=18080 make install`. Consulter `make help` et [le guide de développement](docs/development.md). Docker Compose v2.24+ et Make suffisent ; PHP et Node s’exécutent dans Docker. `make quality` exécute les contrôles.

## Documentation

- [Backlog et questions ouvertes](docs/backlog.md)
- [Architecture et décisions](docs/architecture.md)
- [Spécifications fonctionnelles](docs/functional-specifications.md)
- [Spécifications techniques](docs/technical-specifications.md)
- [Fournisseurs Git](docs/providers.md)
- [Moteur d'analyse](docs/scanning-engine.md)
- [Sécurité](docs/security.md)
- [Déploiement](docs/deployment.md)

Les documents distinguent les fondations en cours et les comportements cibles. Une PR ne vaut pas validation de production ; les résultats de ses contrôles doivent figurer dans sa description.

## Livraison initiale

Les PR sont empilées dans cet ordre : [socle #2](https://github.com/ExodiaLabs/ExoPulse/pull/2), [thème #27](https://github.com/ExodiaLabs/ExoPulse/pull/27), [accès #28](https://github.com/ExodiaLabs/ExoPulse/pull/28), [Docker #29](https://github.com/ExodiaLabs/ExoPulse/pull/29), [CI #30](https://github.com/ExodiaLabs/ExoPulse/pull/30), puis documentation. Aucune fusion automatique. Chaque PR doit être relue contre sa base déclarée ; après fusion de la base, retargeter la suivante vers develop pour éviter de republier les changements précédents.

Les tests fonctionnels des fondations couvrent accès anonyme, connexion réelle, CSRF, déconnexion, dashboard sans données fictives et commande de comparaison des variables. Les adaptations visuelles n’ont pas encore fait l’objet d’un test navigateur. Les revues exécutées restent limitées aux fondations ; aucune intégration privée n’est opérationnelle.
