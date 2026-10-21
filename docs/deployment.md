# Déploiement

## État réel

L’environnement Compose est destiné au développement. Les images dev et runtime ont été construites et la configuration du conteneur runtime validée. Aucun déploiement de production n’a été effectué. Les adaptateurs fournisseurs, scans et planificateur ne sont pas livrés ; ne pas activer d’identifiants Git privés à ce stade.

## Image runtime

```sh
npm ci
npm run build
docker build --target runtime -t exopulse:REVISION .
```

Remplacer REVISION par un commit immuable. L’image runtime contient les dépendances Composer sans dev et les assets compilés. Elle ne contient aucun `.env`, secret ou Composer. `APP_RUNTIME_OPTIONS` désactive Dotenv pour imposer les variables injectées au démarrage. PHP s’exécute sans root. Fournir un serveur HTTP distinct avec le contenu `public/` de la même révision, relié au port FPM 9000 sur réseau privé.

Injecter APP_ENV=prod, APP_DEBUG=0, APP_SECRET, ADMIN_IDENTIFIER, ADMIN_PASSWORD_HASH, DATABASE_URL, MESSENGER_TRANSPORT_DSN, LOCK_DSN, DEFAULT_URI et APP_SHARE_DIR. Utiliser un secret aléatoire et un hash fort ; voir [authentication.md](authentication.md). Conserver caches/sessions accessibles au processus et partagés entre instances si le déploiement devient horizontal. Configurer HTTPS, les proxies de confiance et TLS PostgreSQL selon l’infrastructure ; aucune confiance réseau large n’est préconfigurée.

## Mise en service à compléter

1. Préparer secrets, sauvegarde et URL canonique ; vérifier le proxy HTTPS.
2. Démarrer PostgreSQL et contrôler son état.
3. Sauvegarder avant migration ; jouer les migrations comme étape explicite, jamais dans l’entrypoint.
4. Préparer les transports Messenger, puis démarrer HTTP et worker de la même version.
5. Vérifier /healthz (PHP/Symfony), accès anonyme redirigé et connexion réelle. /healthz ne prouve pas la disponibilité de PostgreSQL.
6. Lorsque les traitements métier seront livrés, vérifier publication, consommation et reprise des messages avant d’activer le planificateur.

La file async utilise trois retries avec backoff, puis failed. Consulter `messenger:failed:show` et analyser la cause avant `messenger:failed:retry`. Aucun token ne doit apparaître dans les arguments ou logs. Les logs PHP sortent sur stderr.

## Sauvegarde et retour arrière

Sauvegarder PostgreSQL avec `pg_dump -Fc`, puis restaurer via `pg_restore` dans une base isolée. Pour le Compose local :

```sh
umask 077
docker compose exec -T database pg_dump -U exopulse -d exopulse -Fc > /tmp/exopulse-backup.dump
```

Ce fichier peut contenir des données sensibles : le conserver hors dépôt, le chiffrer et contrôler ses accès. Adapter utilisateur/base à la configuration effective. Définir rétention et sauvegarde séparée des futures clés de chiffrement avec l’exploitant. Tester chaque procédure de restauration avant production.

`make down` conserve les données. Ne pas utiliser down -v pour un arrêt ordinaire. Un rollback applicatif redéploie l’image précédente ; il ne restaure pas un schéma supprimé. Les migrations destructives demanderont une procédure dédiée.

## Vérification locale du 21 octobre 2026

Sauvegarde custom restaurée dans une base isolée créée pour le test ; tables et compte de messages identiques, puis base temporaire supprimée. Arrêt/redémarrage PostgreSQL et worker : schéma conservé et services sains. Limite : file vide, aucune preuve de reprise d’un message métier en vol. Cette vérification de développement ne remplace pas un exercice de restauration en production.
