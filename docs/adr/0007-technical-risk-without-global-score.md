# 0007 — Distinguer risque technique et score global

- **Statut** : accepté
- **Date** : 2026-10-22

## Contexte

Un score exige une couverture et une confiance suffisantes. Les pannes de registres, contraintes ambiguës et dépendances privées rendent de nombreux scans partiels ; elles n’invalident pas une fin de support déjà démontrée. Le classement par score seul masquait ces risques.

## Décision

Conserver le score global et son exigence de qualité. Ajouter un diagnostic de risque fondé sur les constats actifs, indépendant du score, avec legacy confirmé, suspecté et inconnu. Les filtres et statistiques utilisent les dernières observations techniques et les constats persistés via un modèle de lecture PostgreSQL, sans recopier les snapshots dans de nouvelles tables.

Un référentiel de support local, borné, daté et sourcé auprès des éditeurs permet des diagnostics sans divulguer les noms de packages. Ses sources officielles priment sur un agrégateur pour les séries connues ; les absences restent inconnues. Une contrainte déclarée ne devient jamais une version résolue.

## Conséquences

La criticité connue reste visible lors d’un scan partiel. L’absence de preuve ne devient pas un faible risque. Les nombres du patrimoine comptent des projets distincts et les filtres s’appliquent avant pagination. L’entretien du référentiel et sa date de vérification restent explicites ; une nouvelle analyse crée un snapshot historique au lieu de réécrire les anciens.

## Alternatives

Abaisser la confiance nécessaire au score aurait produit des notes rassurantes sur des analyses incomplètes. Une projection persistée de toutes les technologies ajouterait des migrations et un risque de désynchronisation ; les requêtes bornées sur les snapshots existants conviennent au parc de plusieurs centaines de repositories et sont testées avec 500 projets.
