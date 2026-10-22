# Catalogue des applications

Le catalogue `/applications` fournit recherche par nom, connexion, état et tri autorisé, avec 25 lignes par page. Une application représente un dépôt ; les renommages ne changent pas son UUID. Les archives et dépôts inaccessibles restent explicites.

Le détail présente score, couverture, confiance, dernière vérification, constats et liens d’issues existants. Le sous-écran Dépendances sépare contraintes déclarées, versions verrouillées, dernières versions connues et provenance. L’historique conserve chaque scan avec branche, commit, état et erreurs sûres.

Le bouton Lancer une analyse demande ROLE_ADMIN et CSRF ; la requête ne lit pas les manifests, elle met les branches configurées en file. Un périmètre désactivé ou inaccessible refuse la demande avec un message explicite. Les contrôles d’issues sont ajoutés dans l’incrément de pilotage.
