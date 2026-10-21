# Persistance du parc

La migration initiale crée connexions, dépôts, scans, constats, liens d’issues, synchronisations, paramètres et audit. Un dépôt est l’application suivie ; aucun doublon d’entité Application. Les identifiants UUID internes restent stables après renommage. L’identité externe est unique par connexion et non à l’échelle du fournisseur.

Les scans conservent une photographie JSON des technologies, dépendances et preuves. Les constats courants possèdent une empreinte unique par dépôt et conservent leurs premières/dernières observations. Clôturer une issue ne résout pas un constat. Les clés étrangères protègent les relations et les index portent sur santé, dates de scans et état des constats.

Les tokens de connexion ne doivent contenir que du texte chiffré authentifié ; aucun getter ou rendu ne les expose. La migration down supprime ces tables : sauvegarde obligatoire avant tout retour arrière d’un environnement contenant des données.

Les modifications sont horodatées en UTC via un callback Doctrine partagé. Les lignes existantes sont reprises avec updated_at=created_at avant de rendre la colonne obligatoire. `monitored` distingue un dépôt exclu d’un dépôt inaccessible ; `nextRefreshAt` planifie les contrôles d’issues sans falsifier la date de vérification distante.
