# ADR 0005 — Verrous partagés et catalogues autorisés

Statut : accepté.

## Contexte

Plusieurs conteneurs peuvent traiter un même dépôt ; un verrou de fichier local ne coordonne pas des workers répartis. Les dépendances privées peuvent par ailleurs révéler l’organisation interne si leurs noms sont envoyés à un registre public.

## Décision

Utiliser les verrous consultatifs PostgreSQL pour demandes, synchronisations, scans, planification et issues. Les messages ne portent que des UUID et les jobs sont enregistrés transactionnellement avec le transport Doctrine. Recharger les objets après acquisition du verrou et conserver les états terminaux idempotents.

Désactiver les catalogues externes par défaut et exposer un choix administrateur explicite. L’inventaire statique fonctionne sans enrichissement ; les versions récentes et calendriers inconnus restent inconnus, sans pénalité de score inventée.

## Conséquences

Aucun Redis supplémentaire. Une rupture de connexion libère les verrous ; PostgreSQL devient une dépendance requise pour l’exécution des jobs. Les tokens chiffrés et caches exigent une protection distincte. Le consentement est global à l’instance ; une liste de packages privés autorisés par connexion pourra être ajoutée si un besoin réel apparaît.
