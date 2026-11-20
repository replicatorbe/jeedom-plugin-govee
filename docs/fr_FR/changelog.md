# Changelog — plugin Govee LAN

## 0.3.0 (beta)

- Synchro WLED : la lampe rejoint un groupe de synchro WLED (UDP 21324).
  Elle suit l'allumage, la luminosité et la couleur fixe des WLED du groupe,
  et leur annonce ses propres changements. Réglage par équipement (suivre,
  annoncer, groupes) ; port et adresses unicast (WLED d'un autre VLAN) dans
  la configuration du plugin.
- Suivre un WLED par son WebSocket : la lampe suit un WLED donné par son
  adresse, en une seconde, même sur un autre VLAN (là où les broadcasts de
  la synchro s'arrêtent). Avec « Annoncer », le WLED suit aussi la lampe.
- Appli WLED : la lampe se présente comme un WLED (API JSON et WebSocket)
  sur un port propre ; l'appli la pilote, avec une page de commande
  complète (zones, effets, mode musique). Les ordres passent par les
  commandes de l'équipement.

## 0.2.0 (beta)

- Effets de l'application Govee : commande « Lancer un effet » (111 effets
  sur le H60B0), envoyés en local. Catalogue livré pour le H60B0,
  téléchargé pour les autres modèles, mis à jour par un bouton.
- Commande info « Effet » : dernier effet lancé.
- Mode musique de l'Uplighter (micro de la lampe) : activer, désactiver
  (retour à l'état d'avant), choix parmi huit modes, sensibilité (50 % par
  défaut).

## 0.1.0 (beta)

- Première version : pilotage local des lampes Govee par l'API LAN, sans
  cloud.
- Recherche multicast, et par adresse IP pour les lampes d'un autre VLAN.
- Lumière Jeedom complète : allumer, éteindre, luminosité, couleur,
  température de blanc, avec retour d'état relevé par un démon.
- Lampadaire Uplighter H60B0 : commandes par zone (haute, anneau latéral,
  basse) et vitesse de l'effet.
- Commande d'envoi de trames brutes pour expérimenter d'autres modèles.
