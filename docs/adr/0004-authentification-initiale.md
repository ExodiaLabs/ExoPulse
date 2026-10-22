# 0004 — Configurer un administrateur initial par hash externe

- Statut : accepté pour les fondations monoorganisation
- Date : 2026-10-21
- Décideur : CTO ExoPulse

## Contexte

Les écrans doivent être protégés avant toute manipulation de credentials Git. Aucun fournisseur SSO ni cycle de gestion des comptes n’est imposé.

## Décision

Utiliser le fournisseur mémoire Symfony avec identifiant et hash injectés par configuration, sans mot de passe actif par défaut. Exiger une session pour toute page métier, CSRF pour login/logout, et limiter les tentatives. Le rôle administrateur hérite de ROLE_USER ; les permissions fines viendront avec les mutations correspondantes.

## Conséquences

Aucune inscription publique ni table utilisateur prématurée. L’exploitant doit configurer le hash avant la première connexion. Ce mécanisme ne remplace pas la gestion de plusieurs personnes, SSO ou révocation centralisée. Rotation : remplacer le hash et purger les sessions actives ; voir authentication.md.

## Alternatives écartées

Un compte de démonstration introduirait un accès prévisible. Un serveur d’identité supplémentaire demanderait une exploitation non justifiée pour le socle. Un ADR ultérieur pourra remplacer ce mécanisme.
