# Connexions fournisseurs

La gestion des connexions est réservée au rôle administrateur. Une connexion possède une identité interne indépendante de son nom et peut partager son fournisseur ou son instance avec d’autres connexions.

Les formulaires de création et de modification utilisent Symfony Form, ses contraintes et sa protection CSRF. Les listes d’organisations et de repositories sont saisies avec des virgules. Les branches sont également séparées par des virgules ; un champ vide sélectionne la branche par défaut du repository. La fréquence est comprise entre 1 et 720 heures.

L’URL doit utiliser HTTPS et appartenir à la liste d’hôtes configurée par l’administrateur du déploiement. Sa validation à l’enregistrement ne déclenche aucun accès réseau. Le jeton est chiffré avec SecretCipher avant persistance et n’est jamais rendu dans le formulaire. Un jeton vide à la modification conserve le secret existant. La clé de chiffrement doit être provisionnée avant toute création.

Les actions de test, synchronisation et désactivation sont des POST protégés par un jeton CSRF lié à la connexion. Le test de connectivité est une requête bornée vers le fournisseur ; il actualise la date, le statut et une erreur sans secret. La synchronisation est placée dans la file Messenger via RequestJobs, qui empêche les doublons concurrents. Une connexion désactivée ne peut pas être synchronisée ; sa réactivation se fait par le formulaire de modification.

Créations, modifications, tests et désactivations écrivent un événement d’audit. La demande de synchronisation est auditée par RequestJobs. Les formulaires invalides répondent HTTP 422 ; les mutations réussies redirigent avec HTTP 303. La liste affiche au plus 1 000 connexions et le détail les 20 dernières synchronisations. La pagination de ces connexions reste une limite à lever si le volume dépasse ce seuil.

Le test HTTP utilise des identifiants synthétiques et une transaction annulée après chaque scénario. Il vérifie le chiffrement, la non-divulgation du secret dans l’édition, sa conservation lorsque le champ reste vide, le rejet CSRF, la désactivation, l’interdiction d’une instance hors liste et le refus du rôle lecteur. Il ne nécessite aucun fournisseur distant.

Le fournisseur et l’URL d’instance sont immuables après création : une autre plateforme exige une nouvelle connexion pour conserver des identifiants externes fiables. La rotation du jeton reste autorisée. Modifier les organisations suspend immédiatement la surveillance des repositories importés jusqu’à une nouvelle découverte. Modifier les motifs d’inclusion ou d’exclusion retire immédiatement les repositories qui ne correspondent plus ; élargir le périmètre requiert aussi une synchronisation pour les réactiver. Toute modification de périmètre remet l’échéance de synchronisation à zéro.

L’édition utilise le même verrou que la synchronisation et une transaction pour enregistrer la configuration, le périmètre et l’audit ensemble. Si une synchronisation est en cours, l’enregistrement est refusé avec un message explicite ; attendez la fin du traitement puis rechargez le formulaire. Aucun changement du formulaire refusé n’est persisté.
