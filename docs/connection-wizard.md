# Créer une connexion par étapes

La création propose quatre étapes : identifier la plateforme, renseigner le jeton, délimiter les repositories et planifier la synchronisation. « Suivant » vérifie les champs de l’étape courante. « Précédent » conserve les valeurs déjà saisies. L’indicateur affiche l’étape courante et le clavier permet de parcourir les champs. La touche Entrée avance dans le parcours ; l’enregistrement est possible à la dernière étape seulement.

Le bouton « Enregistrer la connexion » envoie la configuration complète. Il ne teste pas le jeton et ne déclenche pas de synchronisation. Après création, la fiche permet de tester l’accès puis de synchroniser explicitement.

En cas de refus par le serveur, l’étape contenant la première erreur s’affiche. Les valeurs non sensibles sont conservées. Le jeton n’est jamais renvoyé dans le HTML : après un refus, il faut le saisir de nouveau. Une vérification de tous les champs avant envoi ramène à l’étape d’un champ obligatoire manquant, même s’il se trouve auparavant dans le parcours.

## Intégration technique

Le contrôleur Stimulus `wizard` améliore le formulaire Symfony existant. Les fieldsets sont tous rendus et utilisables sans JavaScript ; le contrôleur masque les étapes après connexion seulement. La validation native est appelée explicitement pour éviter qu’un champ obligatoire masqué bloque le navigateur sans pouvoir recevoir le focus. Les étapes intermédiaires n’envoient aucune requête et n’écrivent aucun brouillon côté serveur ou dans le stockage du navigateur.

Un seul POST final conserve les routes, permissions administrateur, CSRF et validations existantes : 303 après création, 422 après refus. Turbo traite cette réponse complète et reconnecte le contrôleur ; les marqueurs d’erreur Twig choisissent la première étape invalide. Le jeton reste un PasswordType `always_empty`. La modification d’une connexion conserve le formulaire complet afin d’accéder directement aux paramètres à corriger.
