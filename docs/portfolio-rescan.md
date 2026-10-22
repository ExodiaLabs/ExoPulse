# Réanalyser le parc après une évolution du moteur

Les anciens scans restent des snapshots historiques. Une amélioration du moteur ne réécrit pas rétroactivement leurs conclusions. Pour demander de nouveaux diagnostics sur les applications actives :

```sh
docker compose exec -T php php bin/console app:scan:portfolio --dry-run
docker compose exec -T php php bin/console app:scan:portfolio --limit=500
```

La limite porte sur les applications, entre 1 et 1000. Les connexions désactivées et les applications inaccessibles, non surveillées ou exclues sont ignorées. Chaque branche configurée fait l’objet d’une demande. Une analyse déjà en attente est réutilisée ; relancer la commande ne duplique pas ces traitements.

Le worker Messenger doit fonctionner avec la version actuelle du code ; le redémarrer après déploiement si ses classes sont déjà chargées. Les requêtes Git fixent le commit courant et les manifests du même commit restent réutilisables en cache. Les informations de versions/support sont revérifiées indépendamment selon leurs durées de cache et l’autorisation d’enrichissement externe.

La commande ne crée aucune issue et ne change aucune autorisation de catalogue. Elle n’affiche ni nom de dépôt privé ni jeton. Pour un parc supérieur à la limite, utiliser les analyses par application ou la planification existante ; cette commande bornée n’est pas un export exhaustif.
