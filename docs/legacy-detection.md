# Détection des applications anciennes

L’analyse lit des manifests et lockfiles sans lancer le code du dépôt. Composer, npm et les autres gestionnaires restent analysables même si leur runtime historique ne peut plus être exécuté sur le serveur.

Les entrées de technologies distinguent `kind=framework`, `component`, `runtime`, `language` et `package_manager`. `symfony/framework-bundle` et `symfony/symfony` indiquent Symfony. Une liste explicite de composants du cœur, dont `symfony/http-kernel`, est présentée sous `Symfony (composants)`. Une observation représentative par série conserve la version et le package source ; toutes les dépendances restent consultables séparément. Une série déjà attestée par le framework ne génère pas une seconde technologie de composants. Leur présence ne prouve pas l’installation du framework complet. Les bundles tiers, Flex et polyfills ne sont pas assimilés au cycle du framework.

Un manifeste Composer constitue un indice de PHP (`kind=language`, confiance 60), un manifeste npm de JavaScript et une dépendance TypeScript du langage/outillage TypeScript. Ces indices ne prouvent ni la répartition du code source ni le runtime déployé. Les contraintes PHP, fichiers de versions et images Docker restent des intentions de runtime, toujours distinctes des versions résolues des dépendances.

`declared` conserve la contrainte ; `resolved` vient uniquement du lockfile. Les dates de support s’appuient sur une sélection séparée :

- Une version exacte ou une série explicite permet une consultation directe.
- `4.4.*`, `4.4.x` ou `~4.4.0` désignent une série déclarée : résultat estimé, confiance plafonnée à 60.
- `^x.4` Symfony, depuis le majeur 3, désigne sa dernière série mineure : la consultation est estimée avec confiance plafonnée à 60. Cette règle ne s’applique pas aux carets qui commencent à une autre mineure.
- `^6.0` ou `^7.0` Symfony, `^7.1` PHP, `>=7.1`, unions et contraintes dynamiques restent inconnus. Par exemple, `^6.0` peut sélectionner 6.4 : attribuer l’EOL de 6.0 serait trompeur.

Une estimation ne remplit jamais `resolved`, porte `supportStatus=estimated`, `supportConfidence=60` au maximum et un intitulé explicite dans le constat. Un résultat vérifié porte `supportStatus=known`; une absence de données porte `unknown`. L’indice de support est indépendant de la confiance globale de l’analyse et du score. Les vérifications de versions récentes non autorisées restent inconnues : la présence d’un constat EOL local ne transforme pas l’analyse en score entièrement vérifié.

Les sources, leur fraîcheur et leur précision accompagnent les dates. Les recommandations ne garantissent aucune compatibilité de migration. Une fin de support n’est jamais présentée comme une vulnérabilité confirmée.
