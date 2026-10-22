# Lire le patrimoine technique

Le catalogue permet de combiner une connexion, un langage, un framework, une technologie et une version observée avec les filtres de risque et de legacy. La saisie d’une version utilise le même envoi différé que la recherche ; les listes déclenchent le filtrage immédiatement. Le formulaire reste utilisable sans JavaScript avec son bouton de recherche. Les facettes décrivent le même projet et peuvent correspondre à des observations différentes, par exemple React et TypeScript. La version reste associée à la technologie sélectionnée en priorité, sinon au framework, sinon au langage : une version de PHP ne devient pas une version de Symfony.

Le score de santé, sa fraîcheur, la couverture et la confiance restent séparés du diagnostic d’obsolescence. Un score indisponible ne signifie ni absence de risque ni score nul. Les colonnes de risque et de legacy affichent les qualifications du moteur, y compris les informations inconnues ou les analyses partielles. Les tris par criticité, ancienneté des constats et urgence facilitent la préparation des migrations.

Le tableau de bord met en premier les projets distincts inventoriés, le legacy confirmé ou suspecté, les technologies hors support et les dépendances obsolètes. Les scores disponibles figurent dans un résumé secondaire : zéro score critique ne signifie pas zéro application hors support. Le legacy confirmé couvre les frameworks et les composants structurants confirmés hors support. Le tableau de bord compte des applications distinctes pour chaque indicateur technique. Les catégories peuvent se recouper. « Maintenance active terminée » signifie qu’un constat de fin de maintenance existe ; ce n’est ni l’âge du scan ni nécessairement une fin de support de sécurité. L’inventaire des technologies compte chaque application une fois par technologie.

Le détail d’une application présente les motifs de priorité, les premiers constats, leur ancienneté et la dernière vérification disponible. Les constats actifs restent la source des recommandations et de la préparation manuelle des issues. Une obsolescence ne constitue pas une vulnérabilité confirmée.

La page des dépendances distingue la contrainte déclarée de la version résolue dans les fichiers du repository. Aucune ne prouve la version déployée en production. Les dates de maintenance et de support, leur provenance, leur précision et la confiance sont affichées lorsqu’elles sont disponibles. Une date connue seulement au mois est représentée en fin de mois et explicitement signalée. Une information absente reste inconnue ; elle ne vaut pas confirmation de support.

## Organisation technique

Le composant Twig `components/technical_diagnostic.html.twig` est partagé entre le catalogue, les priorités et les détails. Il consomme le diagnostic calculé côté serveur sans recalculer de risque dans les vues. Les filtres utilisent les routes Twig et les cadres Turbo existants, sans nouvel endpoint ni dépendance frontend. Les maquettes et le thème Exodia de référence restent inchangés.

## Constats d’une application — évolution du 22 octobre 2026

La fiche application présente les constats actifs sous forme de cartes, avec gravité, type, confiance, versions actuelle/cible et première détection. La provenance reste consultable dans « Source et date de vérification ». Les constats critiques précèdent les avertissements puis les informations.

La liste défile dans une zone dédiée, accessible au clavier. Chaque lot contient au plus 20 constats. Atteindre le bas charge la suite sans effacer les sélections : « Prévisualiser une issue » prend en compte les cases cochées dans tous les lots chargés. Aucune issue n’est créée par le défilement. En cas d’échec, le lien de chargement permet de réessayer. Sans JavaScript, ce lien ouvre le lot suivant ; les sélections ne sont alors pas conservées entre les pages.

### Fonctionnement technique

`GET /applications/{id}?findingsPage=N` conserve l’authentification existante et rend une page HTML complète pour la navigation classique. Avec l’en-tête `Turbo-Frame: findings-page-N`, il renvoie le fragment correspondant. Les frames imbriquées à chargement différé ajoutent chaque lot en conservant les précédents. Les cases utilisent l’association HTML `form` avec le formulaire de prévisualisation ; aucun état sensible n’est stocké dans le navigateur. Le contrôleur Stimulus ne gère que les erreurs et la relance du chargement Turbo.

La requête Doctrine filtre les constats non résolus du seul repository demandé et ordonne par gravité, première détection et UUID. La pagination bornée remplace la limite fixe de 200. Les sources et titres sont échappés par Twig. Si une analyse modifie la liste pendant le défilement, actualiser la fiche pour reprendre une vue cohérente : la pagination ne constitue pas un snapshot transactionnel entre requêtes.
