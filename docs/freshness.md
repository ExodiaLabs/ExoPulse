# Fraîcheur et scores historiques

Le réglage `staleDays` définit la durée pendant laquelle la dernière vérification permet de classer une application comme saine, à surveiller ou critique. La valeur par défaut est de sept jours. Un résultat est frais lorsque sa date est supérieure ou égale à la date courante moins ce délai.

Une date absente ou plus ancienne rend l’évaluation actuelle indisponible. L’application apparaît dans le filtre « Non évalués ou périmés » et dans « À actualiser ». Le nombre total d’applications ne change pas ; le compteur `stale` est un sous-ensemble des évaluations inconnues. Les scores nuls restent également inconnus même lorsque la date est récente.

Les snapshots ne sont pas modifiés : la lecture du catalogue ne retire aucun point à un ancien score. Le catalogue et le détail affichent « À actualiser », la date de vérification et, s’il existe, un ancien score explicitement historique. Une analyse complète récente pourra fournir une nouvelle évaluation.

Les courbes de tendance utilisent uniquement les analyses complètes avec un score disponible. Une analyse partielle est marquée « Provisoire » dans l’historique et son score ne contribue pas aux moyennes de tendance. Cela évite qu’une couverture incomplète apparaisse comme une amélioration confirmée.

L’extension Twig calcule le seuil au premier besoin puis le conserve pour le rendu courant. Elle n’accède pas à la configuration sur un écran de connexion qui n’affiche pas de fraîcheur. Son implémentation ResetInterface renouvelle le seuil entre les requêtes ou traitements réutilisant le conteneur.
