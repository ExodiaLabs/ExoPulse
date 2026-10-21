# Thème Exodia

Les références dans `design-reference/` restent en lecture seule. Le thème reprend la sidebar sombre, la palette, les cartes et les espacements de la maquette. Les valeurs fictives ne sont pas reprises.

`assets/styles/tokens.css` définit les variables indépendantes ; `theme.css` les expose via `@theme inline` Tailwind v4 ; `components.css` porte les composants génériques et `app.css` les adaptations ExoPulse. Le build local produit `public/build/` (non versionné) : `make frontend` utilise une image Node cohérente pour les dépendances natives. Éviter de mélanger les installations Node Alpine et celles de l’hôte dans le même `node_modules`.

Les composants Twig partagés composent navigation, bouton de thème et indicateurs. Le contrôleur Stimulus conserve le choix clair/sombre dans le navigateur et supporte un stockage indisponible. Le dashboard distingue les nombres réels d’applications et les scores inconnus. Les écrans vides guident le raccordement sans afficher de données fictives.

Les écrans métier sont authentifiés. Les validations HTTP et navigateur sont décrites dans [validation.md](validation.md).

## Hiérarchie et composants de présentation

Les variantes `exo-stat`, `exo-welcome`, `exo-badge`, `exo-filter-bar` et `exo-section-heading` utilisent les tokens Exodia existants. La navigation latérale reste fixe dans le défilement sur écran large et devient une liste horizontale sur mobile. Les transitions respectent `prefers-reduced-motion`. Les icônes sont des SVG décoratifs statiques centralisés dans `components/icons.html.twig`, associés à des libellés textuels. Aucun service distant ni police externe n’est ajouté.

`exo-fieldset` et `exo-form-grid` structurent les formulaires Symfony sans modifier validation, noms de champs ni CSRF. Les grilles réduisent leur nombre de colonnes sur petit écran ; les tableaux restent dans des conteneurs à défilement horizontal.
