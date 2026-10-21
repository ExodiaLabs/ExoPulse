# Conventions ExoPulse

## Sources et périmètre

Lire la mission, `docs/backlog.md` et les critères de l'issue avant de modifier le code. `design-reference/` est en lecture seule. Ses données sont fictives ; ne jamais les présenter comme des résultats réels. La mission multi-fournisseurs et Tailwind v4 prévaut sur les propositions GitLab exclusif/Tailwind v3 des références.

## Git et PR

`main` porte la production, `develop` l'intégration. Après initialisation, aucun push direct sur ces branches. Partir d'une base propre et à jour ; une branche `feat/`, `fix/`, `refactor/`, `chore/`, `test/` ou `docs/` porte un seul objectif. Les conventions explicites GitHub de la mission prévalent sur les exemples GitLab des skills.

Commits atomiques au format exclusif `:gitmoji: english message`, shortcode et impératif anglais. Inspecter le diff avant chaque commit. Ne pas réécrire un historique partagé ni fusionner sans autorisation explicite.

PR françaises avec les rubriques **Contexte et objectif**, **Modifications apportées**, **Points d'attention pour la review**, **Tests réalisés**, **Issue associée**. Viser moins de 15 fichiers et environ 300 lignes utiles lorsque le périmètre le permet ; expliquer les exceptions structurelles. Les PR dépendantes déclarent leur base et leur ordre de fusion.

## Architecture et sécurité

Monolithe Symfony/Twig : règles dans Domain, orchestration dans Application, adaptateurs dans Infrastructure, HTTP et vues dans Presentation. Les contrôleurs restent fins. Créer des contrats pour les fournisseurs et parseurs interchangeables, sans interface systématique.

Ne pas exécuter le code des dépôts. Ne jamais afficher un token, même dans les erreurs, fixtures, logs ou commandes de diagnostic. Autorisations serveur et CSRF pour les mutations. Les URLs externes passent par une politique SSRF ; une installation interne requiert une autorisation d'hôte explicite.

## Validation

Exécuter les cibles disponibles : `make env-checker`, `make lint`, `make phpstan`, `make cs-check`, `make test`, `make build`, ou leur agrégat `make quality`. Si une cible manque ou échoue, le signaler ; ne pas déclarer la validation acquise. Vérifier migrations, Twig et configuration Symfony selon le changement. Tester les erreurs et les limites avec des doubles HTTP ; aucun token personnel ne doit être requis en CI.

Avant push : inspecter `git status`, le diff, les fichiers temporaires, secrets et traces de debug. Documenter le comportement réel, les résultats exécutés et les limites. Maintenir les docs techniques et produit avec le code. Toute décision structurante donne un ADR. Une revue sécurité réelle reste nécessaire avant activation d'identifiants privés.
