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
- **Une zone reste éteinte après un réglage de blanc** : les valeurs hors de
  2700–6500 K éteignent une zone du H60B0 ; le plugin les borne.

## Crédits

Les codes des zones de l'Uplighter viennent du projet
[govee-lan-bridge](https://github.com/SamWiIIiams/govee-lan-bridge), qui les
a relevés sur le trafic Bluetooth de l'application Govee. L'encodage des
effets s'appuie sur [govee2mqtt](https://github.com/wez/govee2mqtt) et les
notes de [AlgoClaw/Govee](https://github.com/AlgoClaw/Govee).
