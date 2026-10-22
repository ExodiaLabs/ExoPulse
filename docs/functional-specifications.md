# Spécifications fonctionnelles

## Vocabulaire et accès

Une application correspond à un dépôt. Une connexion associe une instance Git, un token protégé et un périmètre. Une analyse observe une branche à un commit fixé. Un constat décrit un risque avec ses preuves. Une information inconnue ne signifie ni saine ni vulnérable.

Le compte initial est administrateur. Les vues métier demandent ROLE_USER ; connexions, paramètres, déclenchement de jobs et issues demandent ROLE_ADMIN. Aucun écran de gestion d’autres comptes n’est livré. Le serveur contrôle les autorisations et CSRF indépendamment de l’affichage des boutons.

## Parcours disponible

1. Créer une connexion GitHub/GitLab, définir organisations/groupes, inclusions/exclusions, branches et fréquence ; tester l’accès.
2. Demander une synchronisation puis démarrer le worker. L’historique de connexion expose progression et erreurs sûres. Un renommage conserve l’identité ; une réponse partielle ne retire pas les dépôts absents.
3. Ouvrir le catalogue, rechercher, filtrer, trier et paginer. Consulter détail, technologies/dépendances et historique par application.
4. Activer volontairement les catalogues externes pour obtenir versions publiées et calendriers de support. Les noms de packages sont alors transmis aux registres publics ; aucun code source ne leur est envoyé.
5. Sélectionner les constats d’un même dépôt, prévisualiser puis créer explicitement une issue. Le lien existant empêche une nouvelle création. Une réponse réseau incertaine impose une réconciliation avant tout rejeu.

Dashboard, catalogue, détail, dépendances, alertes, historique global, connexions et paramètres utilisent le thème Exodia clair/sombre. Les listes principales sont paginées à 25 lignes. Les états vides ne contiennent aucune donnée fictive.

## Règles métier

- Versions déclarées et verrouillées restent distinctes ; une intention PHP/Node ne prouve pas le runtime déployé.
- Mise à jour patch/mineure/majeure, fin de maintenance, fin de support et information inconnue sont séparées.
- Une version majeure recommandée exige une vérification de compatibilité, même lorsqu’une contrainte de package est satisfaite.
- Le score va de 0 à 100 ; ses poids sont configurables. Les seuils d’affichage sont 80 pour bonne santé et 50 pour intervention, puis critique. Une analyse partielle ou périmée ne classe pas artificiellement le dépôt en bonne santé.
- Couverture, confiance et fraîcheur sont distinctes du score. Les catalogues indisponibles ne font pas disparaître les constats précédents.
- Le premier constat conserve sa date ; seul un scan complet de la branche peut résoudre un constat absent. Fermer une issue ne résout pas le constat.
- Les scans sont quotidiens par défaut, réglables de 1 à 720 heures. Le planificateur évite les jobs déjà en attente et le worker réutilise les contenus d’un commit inchangé.

Voir [guide utilisateur](user-guide.md) pour les actions et messages, [moteur](scanning-engine.md) pour la formule et [limites](../README.md#limites-explicites).
