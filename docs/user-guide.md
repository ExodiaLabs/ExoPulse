# Utiliser ExoPulse

ExoPulse présente les résultats de lectures statiques de repositories. Le code des applications inventoriées n’est pas exécuté. Une dépendance obsolète ne constitue pas à elle seule une vulnérabilité.

## Accéder à son espace

Connectez-vous avec les identifiants fournis par votre administrateur. Le formulaire signale une connexion refusée sans révéler les détails du compte. Le bouton « Se déconnecter » ferme la session. Le mode clair ou sombre peut être changé depuis l’en-tête ; ce choix est conservé dans le navigateur lorsque son stockage est autorisé.

## Connecter une plateforme

Dans **Connexions**, ajoutez une connexion GitHub ou GitLab. Donnez-lui un nom distinct, configurez son instance et les organisations ou repositories autorisés. Utilisez un jeton aux permissions minimales. Le secret n’est jamais réaffiché.

Le fournisseur et l’instance sont fixes après création. Créez une nouvelle connexion pour changer de plateforme ; le jeton peut être remplacé dans la connexion existante.

Depuis le détail d’une connexion, testez son accès puis lancez une synchronisation. L’historique distingue les traitements, les échecs et les erreurs disponibles. Les traitements asynchrones peuvent nécessiter quelques minutes ; rechargez la page pour consulter leur avancement. Désactiver une connexion évite ses futurs traitements planifiés.

## Consulter le parc

Le catalogue permet de rechercher une application, filtrer son état et trier les résultats. Le filtre « À actualiser » regroupe les vérifications absentes ou trop anciennes selon le délai configuré. Un ancien score reste historique et ne garantit pas la santé actuelle. Les commandes de pagination donnent accès aux pages suivantes. Chaque fiche affiche la santé, la confiance, la couverture et la date de dernière vérification. Les vues **Dépendances** et **Historique** complètent les constats.

Dans les paramètres, un administrateur peut autoriser les catalogues externes pour enrichir les versions et cycles de vie. Cette option est désactivée au départ : elle envoie les noms et versions des packages aux registres publics, jamais le contenu du code. Sans cette autorisation, l’inventaire statique reste consultable, mais les conclusions externes sont indisponibles.

La version déclarée exprime une contrainte du manifeste ; la version résolue provient des fichiers de verrouillage lorsqu’ils sont lisibles. Elles ne prouvent pas la version effectivement déployée. Une information absente apparaît comme inconnue ou indisponible. Une application sans score n’est ni saine ni critique par défaut.

Le dashboard reprend les indicateurs disponibles, les applications prioritaires et les dernières analyses. Les réglages expliquent les pénalités du score. La confiance et la couverture restent séparées de ce score pour rendre visibles les limites des informations disponibles.

## Préparer une migration

Dans les alertes ou la fiche d’une application, sélectionnez les constats d’un même repository puis prévisualisez une issue. Vérifiez les versions actuelles, les cibles et leur compatibilité. Le titre et la description peuvent être ajustés avant confirmation. L’issue n’est créée qu’après cette confirmation ; une issue déjà associée à la sélection est signalée pour éviter un doublon. Depuis la fiche d’application, actualisez son statut externe lorsque nécessaire.

Les comptes lecteurs consultent les résultats sans gérer les connexions, les paramètres ou la création d’issues. Les actions d’administration exigent un compte administrateur.

Une indisponibilité de plateforme ou de source de versions peut limiter le résultat d’une analyse. Consultez son état et son message d’erreur avant de relancer. Une absence de constat ne garantit pas une absence de risque.

## Accessibilité et limites

La navigation et les formulaires utilisent les commandes standard du navigateur. Un lien « Aller au contenu » est accessible au clavier. Les états ne reposent pas uniquement sur des couleurs. Les tableaux restent consultables par défilement horizontal sur petit écran. La vérification visuelle avec des technologies d’assistance reste à réaliser.

## Repères dans l’interface

Quand le parc est vide, le tableau de bord présente les trois étapes du premier raccordement et un accès direct aux connexions pour les administrateurs. Les cartes précisent les seuils de santé ; aucun indicateur de démonstration n’est ajouté. Les liens du dashboard permettent de rejoindre le catalogue et l’historique.

La rubrique Applications reste sélectionnée dans les détails, dépendances et historiques d’une application. Connexions reste sélectionnée pendant sa configuration. Les accès d’administration sont masqués pour les lecteurs, en complément des contrôles serveur. Sur mobile, la navigation défile horizontalement. Le catalogue conserve les filtres et propose leur réinitialisation.
