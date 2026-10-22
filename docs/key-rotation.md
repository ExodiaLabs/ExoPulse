# Rotation de la clé des identifiants Git

`app:security:rotate-encryption-key` déchiffre les tokens persistés avec la clé actuellement injectée dans `SecretCipher`, vérifie leur rechiffrement avec la nouvelle clé puis applique les changements dans une transaction PostgreSQL unique. La commande ne modifie pas les tokens chez GitHub/GitLab. Pour renouveler un token fournisseur, utilisez le formulaire de connexion puis révoquez l’ancien token chez le fournisseur.

## Prérequis obligatoires

1. Planifiez une maintenance et arrêtez le serveur web, tous les workers et le planificateur. Empêchez leur redémarrage automatique pendant la procédure. Un processus utilisant encore l’ancienne clé ne doit pas rester actif après la rotation.
2. Sauvegardez PostgreSQL et vérifiez la restauration sur une base isolée. Sauvegardez séparément l’ancienne clé avec les mêmes restrictions que les secrets de production. Conservez cet ensemble tant que les sauvegardes associées peuvent nécessiter une restauration.
3. Préparez un fichier local contenant une nouvelle clé de 32 octets encodés en base64. Utilisez des permissions 0400/0600 et un stockage de secrets approuvé. Ne collez jamais sa valeur dans un argument, un ticket ou un journal. Le fichier doit être lisible par le processus de maintenance.

La commande vérifie le format de la nouvelle clé avant toute mutation. Elle ne peut pas constater que votre sauvegarde est restaurable ou que tous les processus sont arrêtés : l’exploitant doit accomplir ces prérequis. Le verrou de table bloque les écritures concurrentes accidentelles sur les connexions ; il ne remplace pas la maintenance.

## Procédure

Lancez les commandes dans un conteneur PHP ponctuel de la même version applicative, avec accès à PostgreSQL, à **l’ancienne** configuration de secrets et au fichier local de nouvelle clé. Ne démarrez pas de worker dans ce conteneur. Les chemins suivants sont des exemples, à adapter au montage de secrets de l’installation.

```bash
php bin/console app:security:rotate-encryption-key --new-key-file=/run/secrets/exopulse-next-key
php bin/console app:security:rotate-encryption-key --new-key-file=/run/secrets/exopulse-next-key --execute
```

La première commande vérifie tous les enregistrements et le nouveau chiffrement sans modifier les données ni créer d’audit de rotation. La seconde rechiffre effectivement les enregistrements et consigne un audit sans clé ni token. La sortie affiche uniquement le nombre de connexions vérifiées ou rechiffrées. Un enregistrement corrompu ou illisible avec l’ancienne clé annule l’ensemble des modifications de la rotation ; corrigez la cause avant de recommencer.

Après réussite, maintenez le service arrêté. Remplacez `EXOPULSE_ENCRYPTION_KEY` dans le stockage de secrets par la valeur du nouveau fichier, sans l’afficher. Redémarrez l’application, les workers et le planificateur avec la nouvelle configuration. Testez une connexion Git et un traitement. La commande ne modifie pas automatiquement les fichiers `.env` ou le gestionnaire de secrets.

Supprimez la copie temporaire de la nouvelle clé selon les pratiques du stockage utilisé, puis protégez sa copie durable. Ne supprimez pas les clés requises pour restaurer les anciennes sauvegardes.

## Retour arrière

Une erreur de validation ou de déchiffrement avant le commit annule la transaction : les tokens conservent l’ancien chiffrement. Si la connexion à PostgreSQL se coupe pendant le commit, son résultat peut être incertain : maintenez l’arrêt du service et vérifiez quelle clé déchiffre les enregistrements, ou restaurez la sauvegarde avec sa clé. Ne redémarrez pas automatiquement sur la seule présence d’un code de sortie en échec. Après une rotation réussie, réutiliser uniquement l’ancienne variable d’environnement rend les tokens illisibles. Pour revenir en arrière, restaurez **ensemble** la sauvegarde PostgreSQL et son ancienne clé, service arrêté ; une autre option consiste à refaire une rotation contrôlée avec la clé actuelle comme clé de lecture et l’ancienne clé comme nouvelle cible.

Les écritures SQL de maintenance ne mettent pas à jour les objets Doctrine déjà chargés : le conteneur ponctuel se termine après la commande. Les processus applicatifs doivent redémarrer, comme exigé au début de la procédure.

## Vérification

`tests/Security/EncryptionRotationTest.php` utilise PostgreSQL de test et des secrets synthétiques. Il couvre l’inaccessibilité des données avec l’ancienne clé après rotation, la lecture avec la nouvelle, le rollback après un enregistrement corrompu, la simulation, le format de clé et l’absence de valeurs sensibles dans la sortie.
