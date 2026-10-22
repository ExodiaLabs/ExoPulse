# Réconcilier une ancienne alerte de support

Une analyse partielle ne constitue pas une preuve de disparition d’un risque. En particulier, un runtime seulement déclaré, un lockfile manquant, une autre branche ou une panne de catalogue ne clôturent pas un ancien constat.

Une exception ciblée permet de corriger les risques fantômes : pour les constats de support uniquement, toutes les observations de la même technologie doivent être revérifiées sur la même branche, avec une version résolue stable, un support connu et une confiance de 100. Une source non vide et des dates valides de vérification/EOL sont obligatoires. Une preuve estimée ou moins forte ne suffit pas. Le nouveau constat remplace alors celui qui n’est plus observé ; les autres constats restent actifs.

Cette règle permet notamment de corriger une ancienne date EOL lorsqu’une source officielle annonce une prolongation, même si le registre d’un package sans rapport est indisponible. Elle ne clôture pas une vulnérabilité et n’assimile jamais une fermeture d’issue à une migration réussie.

`SupportReconciliationTest` vérifie les preuves inconnues, estimées, plus faibles, sur une autre branche ou sans source/date valide. `ScanProcessorTest` conserve la protection contre un score provisoire sur un simple runtime déclaré.
