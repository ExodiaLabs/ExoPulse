# Livraison ExoPulse

La pile de branches contient l’application fonctionnelle, avec les limites explicites du README. `main` et `develop` restent à leur initialisation : les PR sont proposées en draft et aucune fusion n’a été exécutée. Le code complet se trouve sur la dernière branche de documentation, qui hérite de tous les incréments ci-dessous.

## Ordre de revue

Chaque PR se relit contre sa base déclarée, pas contre `develop`, pour isoler son objectif. Après une fusion explicitement autorisée, retargeter la suivante vers la branche d’intégration et vérifier les contrôles. Les deux corrections de dépendances de tests sont intégrées dans #52 par merges ordinaires, sans force push ni réécriture partagée.

| PR | Incrément |
| --- | --- |
| [#2](https://github.com/ExodiaLabs/ExoPulse/pull/2) | Socle Symfony |
| [#27](https://github.com/ExodiaLabs/ExoPulse/pull/27) | Thème Exodia |
| [#28](https://github.com/ExodiaLabs/ExoPulse/pull/28) | Authentification |
| [#29](https://github.com/ExodiaLabs/ExoPulse/pull/29) | Environnement local |
| [#30](https://github.com/ExodiaLabs/ExoPulse/pull/30) | GitHub Actions qualité |
| [#31](https://github.com/ExodiaLabs/ExoPulse/pull/31) | Architecture et backlog |
| [#32](https://github.com/ExodiaLabs/ExoPulse/pull/32) | Persistance initiale |
| [#33](https://github.com/ExodiaLabs/ExoPulse/pull/33) | Requêtes du parc |
| [#34](https://github.com/ExodiaLabs/ExoPulse/pull/34) | Contrats et secrets |
| [#35](https://github.com/ExodiaLabs/ExoPulse/pull/35) | Adaptateurs GitHub/GitLab |
| [#36](https://github.com/ExodiaLabs/ExoPulse/pull/36) | Métadonnées de suivi |
| [#37](https://github.com/ExodiaLabs/ExoPulse/pull/37) | Distinction permissions/quotas |
| [#38](https://github.com/ExodiaLabs/ExoPulse/pull/38) | Synchronisation persistante |
| [#39](https://github.com/ExodiaLabs/ExoPulse/pull/39) | Lecture statique des manifests |
| [#40](https://github.com/ExodiaLabs/ExoPulse/pull/40) | Versions et support |
| [#41](https://github.com/ExodiaLabs/ExoPulse/pull/41) | Constats et score explicable |
| [#42](https://github.com/ExodiaLabs/ExoPulse/pull/42) | Scans et réconciliation |
| [#43](https://github.com/ExodiaLabs/ExoPulse/pull/43) | Gestion des connexions |
| [#44](https://github.com/ExodiaLabs/ExoPulse/pull/44) | Paramètres et consentement |
| [#45](https://github.com/ExodiaLabs/ExoPulse/pull/45) | Fraîcheur et santé actuelle |
| [#46](https://github.com/ExodiaLabs/ExoPulse/pull/46) | Catalogue et preuves |
| [#47](https://github.com/ExodiaLabs/ExoPulse/pull/47) | Service de planification des issues |
| [#48](https://github.com/ExodiaLabs/ExoPulse/pull/48) | Création manuelle des issues |
| [#49](https://github.com/ExodiaLabs/ExoPulse/pull/49) | Dashboard, alertes et historique |
| [#50](https://github.com/ExodiaLabs/ExoPulse/pull/50) | Planification et entretien |
| [#51](https://github.com/ExodiaLabs/ExoPulse/pull/51) | Rotation de clé |
| [#52](https://github.com/ExodiaLabs/ExoPulse/pull/52) | Parcours complets et capacité |
| [#53](https://github.com/ExodiaLabs/ExoPulse/pull/53) | Images et exploitation production |

## Validation

`make quality` a réussi avec 145 tests et 763 assertions, PHPStan maximal, style, configurations et build frontend. Audits Composer/npm sans vulnérabilité connue. Un scénario vérifie 500 dépôts sur cinq connexions. Le worker séparé, Chromium, les images, sauvegarde/restauration et reprise de file ont été réellement exécutés ; voir [protocole et limites](validation.md). Les checks propres aux commits restent consultables dans GitHub Actions.

Les API Git et catalogues sont simulées dans les tests ; aucun dépôt privé réel ni proxy TLS public n’a été raccordé. Le SSO, multi-tenant, analyse exhaustive de workspaces, avis de vulnérabilités et recherche automatique d’issues créées hors ExoPulse ne sont pas livrés. Ces limites sont des périmètres explicites, pas des fonctionnalités simulées.

## Mise en service

Configurer l’administrateur, les secrets et hôtes autorisés ; appliquer les migrations puis démarrer worker et planificateur. Pour une exploitation réelle, suivre [déploiement](deployment.md), fournir le proxy HTTPS et vérifier les droits de chaque fournisseur. Activer les catalogues publics uniquement si la transmission des coordonnées est autorisée. Les environnements de vérification temporaires sont arrêtés ; leurs volumes sont conservés, sans données de production.

Les protections de branches et checks requis doivent être activés lors de l’intégration du socle CI, en tenant compte des premières PR de bootstrap qui précèdent le workflow. Aucun contournement de protection ni déploiement public n’a été effectué.
