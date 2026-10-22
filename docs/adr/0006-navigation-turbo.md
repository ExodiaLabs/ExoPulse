# 0006 — Navigation progressive avec Turbo

- **Statut** : accepté
- **Date** : 2026-10-21

## Contexte

Les pages Twig doivent rester utilisables sans JavaScript, tout en évitant un rechargement complet pour les interactions courantes. Les traitements métier restent asynchrones dans Messenger.

## Décision

Utiliser Symfony UX Turbo et le client Turbo 8 dans le build esbuild existant. Drive gère la navigation ; les cadres isolent les interactions HTML. Stimulus garde les comportements locaux. Les mutations conservent leurs permissions et protections CSRF côté serveur.

Les snapshots de pages privées et le préchargement spéculatif sont désactivés. L’authentification impose une visite complète. Aucun serveur de diffusion supplémentaire ni API JSON n’est nécessaire.

## Conséquences

Les réponses de formulaires suivent les conventions 303/422 ; une prévisualisation consultative peut retourner 200 dans son cadre. Les erreurs doivent pouvoir quitter un cadre sans rejouer une écriture. Le JavaScript s’installe une fois et les contrôleurs doivent libérer leurs écouteurs et temporisateurs à la déconnexion.

## Alternatives

Une SPA ajouterait une seconde couche de rendu et d’API sans besoin identifié. Mercure reste envisageable si la charge ou le besoin de diffusion serveur justifie un service supplémentaire.
