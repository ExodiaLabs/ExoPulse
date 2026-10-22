# Lecture du patrimoine technique

`TechnicalPortfolio` fournit un modèle de lecture PostgreSQL indépendant du score de santé. Il exploite le JSON `results.technologies` du dernier scan associé au repository et agrège les constats actifs de toutes ses branches. Les constats résolus sont ignorés. Il ne crée ni projection persistante, ni nouvelle table, ni résultat d'analyse. Le score historique n'est jamais modifié pour établir un classement technique.

## Contrats

- `repositories(filters, page)` renvoie les repositories, le total, la page bornée, le nombre de pages et les diagnostics indexés par identifiant interne du repository.
- `diagnosticsFor(repositoryIds)` renvoie les mêmes diagnostics par lot ; les identifiants invalides sont ignorés et les lots de plus de 500 sont divisés en requêtes bornées.
- `stats()` compte des **repositories distincts** : `total`, `legacyConfirmed`, `legacySuspected`, `unsupported`, `aging`, `outdated`, `unknown`.
- `technologyInventory()` renvoie `{name, projects, eolProjects}` ; plusieurs observations ou constats de la même technologie ne comptent qu'une application. Les noms sont regroupés sans distinction de casse et les constats de fin de support sont associés au même nom de technologie.
- `facets()` renvoie les noms de technologies observés, les langages et les familles de frameworks. Les anciens scans non référencés par `latestScan` ne contribuent pas à l'inventaire ni aux facettes.

Les diagnostics exposent `risk`, `legacy`, leurs libellés français, `eolCount`, `maintenanceCount`, `outdatedCount`, `majorCount`, `oldestFindingAt`, `lastVerifiedAt`, `oldestFindingAgeDays`, `eolDurationDays`, `priorityReasons`, `partial` et les technologies du dernier scan. Les comptes de diagnostics comptent des constats ; les statistiques globales comptent des applications. Une date absente ou invalide reste `null`, jamais une date estimée. Les dates ISO sont validées par `pg_input_is_valid` (PostgreSQL 17) avant tout cast dans un `CASE`, puis contrôlées en PHP pour le rendu. Les valeurs invalides sont écartées avant les agrégats ; elles ne masquent pas une autre date valide. Les dates de vérification sont comparées chronologiquement avec leur fuseau.

`lastVerifiedAt` est la dernière date de vérification fournie par les sources des constats ou des observations de support ; ce n'est pas simplement l'heure de lancement du scan. Les sources et dates détaillées demeurent dans les constats/observations d'origine. L'âge d'un constat part de sa première détection ; la durée hors support part de la plus ancienne date de fin de support disponible. Ces deux mesures ont des sens différents.

## Risque, legacy et confiance

Les constats EOL reconnus sont `end_of_support`, `eol`, `end_of_life`, `unsupported`. La maintenance active échue utilise `end_of_maintenance` ou `maintenance`. Les mises à jour utilisent `outdated`, `major`, `minor`, `patch`. Seuls les constats dont la confiance est au moins 50 contribuent aux risques identifiés ; une confiance absente, invalide ou inférieure reste une information insuffisamment vérifiée.

| Risque | Règle |
| --- | --- |
| `critical` | Framework Symfony, React ou Vue hors support avec confiance au moins 80. Le moteur attribue cette confiance à une version résolue plutôt qu'à une simple intention déclarée. |
| `high` | Autre EOL connu, mise à jour majeure confirmée, ou maintenance active échue d'un framework avec confiance au moins 80. |
| `moderate` | Autre maintenance active échue connue. |
| `low` | Analyse complète et vérifiée, sans risque supérieur connu. |
| `unknown` | Informations insuffisantes pour conclure à un risque global faible et aucun signal connu plus fort. |

Une analyse complète exige un scan `completed`, une couverture et une confiance de 100, au moins une observation de technologie, aucune erreur et aucun constat inconnu ou de confiance insuffisante. Un scan partiel qui ne comporte qu'un correctif ou une mise à jour mineure ne devient donc **pas** un projet à faible risque : le compteur d'obsolescences est visible mais le risque global reste inconnu. Un EOL connu conserve en revanche sa priorité élevée/critique même si le score est absent, y compris sur un scan partiel.

Le legacy est `confirmed` lorsqu'un framework hors support est confirmé avec une confiance de 100. Il est `suspected` pour un autre EOL de confiance au moins 50, notamment un runtime déduit d'un Dockerfile ou d'un manifest. PHP et Node.js ne deviennent jamais « legacy confirmé » sur cette seule inférence : la version déployée doit être vérifiée. Sans EOL, un état complet donne `none` (« Aucun legacy confirmé ») ; un état incomplet donne `unknown`.

`unsupported` compte les applications avec au moins un EOL connu. `aging` compte celles dont une maintenance active est terminée ; ce compteur n'est pas un seuil d'ancienneté du scan. `outdated` compte celles ayant une mise à jour disponible connue. Les compteurs se recoupent et ne doivent pas être additionnés. `unknown` concerne le risque global indéterminé, séparément du filtre de score inconnu.

## Filtres et tris

Les filtres `q`, `connection`, `status`, `technology`, `language`, `framework`, `version`, `risk`, `legacy` sont appliqués en SQL **avant** la pagination à 25 repositories. Les valeurs du tri sont limitées à `name`, `score`, `recent`, `criticality`, `oldest`, `urgency` ; une valeur inconnue revient au nom. `criticality` utilise le risque explicable, jamais `latestScore`. `urgency` utilise ce même ordre, puis la première détection la plus ancienne. Les risques inconnus restent explicitement inconnus et sont placés après les classes de risque identifiées. Les dates/scores absents se trouvent en fin de leurs tris respectifs ; l'identifiant interne stabilise chaque ordre.

Les filtres de santé existants sont conservés : score frais sain (80+), attention (50–79), critique (<50), score inconnu ou périmé, ancienneté (`stale`), archive et inaccessibilité. Le seuil de fraîcheur vient de `ConfigurationStore.staleDays`. Un filtre `status=unknown` peut donc afficher un risque technique critique connu : l'absence de score récent n'annule pas un constat EOL.

Les technologies, langages et frameworks sont recherchés sans distinction de casse. Symfony et ses composants/paquets préfixés `Symfony ` ou `symfony/` appartiennent à la famille Symfony et au langage PHP. PHP/Composer correspondent à PHP ; Node.js, JavaScript, React, Vue et les outils npm/pnpm/Yarn à JavaScript ; TypeScript demeure un langage distinct.

Les facettes `technology`, `framework` et `language` sont recherchées indépendamment dans les observations du repository : React avec TypeScript, ou PHP avec Symfony, peuvent donc être combinés. Le filtre `version` reste lié à une seule observation correspondant à la facette sélectionnée la plus précise, par priorité **technologie, puis framework, puis langage**. Sans facette, il recherche toute observation. Ainsi « React 18 + TypeScript 5 » correspond à framework React / langage TypeScript / version 18, mais pas version 5. « PHP 7.4 + Symfony 4.4 » correspond à technologie PHP / framework Symfony / version 7.4, mais pas version 4.4. Un repository « PHP 4.4 + Symfony 8 » ne correspond toujours pas à framework Symfony / version 4.4.

La version est une recherche syntaxique de préfixe dans les versions **résolues ou déclarées** de l’observation liée à la facette prioritaire. Une contrainte `^4.4` peut donc être trouvée sans lockfile ; ce résultat ne prouve pas qu'une version 4.4 est installée. Il ne s'agit pas d'une résolution Semver ou d'une évaluation de plage. Les bornes numériques empêchent de confondre `4.4` avec `14.4` ou `4.40`. Les métacaractères sont échappés et tous les filtres sont liés comme paramètres SQL ; seuls les tris autorisés et limites entières calculées sont interpolés.

## Chargement et limites

Une requête compte le périmètre, une autre sélectionne la page SQL et ses diagnostics, puis une requête ORM récupère les repositories et joint leurs connexions et derniers scans. L'ordre de la page SQL est conservé. L'accès à ces relations ne déclenche pas de requête par application. La lecture du seuil de configuration peut ajouter une requête constante. Les facettes, compteurs et inventaires sont agrégés en SQL sans charger tous les scans/constats comme entités PHP.

Il s'agit d'une lecture en temps réel des données déjà conservées : la requête ne contacte pas les fournisseurs Git ni les registres. Elle ne remplace ni un scan récent ni une validation de compatibilité. Les labels de risque et de legacy sont des règles de priorisation documentées, distinctes d'une vulnérabilité confirmée. Les constats actifs d'autres branches restent pertinents pour le repository ; l'inventaire de technologies représente seulement le dernier snapshot associé.

## Tests

`tests/Integration/TechnicalPortfolioTest.php` utilise PostgreSQL réel et des fixtures transactionnelles. Il couvre le score absent avec EOL partiel, les seuils de confiance 79/80/100, l'inférence runtime, les métadonnées mal formées, les versions déclarées sans lockfile, la borne 4.4/14.4, les associations de versions à la bonne technologie, les injections de filtre, l'exclusion des anciens scans et constats résolus, la déduplication de l'inventaire et la fraîcheur configurable.

Le scénario de 500 applications comporte 30 projets critiques en fin de catalogue : le filtre retrouve ces 30 projets avant pagination et renvoie correctement cinq lignes en dernière page. Le compteur SQL vérifie trois requêtes pour cette page, y compris après lecture des connexions et derniers scans, afin de détecter un N+1.
