# ExoPulse

*Gardez le pouls de votre parc applicatif.*

ExoPulse inventorie les dépôts GitHub et GitLab, lit statiquement leurs manifests et aide à planifier les migrations. Symfony 8.1, PHP 8.4+, PostgreSQL, Twig/Stimulus et Tailwind CSS v4 composent un monolithe déployable avec Docker.

## Fonctionnalités

- Plusieurs connexions GitHub Cloud/Enterprise et GitLab SaaS/self-hosted ; secrets chiffrés et périmètres contrôlés.
- Découverte paginée, synchronisations et scans persistants, relances et planification quotidienne configurable.
- Composer, npm, pnpm, Yarn et intentions PHP/Node ; versions déclarées et résolues séparées, sans exécuter le code des dépôts.
- Versions récentes, échéances de support et sources datées après activation explicite des catalogues externes.
- Dashboard, catalogue filtrable, dépendances, alertes, historique et score explicable ; informations inconnues et périmées signalées.
- Prévisualisation et création manuelle d’issues GitHub/GitLab, déduplication locale et suivi du statut.

Aucune donnée de démonstration n’est chargée en production. Les références visuelles locales sont conservées sans modification. Les changements sont proposés dans des PR dépendantes ; aucune fusion automatique n’est autorisée.

## Démarrer

Prérequis : Docker Engine, Compose v2.24+ et Make.

```sh
make install
# Configurer ensuite l’administrateur : docs/authentication.md
make worker
```

Ouvrir http://localhost:8080 ; si ce port est occupé, utiliser `HTTP_PORT=18080 make install`. Aucun compte ni mot de passe n’est actif par défaut. `make install` initialise des secrets locaux sans les afficher. Activer les catalogues publics dans les paramètres uniquement si la transmission des coordonnées des packages est autorisée.

`make help` liste les commandes. `make quality` vérifie configuration, PHPStan maximal, style, tests et frontend. Les tests utilisent une base PostgreSQL isolée et des réponses Git synthétiques ; ils n’exigent aucun token personnel.

## Documentation

- [Guide utilisateur](docs/user-guide.md)
- [Développement](docs/development.md) et [authentification](docs/authentication.md)
- [Déploiement et sauvegardes](docs/deployment.md)
- [Architecture et ADR](docs/architecture.md)
- [Spécifications fonctionnelles](docs/functional-specifications.md) et [techniques](docs/technical-specifications.md)
- [Fournisseurs Git](docs/providers.md)
- [Moteur d’analyse et score](docs/scanning-engine.md)
- [Sécurité](docs/security.md)
- [Backlog et dépendances](docs/backlog.md)
- [Livraison et ordre des PR](docs/delivery.md) et [preuves de validation](docs/validation.md)

## Limites explicites

L’administration initiale repose sur un compte unique ; aucun SSO ni gestion multi-tenant n’est livré. L’analyse concerne les manifests racine, sans résolution exhaustive des workspaces ni simulation de migration. Aucune source d’avis de vulnérabilités n’est intégrée : une obsolescence n’est jamais présentée comme une vulnérabilité. Les issues créées hors ExoPulse ne sont pas recherchées automatiquement.

Les intégrations sont vérifiées avec des API simulées ; la compatibilité d’une instance Enterprise, ses certificats et ses permissions doivent être contrôlés lors de son raccordement. La capacité cible est de plusieurs centaines de dépôts ; aucun benchmark de charge ne constitue encore un engagement de performance.
