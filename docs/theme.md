# Thème Exodia

Les références dans `design-reference/` restent en lecture seule. Le thème reprend la sidebar sombre, la palette, les cartes et les espacements de la maquette. Les valeurs fictives ne sont pas reprises.

`assets/styles/tokens.css` définit les variables indépendantes ; `theme.css` les expose via `@theme inline` Tailwind v4 ; `components.css` porte les composants génériques et `app.css` les adaptations ExoPulse. Le build local produit `public/build/` (non versionné) : `npm ci && npm run check && npm run build`.

Les composants Twig partagés composent navigation, bouton de thème et indicateurs. Le contrôleur Stimulus conserve le choix clair/sombre dans le navigateur et supporte un stockage indisponible. Le dashboard affiche « — » pour une mesure indisponible ; aucun score zéro artificiel. Les autres sections initiales indiquent leur indisponibilité.

Cette PR fournit les vues ; leur accès authentifié est raccordé par l’incrément suivant. Aucun parcours navigateur automatisé n’a encore été validé.
