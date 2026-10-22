# Navigation Symfony UX Turbo

Symfony UX Turbo 3.6 et son bundle Stimulus sont compatibles avec PHP 8.4 et Symfony 8.1. Le client Hotwire Turbo 8 est importé explicitement dans le point d’entrée esbuild existant ; aucun second bootstrap Stimulus, AssetMapper ou serveur Mercure n’est ajouté. Les recettes de démonstration et le helper CSRF stateless ne sont pas utilisés : les formulaires existants portent leurs tokens de session dans le corps.

Turbo Drive traite les visites internes et les soumissions HTML. Les écritures existantes conservent leur redirection 303, et les formulaires invalides leur réponse 422. Le bouton soumis est désactivé par Turbo ; les boutons renseignés affichent une indication d’envoi. La barre de progression reprend la couleur Exodia après 150 ms.

La prévisualisation d’issue est un POST consultatif retournant 200 : elle cible le cadre `issue-planning` et peut donc être affichée en place. La confirmation sort du cadre vers la navigation principale ; aucune issue n’est créée par la prévisualisation. Une réponse dépourvue du cadre attendu est affichée à partir de la réponse déjà reçue, sans rejouer le POST.

Les snapshots privés et le préchargement au survol sont désactivés. Les GET de cadres ne consomment pas les messages flash du layout. Connexion/déconnexion restent des soumissions complètes ; la page de connexion impose une visite complète afin qu’une session expirée puisse quitter un cadre. Sans JavaScript, les mêmes liens, formulaires, validations et protections serveur fonctionnent.

Les fichiers CSS/JS portent `data-turbo-track="reload"` : lorsque leurs URLs versionnées changent au déploiement, Turbo recharge le document. Les URLs de build actuelles restent stables ; un rechargement complet reste nécessaire au premier déploiement de cette intégration ou lorsqu’aucun versionnement d’assets n’est configuré.

Références : [Symfony UX Turbo](https://symfony.com/bundles/ux-turbo/current/index.html), [Turbo Drive](https://turbo.hotwired.dev/handbook/drive), [Turbo Frames](https://turbo.hotwired.dev/handbook/frames). Les versions résolues figurent dans les lockfiles ; les réponses sont toujours HTML et aucune API publique n’est créée.

## Utilisation

Les liens internes remplacent la page avec une barre de progression, sans recharger tout le document. L’aperçu d’une issue apparaît au niveau des anomalies sélectionnées ; la création demande toujours une confirmation explicite. Les protections CSRF et les permissions restent vérifiées par le serveur.
