# CLASH — le réseau social inversé

Ici, pas d'amis : des **ennemis**. Pas de followers : des **haters**. Pas de likes : des **réactions négatives** (💩 🤮 👎 😭 😡 👽 🤡 🖕).
Aucun email, aucun numéro de téléphone, aucun pistage, aucune ressource externe (polices et icônes sont auto-hébergées).

## Prérequis
- PHP ≥ 8.1 avec `pdo_mysql`, `mbstring`, `gd`, `fileinfo` (+ `exif` recommandé pour corriger l'orientation des photos)
- MySQL 5.7+ / MariaDB 10.3+

## Installation

```bash
# 1. Base de données (installation neuve)
mysql -u root -p < database.sql

# 2. (Facultatif) réglages locaux : copie et adapte
cp config/local.example.php config/local.php

# 3. Droits d'écriture sur les uploads
chmod -R 775 assets/uploads

# 4. Lancer en local (mode debug activé)
CLASH_DEBUG=1 CLASH_DB_USER=root php -S localhost:8000
```

Sous Apache/Nginx, place le dossier dans la racine web : l'URL de base (`/clash`, `/`, …) est **détectée automatiquement**.
Sinon, force-la avec `define('BASE_URL', '/mon-chemin');` dans `config/local.php` ou la variable `CLASH_BASE_URL`.

### Mise à jour d'une base existante
Un seul outil applique les migrations manquantes, dans l'ordre, et retient celles déjà faites :
```bash
php tools/migrate.php --status           # voir ce qui reste à faire, sans rien modifier
php tools/migrate.php --baseline=009     # 1re fois sur une base existante : indique la dernière migration DÉJÀ appliquée
php tools/migrate.php                    # ensuite : applique uniquement les nouvelles migrations
```
Une installation neuve (`database.sql`) est déjà à jour et suivie. `--baseline` est obligatoire sur une base existante non encore suivie :
rejouer d'anciennes migrations pourrait écraser des données. Utilise les mêmes réglages que le site (`config/local.php` ou `CLASH_DB_*`).
Sans accès terminal (hébergement mutualisé), les fichiers de `migrations/` restent applicables à la main dans phpMyAdmin.

### Antivirus (optionnel, recommandé en production)
CLASH peut faire analyser **chaque fichier envoyé** (publications, messages, photos) par ClamAV **avant** de l'enregistrer :
```bash
sudo apt install clamav-daemon          # Debian/Ubuntu
# /etc/clamav/clamd.conf : StreamMaxLength 30M   (≥ MAX_FILE_SIZE, sinon les gros fichiers sont rejetés)
sudo systemctl enable --now clamav-daemon
```
puis, dans `config/local.php` (ou variables d'environnement) :
```php
define('CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl'); // ou 'tcp://127.0.0.1:3310'
define('CLAMAV_FAIL_CLOSED', false); // true = refuser les envois si clamd est injoignable (plus strict)
```
Le compte du serveur web (ex. `www-data`) doit pouvoir lire le socket (`usermod -aG clamav www-data`). Le dialogue avec clamd utilise le protocole
INSTREAM : aucune commande shell n'est lancée. L'état de l'antivirus est visible dans Modération › Statistiques.
Sans clamd, la protection de base reste en place (type MIME vérifié, images ré-encodées, dossier d'uploads sans exécution).

### Variables d'environnement
`CLASH_DB_HOST`, `CLASH_DB_NAME`, `CLASH_DB_USER`, `CLASH_DB_PASS`, `CLASH_BASE_URL`, `CLASH_CLAMAV_SOCKET`, `CLASH_CLAMAV_FAIL_CLOSED=1`, `CLASH_DEBUG=1` (jamais en production).

### Devenir modérateur
```sql
UPDATE users SET is_admin = 1 WHERE username = 'ton_pseudo';
```
puis « Modération » dans le menu : deux onglets, **Messages** (spam) et **Publications & ripostes**.
Un 3e onglet **Statistiques** affiche l'activité (comptes, publications, réactions, courbes sur 14 jours) et des **avertissements de configuration** (base en `root`, mode debug, HTTPS absent).
Pour chaque signalement : voir la copie du contenu, supprimer le contenu (fichiers inclus), suspendre ou réactiver l'auteur, marquer traité / rejeter.

### Production
- Active HTTPS (le cookie de session passe automatiquement en `Secure`).
- Utilise un utilisateur MySQL dédié (pas `root`), dans `config/local.php` ou via les variables d'environnement.
- Nginx : bloque l'exécution de PHP dans `assets/uploads/` et l'accès à `config/`, `includes/`, `migrations/`, `tools/`, `*.sql`, `*.md`
  (le `.htaccess` fourni ne s'applique qu'à Apache).
- `php.ini` : `upload_max_filesize ≥ 30M`, `post_max_size ≥ 260M`, `max_file_uploads ≥ 12` (déjà posés par `.htaccess` / `.user.ini`).

## Interface
- **Desktop** : barre latérale (type Bluesky) · colonne centrale · colonne « cibles du moment ».
- **Tablette** : barre latérale réduite aux icônes.
- **Mobile** : barre du haut + **barre d'onglets en bas** (type Instagram), bouton ＋ central, tiroir de menu, composer plein écran.
- Réaction : **un tap = 👎**, **appui long / survol = les 8 réactions**, **double-tap sur un média** = réaction rapide.
- Médias multiples en carrousel (swipe, flèches, points), défilement infini (fil, profils) et commentaires paginés par 20, toasts et dialogues de confirmation (plus de `alert()`).
- Messagerie : images **et vidéos** (25 Mo), suppression de ses propres messages (fichier effacé du disque).
- Notifications : suppression une à une ou « Tout effacer ».
- Thème sombre par défaut, thème clair automatique (`prefers-color-scheme`).

## Sécurité (ce qui est en place)
| Sujet | Mesure |
|---|---|
| Force brute | Verrouillage **progressif** sur la connexion et la phrase magique (5 échecs → 15 min, 10 → 1 h, 15 → 24 h, par IP + pseudo) ; plafonds par IP ; limites sur inscription, changement de mot de passe et suppression de compte |
| Session | `session_regenerate_id` à la connexion/réinitialisation, cookie `HttpOnly` + `SameSite=Lax` + `Secure` (HTTPS), mode strict |
| CSRF | Jeton sur tous les POST (déconnexion incluse) |
| Uploads | Analyse antivirus ClamAV (optionnelle) avant enregistrement ; type MIME détecté sur le contenu ; extension imposée par le MIME ; JPEG/PNG/WebP **toujours ré-encodés** (EXIF/GPS, texte caché et données ajoutées supprimés) ; 25 Mo par fichier et **100 Mo au total par publication** ; dossier sans exécution |
| Anti-spam | Limites par utilisateur sur publications, commentaires, réactions, messages, signalements, blocages et suppressions ; limites par IP sur connexion, phrase magique et inscription |
| Mots de passe | bcrypt, 8 car. min, 72 octets max ; hash factice pour un temps de réponse constant |
| Réinitialisation | Phrase magique **robuste** (12 caractères, 3 mots, pas de pseudo, pas de phrase courante), normalisée ; autorisation limitée à 10 min |
| Erreurs | Jamais affichées en production (journalisées) ; réponses JSON propres pour `/api/` |
| En-têtes | CSP stricte (`script-src 'self'`), `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Fichiers sensibles | `.sql`, `.md`, `config/`, `includes/`, `migrations/` refusés par `.htaccess` |
| Injection | Requêtes préparées partout ; `LIKE` échappé ; sortie HTML échappée |
| Blocage | Messages, réactions, commentaires, haine, guerre et accès aux posts bloqués dans les deux sens ; les commentaires d'une personne bloquée sont masqués |
| Modération | Signalement des messages, publications et ripostes avec copie du contenu au moment du signalement ; suppression du contenu, suspension **et réactivation** de comptes |
| Confidentialité | **Profil privé** (publications visibles des seuls ennemis jurés, appliqué au fil, au profil, aux pages, à la recherche, aux réactions et commentaires), **mode fantôme**, refus des défis, qui peut écrire (tous / ennemis / personne), notifications désactivables |
| Vie privée | **Suppression définitive du compte** (mot de passe + pseudo à retaper) : base en cascade, fichiers effacés du disque, notifications reçues par d'autres anonymisées ; le dernier administrateur ne peut pas se supprimer |

## Structure
```
config/        configuration, connexion PDO, local.example.php
includes/      fonctions, auth, header/footer, post_card, rail (colonne droite)
api/           endpoints JSON (réactions, haine, guerre, blocage, messages, suppression, fil)
admin/         modération
assets/        css/, js/ (main.js, messages.js), vendor/ (FontAwesome + polices), uploads/
migrations/    scripts de mise à jour de base
tools/         migrate.php (outil de migration, ligne de commande uniquement)
database.sql   schéma complet (installation neuve)
```

## Dernière mise à jour — v4.11

Voir `MISE_A_JOUR_V4_11.md` : correction de l'installation neuve, mentions plus sûres, outil de migration.
