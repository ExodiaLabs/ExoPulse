# Sécurité

## État

L’authentification de session, CSRF et limitation des tentatives sont implémentés : voir [authentication.md](authentication.md). Les revues des fondations ont vérifié refus anonyme, hash vide et CSRF ; elles ne constituent pas un audit des intégrations encore absentes. Le reste de ce document décrit des exigences et procédures cibles. La mise en service avec accès à des dépôts privés exige une revue sécurité exécutée, des tests de permissions et une politique de clés documentée.

## Contrôles attendus

Authentifier tous les écrans métier. Contrôler les mutations côté serveur avec autorisations et CSRF. Décider la matrice lecture/administration/création d'issues avant activation des connexions. Le SSO définitif reste ouvert ; aucun mot de passe de démonstration ne convient à la production.

Chiffrer les tokens persistés avec une clé externe à PostgreSQL ; ne stocker ni clé ni secret dans Git. Ne jamais réafficher le token ; l'édition permet de le remplacer. Expurger corps HTTP externes, en-têtes d'authentification et traces. Journaliser acteur, action, cible, heure et résultat sans contenu sensible.

Les appels fournisseurs et catalogues suivent une allowlist d'hôtes. Contrôler DNS/IP, schéma, port et chaque redirection pour éviter SSRF et rebinding. Les instances internes exigent une exception d'hôte précise. Vérifier TLS ; ne pas désactiver la validation de certificat.

Limiter tailles de fichiers et réponses, temps réseau et concurrence. Ne jamais exécuter Composer, npm ou scripts issus des dépôts. Échapper les sorties Twig et traiter manifests/lockfiles comme des entrées non fiables.

## Rotation cible

1. Émettre un nouveau token à permissions minimales.
2. Remplacer le secret via l'administration sécurisée et tester l'accès.
3. Vérifier une synchronisation puis révoquer l'ancien token.
4. Consigner l'action sans valeur de secret.

La rotation de clé de chiffrement demande un outil de rechiffrement et une restauration testée : à livrer avant production, pas à simuler par changement de variable.

## Données et incidents

Les métadonnées de dépôts et noms d'acteurs peuvent être sensibles. Définir accès, rétention et suppression des historiques/audits avec l'exploitant. En cas de suspicion de fuite : désactiver la connexion, révoquer le token chez le fournisseur, préserver les traces expurgées et appliquer la procédure d'incident de l'organisation, encore à préciser.
