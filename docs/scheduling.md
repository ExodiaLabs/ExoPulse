# Planification, secrets locaux et rétention

`php bin/console app:schedule` effectue un passage sans appel aux plateformes Git. Le mode `--watch` recommence toutes les 60 secondes et termine proprement sur SIGTERM ou SIGINT. Un verrou partagé interdit deux passages concurrents.

Les connexions actives arrivées à `nextSyncAt` sont synchronisées selon leur fréquence propre. Les repositories accessibles et non archivés des connexions actives sont analysés selon la fréquence globale des paramètres, pour chacune des branches configurées. La dernière demande d’analyse, y compris un échec, sert à déterminer l’échéance ; un échec récent n’est donc pas relancé chaque minute. Les messages déjà en attente ou en cours restent dédupliqués par RequestJobs. Les données de versions peuvent être actualisées même si le commit est inchangé.

Le traitement parcourt les données par lots de 1 000 avec curseur stable et agrège les dernières dates d’analyse par repository et branche. Les issues externes confirmées sont actualisées toutes les six heures via un message dédié. `nextRefreshAt` est une échéance de demande, distincte de `lastSyncedAt`, date d’une vérification distante réelle. Aucune issue n’est créée automatiquement.

`make worker` démarre le worker et le planificateur avec le profil Compose `worker`, après les migrations. Les deux processus utilisent la même image PHP et la même configuration. `make install` installe les dépendances, initialise les secrets locaux manquants, recrée le service PHP pour charger son fichier d’environnement, compile le frontend puis applique les migrations. Les workers ne démarrent pas avant les migrations.

Le script `php scripts/initialize-secrets.php` crée seulement les valeurs manquantes de `APP_SECRET` et `EXOPULSE_ENCRYPTION_KEY`, sous verrou exclusif. Il conserve les valeurs locales ou système existantes, n’affiche jamais leurs valeurs et protège `.env.local` par des permissions 0600. Aucun mot de passe administrateur n’est créé. Sauvegardez la clé de chiffrement hors du repository ; sa perte rend les jetons existants inutilisables. Le script ne constitue pas un mécanisme de rotation.

`php bin/console app:history:prune` affiche une simulation. L’option explicite `--execute` supprime uniquement les analyses terminées, partielles ou échouées antérieures à `retentionDays`, à l’exclusion des résultats courants référencés par les applications et des travaux en attente ou en cours. L’exécution est auditée. Le planificateur ne purge jamais automatiquement l’historique : sauvegardez la base et choisissez votre fenêtre de maintenance avant une purge.

Les réglages sont réservés aux administrateurs et protégés par CSRF. Fréquence : 1 à 720 heures ; fraîcheur : 1 à 365 jours ; rétention : 1 à 3 650 jours ; chaque pénalité : 0 à 100. Les nouvelles valeurs s’appliquent aux prochains traitements.
