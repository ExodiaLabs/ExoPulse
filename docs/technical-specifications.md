# Spécifications techniques

## Versions et composants

PHP >=8.4, Symfony 8.1.*, Doctrine ORM ^3.7, PostgreSQL 17, PHPUnit ^12, PHPStan ^2.1 et Tailwind CSS v4. Les versions résolues appartiennent aux lockfiles ; Composer Semver est une dépendance de production explicite. Extensions requises : sodium, mbstring, ctype, iconv et celles fournies par l’image PHP pour PostgreSQL.

Symfony 8.1.9 était installé lors de l’initialisation du 21 octobre 2026 ; son calendrier annonce janvier 2027 comme fin de support. Prévoir la montée de version avant expiration : [calendrier officiel](https://symfony.com/releases/8.1.json).

## HTTP et persistance

Les contrôleurs servent Twig et formulaires, sans API JSON publique. Les mutations sont POST, protégées par session, rôle et CSRF, puis redirection 303. Les formulaires invalides retournent 422. Les identifiants d’objet sont UUID ; des paramètres SQL et listes de tris autorisés protègent les recherches.

Huit tables métier versionnées par deux migrations conservent connexions, dépôts, jobs, scans, constats, issues, configuration et audit. Les dates de création/modification sont gérées côté ORM. Les snapshots JSON conservent observations, sources et erreurs sans écraser les analyses historiques. L’identité externe est unique par connexion.

## Asynchrone

`PortfolioMessage` est routé sur Messenger Doctrine ; `SynchronizeConnection`, `AnalyzeRepository` et `RefreshIssue` portent seulement un identifiant. Trois retries, délai initial 1 seconde, multiplicateur 2, plafond ordinaire 30 secondes ; les quotas peuvent fixer un délai borné à une journée. Le transport failed conserve les échecs finaux. Les tests substituent un transport mémoire ; un smoke séparé vérifie le vrai worker Doctrine.

Les verrous sont des advisory locks PostgreSQL partagés entre conteneurs. Les jobs et messages sont persistés dans la même transaction. Les handlers rechargent les objets après acquisition et ignorent les états terminaux. La commande `app:schedule --watch` vérifie les échéances chaque minute, sans dépendance à un cron externe.

## Bornes et caches

Les listes métier utilisent 25 lignes par page. La découverte Git limite les pages à 10 000 de 100 entrées. Réponses Git : 8 Mio, 15 secondes d’inactivité et 30 secondes au total. Fichier analysé : 2 Mio maximum ; un dépassement rend l’analyse partielle. Catalogues : 8 Mio, 8 secondes d’inactivité et 15 secondes au total.

Cache des contenus : 30 jours par dépôt/commit, sans conserver les extractions partielles. Versions : 6 heures. Calendriers : 24 heures. Le planificateur actualise les métadonnées même si le code n’a pas changé. Le cache contient potentiellement des noms de packages privés et doit rester protégé.

## Vérification et limites

PHPStan maximal, style, configurations Symfony, tests HTTP/Doctrine/adaptateurs, audits Composer/npm, build frontend et recherche de secrets bloquent GitHub Actions. Les mocks réseau ne prouvent pas les permissions d’un token réel ni toutes les variantes Enterprise.

Les seuils et formules de santé sont documentés dans [analyse](analysis-implementation.md). Les contraintes de charge, le nombre de workers et les limites de fournisseur doivent être mesurés sur le parc réel ; aucun benchmark contractuel n’est fourni.
