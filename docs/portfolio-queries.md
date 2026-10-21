# Consultation du parc

Les requêtes de catalogue et de constats sont paginées par 25 éléments, avec compte total et bornage de la page. Les tris sont choisis dans une liste fermée ; les filtres sont liés comme paramètres SQL. Les relations nécessaires au rendu sont chargées par jointure.

Le statut découle du score : sain >=80, attention de 50 à 79, critique <50 ; score absent = inconnu. L’archive et l’inaccessibilité sont des filtres distincts. Aucun score inconnu n’est ramené à zéro. Les courbes affichent la moyenne des scans quotidiens effectivement enregistrés avec leur nombre d’observations ; elles ne prétendent pas être des snapshots exhaustifs du parc.

Les paramètres persistés ont des valeurs par défaut documentées et un identifiant unique. Leur lecture n’écrit rien en base. L’audit enregistre acteur, action et identifiant cible, sans jeton ni contenu HTTP.
