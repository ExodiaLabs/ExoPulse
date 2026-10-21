# Paramètres des analyses

`/settings` est réservé à ROLE_ADMIN. Le formulaire vérifie CSRF et bornes serveur avant persistance ; chaque enregistrement produit une entrée d’audit sans valeurs sensibles.

Fréquence globale : 24 heures par défaut, entre 1 et 720. Fraîcheur : 7 jours, entre 1 et 365. Conservation des scans terminés : 365 jours, entre 1 et 3650. La purge est une commande explicite ; modifier le nombre ne supprime pas les données immédiatement.

Les cinq poids de score vont de 0 à 100 : fin de support 40, fin de maintenance 10, majeure 10, mineure 3, patch 1. Les plafonds du moteur restent applicables et la couverture n’est pas transformée en pénalité.

Les catalogues externes sont désactivés par défaut. Choisir Oui autorise l’envoi des coordonnées des packages aux registres publics ainsi que les calendriers de support ; aucun code source n’est envoyé. Le réglage s’applique aux prochains scans, sans réécrire les preuves historiques.
