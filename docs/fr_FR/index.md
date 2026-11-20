# Plugin Govee LAN

Pilote les lampes Govee Wi-Fi **en réseau local**, par l'API LAN de Govee :
sans cloud, sans compte et sans clé API une fois la lampe installée.

## Prérequis

1. Installer la lampe avec l'application Govee Home et mettre à jour son
   micrologiciel.
2. Dans les réglages de la lampe, activer **LAN Control** (Contrôle LAN).
3. Donner à la lampe une **adresse IP fixe** dans le routeur.
4. Si la lampe est sur un autre réseau ou VLAN que Jeedom, laisser passer
   dans le pare-feu les ports **UDP 4001 et 4003** (Jeedom → lampe) et
   **UDP 4002** (lampe → Jeedom).
5. Aucun autre logiciel Govee (govee2mqtt, Home Assistant…) ne doit écouter
   le port UDP 4002 sur la machine Jeedom.

## Ajouter une lampe

Page du plugin → **Rechercher des lampes**. La recherche interroge le réseau
de Jeedom en multicast ; pour une lampe d'un autre VLAN, saisir son adresse
IP dans le champ prévu : le multicast ne traverse pas les VLAN. Cliquer sur
**Créer** en face de la lampe trouvée.

L'équipement est identifié par l'identifiant Govee de la lampe : si son
adresse change, une nouvelle recherche la met à jour (la recherche horaire
le fait seule pour les lampes du réseau de Jeedom).

## Commandes

### Toutes les lampes

| Commande | Type | Rôle |
|---|---|---|
| Joignable | info | la lampe répond aux relevés |
| Etat, Luminosité, Couleur, Température de blanc | info | état renvoyé par la lampe |
| Allumer, Éteindre, Basculer | action | |
| Régler la luminosité | curseur 0–100 | 0 éteint |
| Régler la couleur | couleur | |
| Régler le blanc | curseur en kelvins | 2000–9000 K, 2700–6500 K sur un Uplighter |
| Rafraîchir | action | relève l'état tout de suite |
| Effet | info | dernier effet lancé par Jeedom |
| Lancer un effet | liste | effets de l'application Govee |
| Envoyer des trames | message | trames `ptReal` brutes, en hexadécimal |

L'état est relevé par le démon toutes les 10 secondes (réglable) et juste
après chaque ordre : un changement fait depuis l'application ou à la voix
apparaît donc dans Jeedom.

### Lampadaire Uplighter (H60B0)

Le profil **Uplighter** est choisi automatiquement pour le H60B0 ; il peut
être forcé dans l'équipement pour essayer un modèle voisin. Il ajoute, pour
chacune des trois zones :

- **Zone haute** (effet projeté au plafond) : allumer, éteindre, luminosité,
  couleur, blanc ;
- **Anneau latéral** : idem ;
- **Éclairage bas** : allumer, éteindre, luminosité, blanc (pas de couleur) ;

et la **vitesse de l'effet** projeté (0 = arrêté, 100 = rapide).

À savoir :

- **La lampe n'accepte que deux zones allumées à la fois.** Allumer une
  troisième zone éteint celle allumée depuis le plus longtemps.
- **La lampe ne renvoie pas l'état des zones.** Leurs commandes info
  montrent le dernier ordre envoyé par Jeedom ; un changement de zone fait
  depuis l'application ne s'y voit pas. L'état global (allumée, luminosité,
  couleur) reste, lui, relu sur la lampe.
- Allumer une zone allume aussi la lampe.

### Mode musique (Uplighter)

La lampe suit la musique captée par **son propre micro**, comme le mode
musique de l'application.

| Commande | Type | Rôle |
|---|---|---|
| Activer le mode musique | action | dernier mode choisi (Rythme au départ) |
| Désactiver le mode musique | action | remet ce qui tournait avant : effet, blanc ou couleur |
| Choisir le mode musique | liste | Rythme, Spectre, Éclosion, Scintillement, Sauts, Vortex, Lucioles, Flux |
| Régler la sensibilité au son | curseur 0–100 | 50 % par défaut ; 100 réagit au moindre bruit |
| Mode musique, Mode musique choisi, Sensibilité au son | info | dernier ordre envoyé par Jeedom |

- Choisir un mode ou régler la sensibilité active la musique ; régler la
  sensibilité alors qu'elle est arrêtée la retient pour la prochaine fois.
- Régler une couleur, un blanc ou lancer un effet sort aussi du mode musique.
- La lampe garde les réglages de chaque mode faits dans l'application
  (palette, style) : Jeedom choisit le mode et la sensibilité.
- Rythme et Spectre ont été vérifiés sur la lampe ; les autres modes
  utilisent la même trame.

### Effets

La commande **Lancer un effet** propose les effets (« scènes ») de
l'application Govee : 111 sur le H60B0 (Aurora, Ocean, Bonfire, Starry Sky,
Christmas…). Ils partent en local, comme le reste : seule leur description
vient de la bibliothèque publique de Govee, sans compte.

- Le catalogue du H60B0 est livré avec le plugin. Pour un autre modèle, il
  est téléchargé à la création de l'équipement.
- Le bouton **Mettre à jour les effets**, dans l'équipement, récupère les
  effets ajoutés depuis par Govee.
- En scénario, l'effet se choisit dans la liste, ou par son nom exact
  (`Ocean`).
- La lampe ne dit pas quel effet tourne : la commande info **Effet** montre
  le dernier lancé par Jeedom. Elle se vide quand une couleur ou un blanc est
  réglé.
- Les effets DIY créés dans l'application, et le mode musique, ne sont pas
  accessibles : ils restent sur le compte Govee.

Sur le H60B0, l'encodage des effets a été vérifié sur la lampe. Pour les
autres modèles, il suit celui de govee2mqtt, sans essai : un effet qui ne
fait rien se signale.

### Envoyer des trames

Pour expérimenter : une trame par ligne (ou séparées par `;`), en
hexadécimal, par exemple `33 30 01 01` (allume la zone haute). Une trame de
moins de 20 octets est complétée de zéros et de sa somme de contrôle.

## Suivre un WLED

Le champ **Suivre le WLED** de l'équipement prend l'adresse IP d'un WLED (la
liste propose ceux du plugin WLED). Le démon s'abonne à son WebSocket, comme
l'appli WLED : dès que le WLED s'allume, s'éteint, change de luminosité ou
de couleur, la lampe suit, en une seconde environ. Contrairement à la
synchro UDP ci-dessous, cela traverse les VLAN : la connexion part de
Jeedom.

- Le segment principal du WLED fait foi ; sa luminosité propre s'ajoute à
  la luminosité générale.
- Pendant un effet WLED, seuls l'allumage et la luminosité sont suivis.
- Au démarrage du démon, l'état du WLED sert de référence et n'est pas
  appliqué : redémarrer Jeedom ne change pas la lampe.
- Avec **Annoncer aux WLED**, le WLED suivi reçoit aussi les changements de
  la lampe (couleur fixe, luminosité, allumage).
- Un ESP8266 n'accepte que trois clients WebSocket : le plugin WLED en
  prend un, la lampe un autre.

### Commandes WLED

Pour les scénarios, chaque lampe a :

| Commande | Rôle |
|---|---|
| Suivre le WLED / Ne plus suivre le WLED | Reprendre ou suspendre le suivi, sans perdre l'adresse saisie |
| Suivi WLED actif | 1 si la lampe suit son WLED |
| WLED suivi joignable | 1 tant que la connexion au WLED est ouverte : de quoi alerter s'il disparaît |
| Régler la copie en direct | Non, Couleur moyenne ou Répartie |
| Copie en direct | Le choix en cours |

### Copier les couleurs en direct

Avec **Copier les couleurs en direct**, la lampe rejoue en temps réel les
couleurs des LED du WLED suivi : tous ses effets (feu, arc-en-ciel, vagues…),
et demain ses effets réactifs au son, à 20 images par seconde environ. Le
démon reçoit l'aperçu du WLED (celui de sa vue « Peek ») et le transmet à la
lampe par le flux temps réel de Govee, celui de Govee Desktop.

- **Couleur moyenne** : toute la lampe prend la couleur moyenne du WLED.
- **Répartie sur la lampe** : la bande du WLED est découpée sur les
  segments du flux, du bas vers le haut (H60B0 : 7 segments, relevés sur la
  lampe). Les autres modèles prennent la couleur moyenne.
- La luminosité reste celle du WLED, suivie comme d'habitude ; WLED éteint,
  la lampe s'éteint et sort du flux. À l'arrêt de la copie (case décochée,
  WLED injoignable, démon arrêté), la lampe retrouve son état d'avant.
- Le WLED ne donne son aperçu qu'à un client à la fois : ouvrir sa vue
  « Peek » dans un navigateur interrompt la copie, reprise au plus tard
  30 secondes après sa fermeture.

## Synchro WLED

La lampe peut entrer dans un groupe de synchro WLED, comme un WLED de plus :
elle reçoit et envoie le paquet que les WLED s'échangent sur le port UDP
21324 (page **Config → Sync Interfaces** d'un WLED). Deux cases sur
l'équipement :

- **Suivre les WLED** : un WLED du groupe s'allume, s'éteint, change de
  luminosité ou de couleur, et la lampe suit. Quand le WLED joue un effet,
  seuls l'allumage et la luminosité sont suivis : sa couleur n'est alors
  qu'un ingrédient de l'effet. Un blanc pur (canal W) devient une
  température de blanc, bornée à la plage de la lampe.
- **Annoncer aux WLED** : chaque changement de la lampe, qu'il vienne de
  Jeedom, de l'application Govee ou de la voix, est annoncé. Les WLED
  du groupe qui ont la réception activée le suivent. Le relevé d'état
  impose son rythme : un changement fait hors de Jeedom part au plus tard
  après le délai de relevé.

**Groupes** : les numéros 1 à 8 des cases « Groups » de la page Sync des
WLED, séparés par des virgules (1 par défaut). Côté WLED, cochez « Send
notifications » pour que la lampe suive le WLED, et les cases de réception
(« Brightness », « Color ») pour que le WLED suive la lampe.

Le démon n'ouvre le port qu'à partir du moment où une lampe s'en sert.
Redémarrer le démon n'annonce rien aux WLED, et un ordre venu d'un WLED ne
lui est pas renvoyé en écho.

**Autre réseau, autre VLAN.** Les WLED annoncent leurs changements en
broadcast, qui ne traverse pas les routeurs : pour que la lampe suive des
WLED d'un autre VLAN, il faut que Jeedom ait une patte dans ce VLAN, ou que
le routeur relaie le port UDP 21324 (relais de broadcast). Dans l'autre
sens, la configuration du plugin, rubrique **Annoncer aussi à**, accepte
l'adresse de chaque WLED : ils reçoivent l'annonce directement.

## Appli WLED

Avec **Se présenter comme un WLED**, la lampe répond comme un WLED sur le
port choisi (8180 par défaut, un port différent par lampe) de la machine
Jeedom. Dans l'appli WLED, ajoutez l'appareil à la main avec l'adresse
`IP-de-Jeedom:8180` : la recherche automatique de l'appli ne retient pas le
port, elle ne trouve pas la lampe.

- **Liste des appareils** : allumage et luminosité, en direct.
- **Écran de l'appareil** : une page de commande propre à la lampe
  (luminosité, couleur, blanc, effets Govee, et pour l'Uplighter chaque zone
  et le mode musique). Ce n'est pas l'interface de WLED.
- Chaque geste exécute la commande correspondante de l'équipement, comme
  depuis Jeedom (actions avant et après, timeline) : Jeedom reste la source
  de vérité, ses historiques et scénarios voient tout, et un changement fait
  ailleurs (Jeedom, application Govee, voix) apparaît dans l'appli.
- Un curseur glissé dans l'appli envoie une rafale d'ordres : un seul part
  à la fois chez Jeedom, et seule la dernière valeur compte.
- Uplighter : deux zones au plus restent allumées. Allumer un troisième
  segment éteint la zone allumée depuis le plus longtemps ; une luminosité
  de segment envoyée pour une zone éteinte est retenue sans l'allumer.
- Pour les autres clients WLED, l'API JSON est servie aussi (`/json`,
  `/json/state`, `/json/si`, `/json/eff`…) : une lampe simple est un seul
  segment, un Uplighter trois (zone haute, anneau, zone basse), et les effets
  sont ceux de l'application Govee.

Comme un vrai WLED, cette API répond sans mot de passe à tout le réseau
local : ne l'activez que sur un réseau de confiance. Une page web d'un autre
site ne peut pas s'en servir (contrôle de l'origine), les connexions sont
limitées (32, dont 12 par lampe) et les trames brutes ne sont pas
accessibles. Home Assistant n'est pas
pris en charge : il impose le port 80, celui de Jeedom.

## Exemple : soirée musique

Un scénario « Soirée » lancé à la main, par un bouton ou à heure fixe :

1. Plugin WLED : allumer le WLED du salon sur un effet animé (par exemple
   « Fire 2012 » ou un effet réactif au son si un WLED capte la musique).
2. Lampe Govee : **Régler la copie en direct** sur **Répartie**. La lampe
   rejoue les couleurs du WLED, segment par segment.
3. Pour danser sur la musique avec son propre micro, la lampe peut aussi
   passer en **Activer le mode musique** à la place de la copie.

Et un scénario « Fin de soirée » : **Régler la copie en direct** sur **Non**
(la lampe retrouve son état d'avant), puis éteindre le WLED. Avec le suivi
actif, éteindre le WLED éteint aussi la lampe.

La commande **WLED suivi joignable** peut déclencher une alerte si le WLED
disparaît du réseau.

## Santé

La page Santé du plugin montre l'état du démon et des lampes et, quand elles
sont utilisées, celui des fonctions WLED : connexion à chaque WLED suivi
(copie en cours ou prête), port de l'appli WLED et nombre d'applis
connectées, port de la synchro UDP.

## Démon

Le démon est indispensable au retour d'état : la lampe répond toujours sur
le port UDP 4002, qu'un seul processus peut écouter. Il espace aussi les
envois (une lampe perd un ordre qui suit le précédent de moins de 150 ms).
S'il est arrêté, les ordres partent quand même et le cron relève l'état une
fois par minute.

## Dépannage

- **Aucune lampe trouvée** : LAN Control activé ? Lampe sur le même réseau,
  ou adresse IP saisie ? Ports UDP 4001–4003 ouverts entre les réseaux ?
- **Le démon ne démarre pas** : son journal `goveed` dit si le port 4002 ou
  le port de contrôle est déjà pris.
- **L'appli WLED affiche la lampe hors ligne** : démon arrêté, port pris
  (journal `goveed`), ou adresse saisie sans le port.
- **La synchro WLED ne fait rien** : le journal `goveed`, en niveau info,
  dit si le port 21324 est pris, et trace chaque paquet appliqué ou annoncé.
  WLED sur un autre VLAN : voir « Autre réseau, autre VLAN ».
- **Une zone reste éteinte après un réglage de blanc** : les valeurs hors de
  2700–6500 K éteignent une zone du H60B0 ; le plugin les borne.

## Crédits

Les codes des zones de l'Uplighter viennent du projet
[govee-lan-bridge](https://github.com/SamWiIIiams/govee-lan-bridge), qui les
a relevés sur le trafic Bluetooth de l'application Govee. L'encodage des
effets s'appuie sur [govee2mqtt](https://github.com/wez/govee2mqtt) et les
notes de [AlgoClaw/Govee](https://github.com/AlgoClaw/Govee).
