# Identifiants et destinations Git

Les jetons sont chiffrés par Sodium secretbox avec nonce aléatoire et authentification. EXOPULSE_ENCRYPTION_KEY contient 32octets encodés en base64, est distincte du secret de session et reste hors Git. Une clé absente empêche l’enregistrement des identifiants ; aucun chiffrement dégradé n’est utilisé. Le hash/clé de .env.test est une fixture publique uniquement.

EXOPULSE_GIT_HOSTS autorise des hôtes HTTPS exacts (GitHub et GitLab SaaS par défaut). EXOPULSE_INTERNAL_GIT_HOSTS est une décision d’opérateur pour des noms internes précis ; les formulaires ne modifient pas ces listes. Les URLs avec utilisateur, query de base, fragment, port ou contrôle sont refusées. Les redirections et résolutions privées restent contrôlées dans les adaptateurs suivants.

Les contrats séparent lecture et écriture d’issues pour permettre de futurs fournisseurs en lecture seule. Le client Git dédié n’est pas décoré par le profiler afin que les en-têtes d’authentification ne soient pas conservés dans ses traces.
