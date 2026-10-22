# Spécifications fonctionnelles

## Statut

Cible issue de la mission, non déclaration de livraison. Consulter le backlog pour les incréments. Les nombres, exemples de versions et courbes de la maquette ne constituent pas des faits sur le parc.

## Utilisateurs et vocabulaire

Le développeur consulte son application ; le lead compare les priorités ; le responsable consulte la synthèse ; l'administrateur configure connexions et analyses. Cette liste provient du cahier des charges local §2. La matrice de droits détaillée reste à décider.

Une application correspond initialement à un dépôt. Une connexion associe une instance Git à des identifiants et un périmètre autorisé. Une analyse extrait des observations à un commit précis. Un constat décrit un risque étayé. Une information inconnue ne signifie ni saine ni vulnérable.

## Parcours cible

1. L'administrateur configure une connexion, son périmètre, ses exclusions et teste son accès. Un échec ne révèle aucun secret.
2. Il demande une synchronisation ; le traitement affiche son état puis les dépôts découverts. Un dépôt inaccessible n'interrompt pas les autres.
3. L'utilisateur ouvre le catalogue, filtre et consulte un dépôt. L'interface distingue branche, commit, dernière analyse, couverture et confiance.
4. Il consulte les versions déclarées et résolues, constats et sources. Une suggestion majeure demande une vérification de compatibilité.
5. Il sélectionne les constats, prévisualise une issue puis confirme sa création. Un lien existant évite un doublon ; fermer l'issue ne résout pas le constat sans nouvelle analyse.

## Écrans attendus

Dashboard global, catalogue, détail, dépendances/technologies, alertes/recommandations, historique, connexions et paramètres. Recherche, filtres, tri autorisé et pagination portent sur les listes. Afficher explicitement absence de données, analyse en cours, échec partiel et information indisponible.

Le dashboard compte les applications par statut et présente migrations prioritaires, dépendances obsolètes et dernières analyses avec définition de chaque indicateur. Les tendances reposent sur des snapshots réellement conservés. Une période sans données reste vide.

## Règles confirmées

- Les versions déduites des manifests ne prouvent pas le runtime déployé.
- Obsolescence, fin de support et vulnérabilité confirmée sont des catégories distinctes.
- Une date de fin de support absente reste inconnue.
- Les sources indisponibles ne doivent pas effacer des alertes connues.
- Aucun scan n'exécute le code du dépôt et aucune issue ne se crée sans action explicite au MVP.
- Le score de 0 à 100 affiche contributions, confiance, couverture et date. Une couverture insuffisante interdit de présenter un score artificiellement rassurant ou pénalisant.

## Identité et accessibilité

Respecter la navigation latérale, les cartes, tables et statuts Exodia des références. Prévoir clair/sombre, navigation clavier, focus visible, libellés explicites et statuts textuels. Le cahier local vise WCAG AA ; vérifier les contrastes lors de l'adaptation, particulièrement pour les boutons violets. Conserver l'organisation visuelle en adaptant le contenu aux états réels.
