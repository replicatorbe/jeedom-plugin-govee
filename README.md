# Govee LAN pour Jeedom

Plugin Jeedom qui pilote les lampes Govee Wi-Fi en réseau local, par l'API
LAN de Govee : sans cloud, sans compte, sans clé API.

- Lumière Jeedom complète avec retour d'état (démon PHP, aucune dépendance).
- Ajout par recherche multicast ou par adresse IP (lampes sur un autre VLAN).
- Lampadaire **Uplighter H60B0** : chaque zone pilotée à part, et vitesse de
  l'effet projeté.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

Tests hors ligne : `php tests/run.php` et `php tests/check-classes.php`.
