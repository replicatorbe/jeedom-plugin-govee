# Changelog — plugin Govee LAN

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
