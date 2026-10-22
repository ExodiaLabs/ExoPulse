# Lecture statique des manifests

Les parseurs reçoivent des contenus de fichiers déjà lus à un commit fixé. Ils ne lancent aucun processus, installation, script de package ou requête HTTP. Leurs résultats décrivent les fichiers du dépôt ; ils ne prouvent pas la version exécutée en production.

## Versions et dépendances

`ComposerParser` lit les sections racine `require` et `require-dev`, puis les listes `packages` et `packages-dev` du lockfile. Il conserve les anciennes versions sans exiger un runtime moderne dans le dépôt analysé. Les contraintes PHP restent des observations de runtime ; les packages portent écosystème, nom, contrainte déclarée, résolution, caractère direct, scope et fichier source.

Sans lockfile, une contrainte, même exacte, ne devient pas une version installée. Un package verrouillé absent du manifeste racine reste transitif. Les scopes prod et dev restent distincts ; une présence en production prévaut sur une déclaration dev répétée.

`JavascriptParser` lit dependencies, optionalDependencies, devDependencies et peerDependencies. `JavascriptLockParser` distingue les résolutions directes et imbriquées. Les locks pris en charge sont npm versions 1/2/3, pnpm séries 5/6/9 et Yarn Classic/Berry. Un sélecteur racine Yarn doit correspondre à la contrainte déclarée pour confirmer une résolution directe.

Si plusieurs locks coexistent, la priorité est `npm-shrinkwrap.json`, `package-lock.json`, `pnpm-lock.yaml`, puis `yarn.lock`. Les parseurs ne fusionnent pas plusieurs graphes potentiellement incompatibles.

## Intentions de runtime

`RuntimeDetector` lit `.nvmrc`, `.node-version`, `.php-version`, les contraintes des manifests et les images PHP/Node reconnues dans Dockerfile. Il conserve le fichier source et la confiance de l’observation. Une contrainte large, un alias comme `lts/*` ou un tag dynamique ne prouve aucune version déployée. La version déclarée reste séparée de la résolution ; les versions de frameworks proviennent des packages reconnus.

## Limites

L’inventaire direct porte sur le manifeste racine. Les workspaces et monorepos ne sont pas parcourus exhaustivement. Les références locales, liens et formats inconnus restent inconnus ; un format de lock non pris en charge produit une erreur exploitable par l’orchestrateur. Les parseurs ne résolvent pas les contraintes transitives ou de peer dependencies et ne vérifient ni obsolescence, ni support, ni vulnérabilité.

La lecture réseau, les limites de taille, les états partiels et la conservation du manifeste après erreur de lockfile relèvent du traitement de scan et du moteur d’analyse. Les parseurs JSON limitent leur profondeur de décodage et les parseurs YAML utilisent le mode données, sans instanciation d’objets.

## Vérification

`tests/Analysis/ManifestParsersTest.php` teste ces contrats directement, sans moteur d’analyse ni catalogue externe : legacy Composer, séparation déclarée/résolue, dépendances directes/transitives, locks absents, formats pnpm/Yarn et intentions de runtime.
