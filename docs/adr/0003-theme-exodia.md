# 0003 — Porter les références Exodia vers Tailwind v4

- Statut : accepté
- Date : 2026-10-21
- Décideur : CTO ExoPulse

## Contexte

La maquette validée utilise Tailwind v3 par CDN et des données fictives. La mission exige Tailwind v4 et la conservation des fichiers de référence.

## Décision

Conserver palette, sidebar, cartes et organisation des écrans. Séparer tokens CSS indépendants, mapping @theme natif, composants génériques et styles ExoPulse. Composer les vues Twig et utiliser Stimulus pour l’interactivité locale. Compiler les assets à partir de lockfiles, sans CDN ni script de simulation.

## Conséquences

Le thème peut être réutilisé indépendamment des données métier. Une métrique indisponible affiche « — », jamais un chiffre fictif ou un score zéro. Ajuster uniquement les contrastes/accessibilité si nécessaire. Aucun test navigateur visuel n’est revendiqué à ce stade.

## Alternatives écartées

Recopier le prototype v3 prolongerait une dépendance CDN et ses simulations. Réinventer les écrans ferait perdre la référence validée sans besoin fonctionnel.
