# 0002 — Lire les dépôts et utiliser une file persistante

- Statut : accepté
- Date : 2026-10-21
- Décideur : CTO ExoPulse

## Contexte

Les dépôts privés et legacy sont des entrées non fiables ; leur absence de CI ne doit pas empêcher une analyse. Les appels distants ne doivent pas occuper la requête HTTP.

## Décision

Lire statiquement des fichiers bornés à commit fixé, sans lancer Composer, npm, shell ou code du dépôt. Utiliser Symfony Messenger avec transport Doctrine/PostgreSQL, trois retries bornés avec backoff et file failed. Les futurs messages ne porteront que des identifiants internes. Les scans conserveront des résultats partiels sans interrompre le parc.

## Conséquences

Le transport et le worker sont disponibles ; messages métier, verrous, idempotence, reprise et planificateur restent à implémenter avant activation des scans. Le commit inchangé permet de réutiliser l’extraction, mais ne dispense pas de rafraîchir versions et cycles de vie.

## Alternatives écartées

Exécuter les outils du dépôt sur le serveur expose secrets et infrastructure. Redis/RabbitMQ ajouteraient un service ; les reconsidérer seulement si la capacité PostgreSQL mesurée devient insuffisante. Renovate reste une source complémentaire éventuelle.
