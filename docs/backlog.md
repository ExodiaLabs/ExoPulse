# Backlog ExoPulse

25 issues publiées sur GitHub. Les critères et le périmètre détaillés vivent dans chaque issue pour éviter deux sources divergentes. Une issue ouverte ne prouve aucune implémentation. Les PR de fondations sont empilées et ne seront pas fusionnées automatiquement.

## Priorités

1. F1–F3 : socle, exécution locale et espace authentifié. Livrés en PR de fondations, à relire.
2. C1–C2 puis I1–I3 : connexions sécurisées, inventaire et reprise des synchronisations.
3. A1–A8 : extractions legacy, référentiels et traitements planifiés.
4. P1–P3 : priorisation, synthèse et historique sur données réelles.
5. T1–T4 : issues manuelles, déduplication et suivi.
6. O1–O2 : administration complète et préparation de mise en service.

Le MVP couvre plusieurs centaines de dépôts et plusieurs instances GitHub/GitLab. Il exclut exécution de code distant, modifications automatiques, SAST complet, estimation des migrations et automatisation des issues. L’intégration d’avis de sécurité devra être spécifiée avant d’afficher une vulnérabilité confirmée ; l’obsolescence ne suffit jamais.

## Issues et dépendances

| Clé | Issue GitHub | Dépendances |
|---|---|---|
| F1 | [#1 — Démarrer le socle Symfony et ses contrôles](https://github.com/ExodiaLabs/ExoPulse/issues/1) | — |
| F2 | [#3 — Exécuter le socle avec PostgreSQL dans Docker](https://github.com/ExodiaLabs/ExoPulse/issues/3) | [#1](https://github.com/ExodiaLabs/ExoPulse/issues/1) |
| F3 | [#4 — Consulter un espace authentifié au thème Exodia](https://github.com/ExodiaLabs/ExoPulse/issues/4) | [#1](https://github.com/ExodiaLabs/ExoPulse/issues/1) |
| C1 | [#5 — Enregistrer et tester une connexion GitHub](https://github.com/ExodiaLabs/ExoPulse/issues/5) | [#3](https://github.com/ExodiaLabs/ExoPulse/issues/3), [#4](https://github.com/ExodiaLabs/ExoPulse/issues/4) |
| C2 | [#6 — Enregistrer et tester une connexion GitLab](https://github.com/ExodiaLabs/ExoPulse/issues/6) | [#5](https://github.com/ExodiaLabs/ExoPulse/issues/5) |
| I1 | [#7 — Découvrir les dépôts GitHub et consulter le catalogue](https://github.com/ExodiaLabs/ExoPulse/issues/7) | [#5](https://github.com/ExodiaLabs/ExoPulse/issues/5) |
| I2 | [#8 — Découvrir les groupes et dépôts GitLab](https://github.com/ExodiaLabs/ExoPulse/issues/8) | [#6](https://github.com/ExodiaLabs/ExoPulse/issues/6), [#7](https://github.com/ExodiaLabs/ExoPulse/issues/7) |
| I3 | [#9 — Reprendre une synchronisation interrompue](https://github.com/ExodiaLabs/ExoPulse/issues/9) | [#7](https://github.com/ExodiaLabs/ExoPulse/issues/7), [#8](https://github.com/ExodiaLabs/ExoPulse/issues/8) |
| A1 | [#10 — Analyser Composer et afficher les preuves PHP/Symfony](https://github.com/ExodiaLabs/ExoPulse/issues/10) | [#7](https://github.com/ExodiaLabs/ExoPulse/issues/7) |
| A2 | [#11 — Analyser npm et les intentions de runtime](https://github.com/ExodiaLabs/ExoPulse/issues/11) | [#10](https://github.com/ExodiaLabs/ExoPulse/issues/10) |
| A3 | [#12 — Lire les lockfiles pnpm](https://github.com/ExodiaLabs/ExoPulse/issues/12) | [#11](https://github.com/ExodiaLabs/ExoPulse/issues/11) |
| A4 | [#13 — Lire les lockfiles Yarn](https://github.com/ExodiaLabs/ExoPulse/issues/13) | [#11](https://github.com/ExodiaLabs/ExoPulse/issues/11) |
| A5 | [#14 — Comparer les dépendances Composer aux versions publiées](https://github.com/ExodiaLabs/ExoPulse/issues/14) | [#10](https://github.com/ExodiaLabs/ExoPulse/issues/10) |
| A6 | [#15 — Comparer les dépendances JavaScript aux versions publiées](https://github.com/ExodiaLabs/ExoPulse/issues/15) | [#11](https://github.com/ExodiaLabs/ExoPulse/issues/11), [#14](https://github.com/ExodiaLabs/ExoPulse/issues/14) |
| A7 | [#16 — Afficher les échéances de support des runtimes et frameworks](https://github.com/ExodiaLabs/ExoPulse/issues/16) | [#10](https://github.com/ExodiaLabs/ExoPulse/issues/10), [#11](https://github.com/ExodiaLabs/ExoPulse/issues/11) |
| A8 | [#17 — Planifier les scans et rafraîchir les référentiels](https://github.com/ExodiaLabs/ExoPulse/issues/17) | [#9](https://github.com/ExodiaLabs/ExoPulse/issues/9), [#14](https://github.com/ExodiaLabs/ExoPulse/issues/14), [#15](https://github.com/ExodiaLabs/ExoPulse/issues/15), [#16](https://github.com/ExodiaLabs/ExoPulse/issues/16) |
| P1 | [#18 — Prioriser les constats et expliquer la santé](https://github.com/ExodiaLabs/ExoPulse/issues/18) | [#14](https://github.com/ExodiaLabs/ExoPulse/issues/14), [#15](https://github.com/ExodiaLabs/ExoPulse/issues/15), [#16](https://github.com/ExodiaLabs/ExoPulse/issues/16) |
| P2 | [#19 — Consulter le dashboard et les alertes réels](https://github.com/ExodiaLabs/ExoPulse/issues/19) | [#18](https://github.com/ExodiaLabs/ExoPulse/issues/18) |
| P3 | [#20 — Comparer les analyses et les tendances](https://github.com/ExodiaLabs/ExoPulse/issues/20) | [#17](https://github.com/ExodiaLabs/ExoPulse/issues/17), [#18](https://github.com/ExodiaLabs/ExoPulse/issues/18) |
| T1 | [#21 — Prévisualiser une issue depuis des constats sélectionnés](https://github.com/ExodiaLabs/ExoPulse/issues/21) | [#18](https://github.com/ExodiaLabs/ExoPulse/issues/18) |
| T2 | [#22 — Créer une issue GitHub sans doublon](https://github.com/ExodiaLabs/ExoPulse/issues/22) | [#21](https://github.com/ExodiaLabs/ExoPulse/issues/21), [#5](https://github.com/ExodiaLabs/ExoPulse/issues/5) |
| T3 | [#23 — Créer une issue GitLab sans doublon](https://github.com/ExodiaLabs/ExoPulse/issues/23) | [#22](https://github.com/ExodiaLabs/ExoPulse/issues/22), [#6](https://github.com/ExodiaLabs/ExoPulse/issues/6) |
| T4 | [#24 — Suivre les issues sans effacer les risques](https://github.com/ExodiaLabs/ExoPulse/issues/24) | [#22](https://github.com/ExodiaLabs/ExoPulse/issues/22), [#23](https://github.com/ExodiaLabs/ExoPulse/issues/23) |
| O1 | [#25 — Administrer les scans et les accès sensibles](https://github.com/ExodiaLabs/ExoPulse/issues/25) | [#17](https://github.com/ExodiaLabs/ExoPulse/issues/17), [#4](https://github.com/ExodiaLabs/ExoPulse/issues/4) |
| O2 | [#26 — Préparer une mise en service restaurable](https://github.com/ExodiaLabs/ExoPulse/issues/26) | [#19](https://github.com/ExodiaLabs/ExoPulse/issues/19), [#20](https://github.com/ExodiaLabs/ExoPulse/issues/20), [#24](https://github.com/ExodiaLabs/ExoPulse/issues/24), [#25](https://github.com/ExodiaLabs/ExoPulse/issues/25) |

## Arbitrages retenus

Une application correspond à un dépôt au MVP ; aucun doublon d’entité Application. L’administration initiale utilise un compte unique sans mot de passe par défaut ; le SSO et une gestion fine de plusieurs utilisateurs pourront remplacer ce mécanisme. Transport PostgreSQL pour limiter le nombre de services. Les paramètres d’exécution et de score seront versionnés avec leurs cas d’usage ; leur présence dans ce plan ne constitue pas une fonctionnalité livrée.

## Avant mise en service

Fixer avec l’exploitant rétention des historiques, distribution des clés, hôtes Git internes explicitement autorisés, sauvegardes et capacité mesurée. Ces choix d’environnement ne bloquent pas le développement local. Les permissions minimales et cas d’échec restent des critères de chaque intégration.
