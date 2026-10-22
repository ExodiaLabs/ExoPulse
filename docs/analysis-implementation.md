# Moteur d’analyse implémenté

## Entrée et sortie

`AnalysisEngine::analyse(array $files, bool $refresh = true, array $weights = []): AnalysisResult` reçoit des noms de fichiers et leur contenu. L’appelant fixe branche et commit avant lecture. Aucun parseur ne clone, n’installe ni n’exécute le dépôt.

Le DTO readonly expose `technologies`, `dependencies`, `findings`, `score`, `confidence`, `coverage` et `errors`. Les observations de technologie séparent intention déclarée et version verrouillée. Lorsqu’un calendrier est confirmé, elles ajoutent `eol`, `endOfMaintenance`, `supportSource` et `supportVerifiedAt`, même si la série reste supportée. Une dépendance conserve écosystème, nom, contrainte, résolution, caractère direct, scope, dernières versions stable/compatible, catégorie de mise à jour, source et date de vérification. Les constats portent empreinte, type, sévérité, versions, source, date, éventuelle échéance et confiance.

`refresh=false` désactive les consultations externes. Cette option permet l’inventaire statique sans transmettre de coordonnées de packages. Les versions publiques passent par Packagist/npm lorsque le rafraîchissement est activé ; l’exploitant doit autoriser cette transmission avant de l’activer sur des packages privés. Aucun contenu de fichier n’est envoyé aux registres.

## Formats pris en charge

- Composer : `require`, `require-dev`, `packages`, `packages-dev`, y compris anciens locks. L’absence de lock conserve la contrainte et laisse la résolution inconnue. Les versions de Symfony viennent de `symfony/framework-bundle` ou `symfony/symfony`.
- npm : package-lock versions 1, 2 et 3 ; résolutions imbriquées conservées sans les qualifier de dépendances directes.
- pnpm : locks séries 5, 6 et 9, importer racine et suffixes de peer dependencies. Les résolutions `link:`, `workspace:` et `file:` ne deviennent pas des versions publiées.
- Yarn : format Classic et Berry, sélecteurs racine comparés aux contraintes du manifeste.
- Runtimes : `.nvmrc`, `.node-version`, `.php-version`, `engines.node`, `require.php` et images PHP/Node de Dockerfile. Les tags dynamiques et contraintes larges ne deviennent pas une version déployée.

Les workspaces et monorepos ne constituent pas une analyse exhaustive : seul le manifeste racine fournit les dépendances directes. Si plusieurs lockfiles coexistent, la priorité déterministe est npm, pnpm puis Yarn ; les résolutions ne sont pas fusionnées. Un format inconnu ou malformé produit une erreur partielle, avec conservation du manifeste exploitable. Les fichiers dépassant 2 Mio sont ignorés avec erreur explicite. Les JSON ont une profondeur maximale de 64 ; le parseur YAML utilise le mode données de Symfony sans objets exécutables.

## Sources consultées

Contrats vérifiés le 21 octobre 2026 :

- [API Packagist](https://packagist.org/apidoc) : métadonnées `https://repo.packagist.org/p2/vendor/package.json`, versions publiées.
- [Métadonnées npm officielles](https://github.com/npm/registry/blob/main/docs/responses/package-metadata.md) : clés `versions` du document `https://registry.npmjs.org/package`.
- [API endoflife.date v1](https://endoflife.date/docs/api/v1/) : produit, séries et champs `eolFrom`, `eoasFrom`, `isMaintained`. Il s’agit d’un agrégateur communautaire, pas de l’éditeur du runtime. Les URLs de consultation et dates restent visibles. Les politiques officielles de support sont notamment [PHP](https://www.php.net/supported-versions.php), [Node.js](https://github.com/nodejs/Release#release-schedule) et [Symfony](https://symfony.com/releases).

Le fournisseur de support couvre PHP, Node.js, Symfony et Vue. Une série absente, une date absente ou une contrainte ambiguë conserve un support inconnu. React n’a aucun calendrier supposé. Aucune vulnérabilité n’est inférée d’une obsolescence ; aucune source d’avis de sécurité n’est intégrée dans ce moteur.

## Réseau et fraîcheur

Les requêtes utilisent trois hôtes HTTPS fixes, aucune redirection, timeout de 8 secondes et durée maximale de 15 secondes. Une réponse dépassant 8 Mio est refusée. Les noms de packages passent une validation restrictive et les noms npm à scope sont encodés. Les erreurs excluent les corps externes et leurs valeurs potentiellement sensibles.

Le cache Symfony conserve les versions six heures et les calendriers vingt-quatre heures, avec la date de la récupération réussie. Après HTTP 429 ou 5xx, le client suspend ses appels à l’hôte pendant au moins une minute ; un `Retry-After` numérique est respecté jusqu’à une heure. Ce circuit temporaire vit dans le processus du worker, sans coordination distribuée. Il ne remplace pas la planification des retries du job. Un échec ne génère pas une version récente fictive.

## Politique de santé initiale

La politique initiale est explicite et peut évoluer : poids `end_of_support=40`, `end_of_maintenance=10`, `major=10`, `minor=3`, `patch=1`. Le troisième argument permet de remplacer les poids ; chaque poids est borné entre 0 et 100. Chaque catégorie de support est plafonnée à 60 points et chaque catégorie de mise à jour à 25 points. Le score vaut `max(0, 100 - somme des pénalités plafonnées)`.

La couverture représente le pourcentage de dépendances dont une résolution stable est connue. Pour un dépôt sans dépendance mais avec une technologie détectée, elle vaut 50 ; sans observation, zéro. Une erreur plafonne la couverture à 75. La confiance représente le pourcentage des vérifications de dépendances directes et de support confirmées. Le calcul du score exige une couverture d’au moins 50 et une confiance de 100 : une source manquante donne « non calculable », sans pénalité inventée. La fraîcheur reste exposée par les dates et n’entre pas dans une pénalité cachée.

`latestCompatible` signifie compatible avec la contrainte déclarée de ce package, sans résolution complète des contraintes PHP/Node, dépendances transitives ou peer dependencies. Une suggestion de version récente ou maintenue ne garantit pas la compatibilité de migration. Les constats le précisent. Une fin de support portant sur une intention de runtime demande de vérifier le runtime réellement déployé.

## Vérification

Les tests dans `tests/Analysis/` utilisent des manifests synthétiques et `MockHttpClient`, sans token ni fournisseur réel. Ils couvrent legacy, locks absents/malformés, résolutions imbriquées, pnpm, Yarn, erreurs réseau, cache, provenance, mises à jour majeures/patch, échéances absentes et intentions de runtime. Les résultats exécutés figurent dans le compte rendu de livraison ; leur présence ne remplace pas l’exécution.
