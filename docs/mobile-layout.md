# Utiliser ExoPulse sur mobile

Sous 768 px, le bandeau ExoPulse affiche un bouton « Menu ». Il ouvre les liens de navigation et la déconnexion ; Échap depuis le menu le referme et rend le focus au bouton. Les liens sont disposés sur deux colonnes pour rester visibles sans défilement horizontal. Sur un écran plus large, la navigation latérale reste affichée.

Les titres et espacements sont plus compacts sur téléphone. Les champs utilisent une taille de texte de 16 px ; les boutons, le menu et les onglets conservent une hauteur minimale de 44 px. Les filtres s’affichent sur une colonne. Les noms longs peuvent revenir à la ligne sans être tronqués.

Les tableaux gardent toutes leurs colonnes : faites défiler leur zone horizontalement pour les consulter. Chaque tableau se trouve dans une région nommée, accessible par Tab et défilable au clavier. La page elle-même garde sa largeur de lecture.

Sans JavaScript, tous les liens du menu restent affichés. Les formulaires complets conservent leur fonctionnement habituel ; le zoom du navigateur reste autorisé.

## Intégration technique

`assets/styles/mobile.css` centralise les adaptations et réutilise les tokens Exodia. Le contrôleur Stimulus `navigation` gère le panneau associé à `aria-controls` et synchronise `aria-expanded` avec le breakpoint de 768 px. Il retire son écouteur MediaQuery à la déconnexion. Le focus est déplacé vers un élément visible lors d’un changement de breakpoint.

Le menu est un panneau en ligne, sans superposition ni piège de focus. Turbo reconstruit le contrôleur lors d’une visite ; aucun état de navigation n’est conservé dans le stockage du navigateur. Les restrictions de rôle, le POST de déconnexion et son CSRF restent rendus côté serveur.

Le défilement horizontal est limité aux conteneurs de tableaux, sans masquer globalement les débordements de la page. Les adaptations ne changent ni données ni actions métier.
