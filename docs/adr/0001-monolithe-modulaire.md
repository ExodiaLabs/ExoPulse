# 0001 — Conserver un monolithe modulaire

- Statut : accepté
- Date : 2026-10-21
- Décideur : CTO ExoPulse, dans le cadre de la mission autonome

## Contexte

Un parc de plusieurs centaines de dépôts exige des analyses asynchrones mais ne justifie ni microservices ni SPA. La mission impose une séparation entre règles métier et fournisseurs Git.

## Décision

Symfony 8.1/PHP 8.4, Twig/Symfony UX, PostgreSQL/Doctrine et Messenger constituent un monolithe modulaire. Domain porte les règles, Application les cas d’usage, Infrastructure les adaptateurs et Presentation les contrôleurs. Créer les dossiers au rythme des besoins. Un dépôt représente une application au MVP ; identité stable et unicité connexion/ID externe.

## Conséquences

Le domaine ne dépend pas des API Git. Les contrats ciblent fournisseurs et parseurs interchangeables ; pas d’interface par classe. Un seul artefact applicatif sert HTTP et worker. Le schéma métier reste à livrer dans les issues d’inventaire. Symfony 8.1 doit être mis à niveau avant janvier 2027.

## Alternatives écartées

Des microservices ou une SPA exigeraient plusieurs déploiements et contrats sans bénéfice démontré. Une entité Application distincte deviendra utile si le produit doit regrouper plusieurs dépôts.
