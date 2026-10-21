# Déploiement self-hosted

Le fichier autonome `compose.prod.yaml` déploie PostgreSQL, PHP-FPM, nginx, le worker Messenger et le planificateur. Il ne faut pas le superposer au Compose de développement. Les images PHP et HTTP portent la même révision immuable ; nginx contient les assets compilés de cette révision. Aucun bind mount de code ou de dépendances de développement n'est utilisé.

## Prérequis et frontières

Prévoir Docker Engine, BuildKit et Docker Compose **2.30 ou ultérieur** (`env_file.format: raw`), l'accès aux registres et dépôts de dépendances pendant le build, un disque persistant et un reverse proxy HTTPS sur le serveur. Les builds ne nécessitent ni runner privilégié, ni socket Docker dans un conteneur applicatif, ni identifiants Git personnels.

Seul le port HTTP est publié, sur `127.0.0.1` ; PostgreSQL et FPM restent sur le réseau Compose. Le reverse proxy de l'hôte termine TLS et relaie vers ce port. Le nginx de production transmet `HTTPS=on` à PHP afin que les cookies de session soient Secure. Son upstream FPM est résolu via le DNS Docker avec une fraîcheur de cinq secondes, pour suivre la recréation du conteneur PHP sans conserver une ancienne adresse. **Cette configuration impose une entrée publique HTTPS** : ne pas exposer directement ce port HTTP sur une interface publique. Configurer le renouvellement du certificat et la validation du nom d'hôte sur le proxy. Le test local HTTP du port loopback ne valide pas ce proxy TLS.

Le déploiement fourni cible un serveur unique. Les caches sont éphémères par conteneur ; les sessions PHP sont dans un volume persistant partagé. Une architecture sur plusieurs serveurs demanderait un stockage de sessions adapté. La base PostgreSQL incluse utilise le réseau privé Docker ; pour une base externalisée, appliquer la politique TLS de l'infrastructure et adapter le déploiement plutôt que publier cette base sur le réseau.

## Secrets et variables

Créer trois fichiers protégés, **hors dépôt et hors contexte de build**, par exemple dans `/srv/exopulse/config`, avec un répertoire de mode 700 :

- `postgres-password` (mode 600) : mot de passe aléatoire du compte de bootstrap `exopulse_owner`, réservé aux opérations de sauvegarde/restauration.
- `app-database-password` (mode 444, dans le répertoire parent 700) : mot de passe aléatoire distinct du rôle applicatif `exopulse`, au moins 32 caractères. Le mode 444 permet au processus PostgreSQL de lire le secret monté lors de l’initialisation ; le répertoire parent privé interdit sa lecture aux autres utilisateurs de l’hôte. Utiliser 32 octets aléatoires encodés en hexadécimal. Ce mot de passe doit correspondre à celui de `DATABASE_URL`.
- `runtime.env` (mode 600) : variables applicatives ci-dessous. Ce fichier est lu en mode raw : ne pas entourer les valeurs de guillemets et ne pas échapper les `$` des hashes de mot de passe.

```dotenv
APP_SECRET=REMPLACER_PAR_UN_SECRET_ALEATOIRE
ADMIN_IDENTIFIER=admin
ADMIN_PASSWORD_HASH=REMPLACER_PAR_UN_HASH_VALIDE
DATABASE_URL=postgresql://exopulse:MOT_DE_PASSE_ENCODE_URL@database:5432/exopulse?serverVersion=17&charset=utf8
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
DEFAULT_URI=https://exopulse.example.org
EXOPULSE_ENCRYPTION_KEY=REMPLACER_PAR_32_OCTETS_ALEATOIRES_EN_BASE64
EXOPULSE_GIT_HOSTS=github.com,api.github.com,gitlab.com
EXOPULSE_INTERNAL_GIT_HOSTS=
```

Ces valeurs sont des indications à remplacer, pas une configuration utilisable. Générer la clé de chiffrement avec un générateur cryptographique, conserver sa sauvegarde séparément et suivre [la procédure de rotation](key-rotation.md). La perdre empêche de déchiffrer les identifiants des fournisseurs. Générer le hash administrateur de manière interactive avec la commande Symfony `security:hash-password`, sans mot de passe en argument, et consulter [l'authentification](authentication.md). Un hash vide désactive la connexion de l'administrateur.

Le Compose impose `APP_ENV=prod`, `APP_DEBUG=0`, désactive Dotenv et fixe les répertoires internes. Les secrets applicatifs sont injectés comme variables d'environnement, donc lisibles par un administrateur Docker : limiter cet accès. Les secrets PostgreSQL sont montés sous `/run/secrets` et n'apparaissent pas dans sa ligne de commande. Ne jamais afficher `docker compose config`, `docker inspect` ou les fichiers de configuration complets dans un journal public : utiliser `config --quiet` pour la validation.

Exporter uniquement les chemins et paramètres de déploiement :

```sh
export EXOPULSE_VERSION=COMMIT_IMMUABLE
export EXOPULSE_ENV_FILE=/srv/exopulse/config/runtime.env
export EXOPULSE_DB_PASSWORD_FILE=/srv/exopulse/config/postgres-password
export EXOPULSE_APP_DB_PASSWORD_FILE=/srv/exopulse/config/app-database-password
export EXOPULSE_HTTP_PORT=8080
# Facultatif : base initiale, à garder cohérente avec DATABASE_URL.
export EXOPULSE_DB_NAME=exopulse
```

L’initialisation d’un volume vide crée le rôle applicatif propriétaire de sa base, sans privilèges superuser, création de base ou création de rôle. Le compte de bootstrap reste séparé et son secret n’est jamais fourni à PHP. Les variables `POSTGRES_*` et le script SQL du conteneur initialisent seulement un volume PostgreSQL vide. Au démarrage, chaque conteneur PHP vérifie les privilèges de son rôle et refuse de lancer FPM, le worker, le planificateur ou une commande si le rôle possède SUPERUSER, CREATEDB ou CREATEROLE. Un ancien volume n’est jamais modifié automatiquement : préparer un rôle dédié et transférer les propriétés nécessaires avec une procédure DBA avant de réutiliser ses données. Modifier le fichier de mot de passe ne change pas le mot de passe d'un rôle déjà créé ; une rotation exige une opération SQL contrôlée et la mise à jour coordonnée de `DATABASE_URL`.

## Construction et première mise en service

Depuis une révision propre et identifiée :

```sh
docker compose -f compose.prod.yaml config --quiet
docker compose -f compose.prod.yaml build php web
docker compose -f compose.prod.yaml up -d --wait database
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console lint:container
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console messenger:setup-transports --no-interaction
docker compose -f compose.prod.yaml up -d --wait php web worker scheduler
```

Le Dockerfile installe Composer sans dépendances de développement, copie les templates Twig avec les assets pour que Tailwind analyse ses sources, construit Tailwind et Stimulus dans une étape Node séparée, puis copie uniquement le code nécessaire, les dépendances de production et les assets dans le runtime. Aucun `.env`, test, secret ou outil npm/Composer/PHPStan/PHPUnit n'est présent dans le runtime. Les migrations constituent une étape explicite ; aucune migration n'est exécutée automatiquement au démarrage.

Les conteneurs applicatifs tournent sans root, sans capabilities, avec `no-new-privileges` et un système de fichiers racine en lecture seule. Les caches, logs applicatifs et fichiers temporaires sont dans des tmpfs ; les logs PHP et nginx sont collectés par Docker, avec rotation de taille. PostgreSQL et les sessions ont des volumes persistants.

Vérifier ensuite l'URL HTTPS publique, `/healthz`, le refus des pages métier sans authentification, la connexion administrateur, une connexion fournisseur de test et un scan contrôlé. Les healthchecks prouvent : réponse FPM pour PHP ; réponse Symfony via FPM pour nginx ; processus attendu et `SELECT 1` pour worker/planificateur. Ils ne prouvent pas qu'un job métier particulier progresse. Contrôler également l'historique des synchronisations et scans, les issues en état incertain et la file d'échecs.

## Exploitation et mises à jour

```sh
docker compose -f compose.prod.yaml ps
docker compose -f compose.prod.yaml logs --tail=100 worker scheduler
docker compose -f compose.prod.yaml exec -T php php bin/console messenger:failed:show
```

La file persiste dans PostgreSQL. Les erreurs transitoires ont des retries bornés ; après échec, diagnostiquer avant une relance manuelle. Ne pas rejouer automatiquement une création d'issue incertaine : suivre [la réconciliation](issues.md). Le planificateur met en file les échéances et ne crée pas automatiquement d'issues. `app:history:prune --no-interaction` prévisualise la rétention ; l'option `--execute` supprime les analyses éligibles et doit suivre la politique validée par l'exploitant.

Pour une mise à jour : conserver les tags précédents, construire les deux nouvelles images, arrêter worker et scheduler, activer la maintenance au proxy si le schéma change, sauvegarder la base, jouer les migrations avec la nouvelle image, recréer PHP/nginx puis reprendre worker et scheduler. Exécuter les contrôles de mise en service avant de retirer la maintenance. Le délai d'arrêt de 90 secondes permet aux traitements bornés de terminer ; vérifier la file d'échec après une interruption forcée.

## Sauvegarde et restauration

Avec les variables de déploiement exportées, sauvegarder dans un répertoire protégé extérieur au dépôt :

```sh
scripts/backup-production.sh /srv/exopulse/backups/DATE-REVISION.dump
scripts/restore-production.sh /srv/exopulse/backups/DATE-REVISION.dump exopulse_restore_DATE_UNIQUE
```

Le script de sauvegarde utilise `pg_dump -Fc`, refuse d'écraser un fichier existant, applique `umask 077` et vérifie la lisibilité de l'archive avec `pg_restore --list`. Son code de retour fait foi ; après une erreur, une archive partielle peut subsister et ne doit pas être considérée comme valide. Chiffrer les archives, définir une rétention, une copie hors hôte et des exercices réguliers de restauration. Ces obligations d'exploitation ne sont pas remplacées par un volume Docker.

La restauration crée les objets sous le rôle applicatif `exopulse` dans une **nouvelle** base dont le nom commence par `exopulse_restore_`, puis utilise `pg_restore --exit-on-error`. Elle ne supprime ni ne nettoie la base existante et échoue si la cible existe déjà. Une restauration partiellement échouée laisse sa base de diagnostic en place. Vérifier schéma, comptes, scans, messages et déchiffrement avec la clé correspondante avant de basculer `DATABASE_URL`. Pour utiliser durablement la nouvelle base, adapter également `EXOPULSE_DB_NAME` afin que les healthchecks et sauvegardes suivants ciblent cette base. Ne jamais inclure de données de production dans l'environnement de développement.

Sauvegarder séparément les clés, secrets et configuration protégée. Les sessions persistantes ne sont pas nécessaires à la restauration métier : leur perte déconnecte les utilisateurs.

## Retour arrière

Arrêter worker et scheduler, remettre `EXOPULSE_VERSION` à la révision précédente puis recréer PHP/nginx/worker/scheduler avec les images conservées. Ce rollback applicatif conserve les volumes. Il ne revient pas automatiquement sur une migration de base : vérifier sa compatibilité avant de redémarrer l'ancienne version. Une migration destructive nécessite une procédure spécifique et, si nécessaire, une restauration dans une nouvelle base, avec une perte de données explicitement évaluée avant bascule.

`docker compose -f compose.prod.yaml down` arrête les services et conserve les volumes. Ne pas ajouter `-v` pour un arrêt ordinaire. Aucun nettoyage de volume ni écrasement de base existante n'est inclus dans les scripts.

## État de vérification

Les preuves de construction et d'exécution locales sont rapportées avec la livraison. Aucun déploiement externe, certificat TLS public, accès Git privé ou sauvegarde distante n'est configuré automatiquement. Un build local réussi ne constitue pas une publication d'image ni un pipeline de déploiement vérifié. Le job bloquant `containers` de GitHub Actions construit les deux cibles de production et vérifie, sans base de données, l'utilisateur non root, les extensions, les assets et l'absence des outils/fichiers de développement. Ce job ne publie ni ne déploie d'image.


Vérification locale du 21 octobre 2026, dans un projet Compose isolé :

- Images PHP et nginx construites avec les assets ; runtime non root, sans Composer, Node, compilateurs ni dépendances de tests.
- Cinq services sains en lecture seule côté application ; migrations, transports et validation du schéma réussis. La recréation de PHP seul, sans redémarrer nginx, conserve une réponse Symfony HTTP 200 après résolution de la nouvelle adresse.
- Rôle applicatif sans SUPERUSER/CREATEDB/CREATEROLE ; tentative de démarrage avec le rôle de bootstrap refusée avant la commande demandée.
- Connexion administrateur, dashboard authentifié et cookie Secure vérifiés ; CSS et JavaScript servis avec HTTP 200. Quatre sélecteurs issus des templates, dont `lg:grid-cols-5` et `grid-cols-2`, sont présents dans les builds CSS de développement et de production.
- Archive custom en mode 600 restaurée dans une base nouvelle ; tables applicatives détenues par le rôle restreint, refus d'écraser un fichier ou une base existante.
- Un message synthétique sans objet métier associé a traversé un arrêt/redémarrage de PostgreSQL : un message avant et après arrêt, puis zéro après reprise du worker, sans message en file d'échec. Aucun repository réel n'a été interrogé dans ce test.
