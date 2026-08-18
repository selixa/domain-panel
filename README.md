# Domain Panel

Panel perso de suivi de noms de domaine : registrar, projet associé, date
d'expiration (via RDAP), auto-renew, coût annuel, notes.

## Déploiement (Planethoster mutu)

1. Upload de **tout le contenu** de ce zip dans un dossier de ton `public_html`
   (ou à la racine si tu veux `tondomaine.fr/` directement).
2. Vérifie que PHP 8+ est actif sur le dossier (panel N0C/cPanel → sélecteur
   de version PHP).
3. Vérifie que l'extension `curl` est activée (elle l'est par défaut chez
   Planethoster).
4. Va sur `https://tondomaine.fr/chemin/login.php` → tu tombes automatiquement
   en mode "Configuration" puisqu'aucun mot de passe n'existe encore.
5. Choisis ton mot de passe. Tu es redirigé vers le panel, et un **token de
   rafraîchissement** s'affiche une seule fois en haut de page — note-le
   immédiatement, il ne sera plus jamais affiché (il est bien stocké dans
   `config/auth.php`, mais en clair uniquement dans ce fichier protégé).

⚠️ Entre l'étape 4 et 5, n'importe qui tombant sur l'URL peut définir le mot
de passe à ta place. Fais l'étape 5 tout de suite après l'upload, sans laisser
traîner.

## Configurer le cron (rafraîchissement quotidien)

Panel Planethoster → **Tâches CRON** → nouvelle tâche :

- Type : `wget` ou `curl` (selon ce qui est proposé)
- Commande / URL :
  `https://tondomaine.fr/chemin/refresh-domains.php?token=TON_TOKEN`
- Fréquence : une fois par jour suffit largement (les dates d'expiration ne
  bougent pas d'un coup)

Sans cron, les données restent affichées mais ne se rafraîchissent que quand
tu cliques sur "↻ Tout rafraîchir" ou "↻" sur un domaine dans l'interface
(le cache expire de toute façon après 24h et se regénère au prochain appel).

## Usage

- **+ Ajouter un domaine** : formulaire simple, vérifie direct le RDAP à
  l'ajout donc l'expiration s'affiche tout de suite.
- **↻** sur une ligne : force une revérification RDAP pour ce domaine.
- **↻ Tout rafraîchir** : revérifie tous les domaines (utile en dehors du
  cron quotidien).
- Le code couleur : vert (>60j), orange (<60j), rouge (<30j ou expiré),
  gris (non encore vérifié ou source RDAP en erreur pour ce TLD).

## Fichiers de données

- `data/domains.json` : la liste des domaines et leurs infos manuelles
  (registrar, projet, coût, notes...). C'est ta source de vérité, éditable
  à la main si besoin (format JSON, un objet par domaine).
- `cache/expirations.json` : cache des réponses RDAP (expiration,
  nameservers, statut). Régénéré automatiquement, tu peux le supprimer sans
  risque, il se recrée tout seul.
- `config/auth.php` : hash du mot de passe + token de cron. Ne jamais
  partager ce fichier.

Les trois dossiers (`config/`, `data/`, `cache/`) sont protégés par
`.htaccess` contre l'accès direct depuis le navigateur.

## Mettre à jour une instance déjà en prod

Si tu réuploades une nouvelle version par FTP par-dessus une instance déjà
configurée, **ne transfère jamais ces trois fichiers** (ils contiennent ton
mot de passe, ton token de cron, ta liste de domaines et le cache
d'expirations) :

- `config/auth.php` — ton mot de passe hashé + ton token de cron
- `data/domains.json` — ta liste de domaines
- `cache/expirations.json` — le cache RDAP/WHOIS

Tous les autres fichiers (`.php`, `assets/style.css`, `.htaccess`, `README.md`,
`LICENSE`) peuvent être écrasés sans risque : ils ne contiennent aucune donnée
personnelle. La plupart des clients FTP proposent une option "ne pas écraser
si plus récent/différent" ou "ignorer ces fichiers" — sinon, uploade tout
sauf ces trois-là manuellement.

## Open source / GitHub

Le dépôt est prêt à être publié tel quel : `config/auth.php`,
`data/domains.json` et `cache/expirations.json` sont commités avec un contenu
vide/neutre (aucune donnée personnelle), donc un `git clone` frais fonctionne
directement en mode setup.

Le risque, c'est *après* : si tu déploies un jour cette instance en la
gérant avec le même dépôt git (par exemple un `git pull` sur le serveur au
lieu du FTP), ton mot de passe réel et tes vrais domaines finiraient dans ces
fichiers suivis par git — et un `git add -A && git commit` un jour de flemme
les enverrait sur GitHub. Pour t'en protéger, juste après le premier clone/
déploiement :

```bash
git update-index --assume-unchanged config/auth.php data/domains.json cache/expirations.json
```

Ça dit à git d'ignorer toute modification locale de ces trois fichiers,
même déjà trackés — donc tes vraies données ne remonteront jamais dans un
commit, sans avoir à restructurer le projet. Pour revenir en arrière un jour :
`git update-index --no-assume-unchanged <fichier>`.

Licence : MIT (`LICENSE`), avec ton nom comme copyright holder — change-le si
tu préfères y mettre SELIXACORP ou rester anonyme.

## Limites connues

- RDAP ne couvre pas 100% des TLD. Certains (`.io`, `.bz`, `.co`, `.me`...)
  n'ont pas de service RDAP enregistré auprès de l'IANA et ne sont vérifiables
  que via l'ancien protocole WHOIS (port 43). Le panel bascule automatiquement
  dessus dans ce cas (`includes/whois.php`) — tu verras alors "via WHOIS"
  sous le nom du domaine au lieu du nombre de nameservers. Si l'hébergeur
  bloque le port 43 en sortie (rare sur Planethoster, mais possible), ou si
  le TLD n'a ni RDAP ni serveur WHOIS connu, le domaine reste marqué "Non
  disponible" — tu gardes quand même la main sur les infos manuelles
  (registrar, projet, coût, notes). La liste des serveurs WHOIS connus est
  dans `WHOIS_SERVERS` en haut de `includes/whois.php`, à compléter si tu
  ajoutes des domaines dans d'autres TLD exotiques.
- Le fallback WHOIS analyse du texte non standardisé (chaque registre a son
  propre format) : l'extraction de la date d'expiration est heuristique et
  peut échouer sur des registres non testés, même si la connexion réussit.
- "Tout rafraîchir" peut être lent si plusieurs domaines sont sur des TLD
  sans RDAP (chaque fallback WHOIS ajoute quelques secondes) — le cron
  quotidien reste la façon la plus fiable de garder les données à jour sans
  attendre devant l'interface.
- Outil mono-utilisateur, pas de gestion de rôles — un seul mot de passe.
- Pas de suppression protégée par confirmation navigateur uniquement
  (confirm JS), pas de corbeille.
