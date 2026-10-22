# Paramètres par sections

Les paramètres sont regroupés en trois onglets :

- **Rythme et conservation** : fréquence des analyses, fraîcheur et durée de conservation.
- **Confidentialité** : autorisation d’enrichir les dépendances auprès des catalogues publics.
- **Score de santé** : pénalités appliquées aux constats confirmés.

Changer d’onglet conserve la saisie et n’enregistre rien. Le bouton « Enregistrer les paramètres », commun aux trois sections, enregistre l’ensemble des réglages. Les paramètres prennent effet pour les prochains traitements.

Au clavier, Tab atteint l’onglet sélectionné puis son contenu. Les flèches gauche/droite parcourent les onglets ; Début et Fin sélectionnent le premier et le dernier. Le focus visible et l’état sélectionné identifient la section active.

Un champ obligatoire vide ouvre sa section avant d’afficher la validation du navigateur. Après un refus serveur, la première section contenant une erreur est ouverte ; les valeurs saisies restent affichées. Sans JavaScript, les trois sections et le bouton d’enregistrement restent disponibles dans le formulaire complet.

## Intégration technique

Le contrôleur Stimulus `tabs` améliore le formulaire Symfony existant. Les panneaux ne sont masqués qu’après connexion du contrôleur. Les boutons portent `role="tab"`, `aria-controls` et `aria-selected` ; les panneaux reçoivent `role="tabpanel"`, un libellé associé et un arrêt clavier. Un seul onglet figure dans l’ordre de tabulation.

La validation native est pilotée explicitement avant l’envoi pour révéler le premier champ invalide, y compris dans un panneau masqué. Le validateur Symfony reste autoritaire et les marqueurs Twig `data-panel-invalid` orientent le contrôleur après une réponse 422. Un enregistrement réussi conserve sa redirection 303 et Turbo reconnecte les contrôleurs sur la réponse. CSRF et droits administrateur restent vérifiés côté serveur.

Aucune route, API, migration, écriture intermédiaire ou stockage navigateur n’est ajouté. La sélection d’onglet reste locale à la page et revient au premier onglet après un chargement normal.
