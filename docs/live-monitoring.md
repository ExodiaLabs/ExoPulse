# Suivre les traitements sans recharger la page

L’historique global des analyses, l’historique d’une application et l’activité d’une connexion actualisent leur tableau toutes les cinq secondes lorsqu’un traitement visible est en attente, en cours ou en nouvelle tentative. L’actualisation s’arrête lorsque la liste ne contient plus ces états. Le contrôle porte sur les traitements affichés dans la page courante, pas sur les autres pages de l’historique.

Chaque zone est un Turbo Frame identifié de manière stable. Le chargement initial contient déjà les résultats : aucune requête supplémentaire n’est lancée à l’ouverture d’un historique terminé. Stimulus utilise ensuite l’URL courante, paramètres et pagination compris, pour demander une page HTML normale ; Turbo extrait seulement le cadre correspondant. Aucune route JSON ni action de mutation supplémentaire n’est exposée.

Les requêtes ne se superposent pas. L’actualisation automatique attend lorsque l’onglet est masqué, le navigateur hors ligne, un formulaire actif ou en cours d’envoi, ou le cadre déjà occupé. Un délai maximal de trente secondes interrompt une requête bloquée. Les timers et écouteurs sont retirés lors du départ de la page. Un échec affiche un message sans contenu du fournisseur et arrête l’automatisme jusqu’à une nouvelle tentative.

Le lien « Actualiser » permet de relancer la lecture manuellement. Sans JavaScript, il recharge la page par une requête GET classique et la pagination reste utilisable. Les libellés d’état, dates et erreurs restent présents dans le HTML rendu par Symfony.

Sur une fiche connexion, seule la section d’historique est remplacée. Le résumé, les actions POST, leurs jetons CSRF et les messages de confirmation restent à l’extérieur du cadre. Les liens vers une autre application sortent du cadre ; les changements de page de l’historique conservent leur navigation dans l’URL du navigateur. Les actualisations automatiques ou manuelles utilisent ensuite Turbo.visit avec l’action replace : elles remplacent l’entrée courante et ne créent pas de faux retours dans l’historique du navigateur.

Les données et protections d’accès restent celles des contrôleurs existants. L’actualisation ne lance ni synchronisation, ni analyse, ni création d’issue. Les traitements sont toujours exécutés par Messenger.

Références : [Turbo Frames](https://turbo.hotwired.dev/reference/frames) et [événements Turbo](https://turbo.hotwired.dev/reference/events).

Lors d’une panne réseau, Turbo peut rejeter sa promesse interne même après la prise en charge de son événement d’erreur. Le contrôleur conserve les objets Error exacts de ces requêtes déjà signalées à l’utilisateur et empêche uniquement leur seconde notification comme rejet non géré. Les autres erreurs JavaScript continuent de remonter normalement. Cet écouteur est retiré au départ de la page.
