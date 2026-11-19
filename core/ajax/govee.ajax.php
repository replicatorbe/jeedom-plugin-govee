<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */


try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function goveeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'govee') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Recherche (multicast et adresses données), sans rien créer. */
    if (init('action') == 'scan') {
        unautorizedInDemo();
        $ips = preg_split('/[\s,;]+/', (string) init('ips'), -1, PREG_SPLIT_NO_EMPTY);
        ajax::success(array('devices' => govee::scan($ips, 3), 'daemon' => govee::deamon_info()['state'] == 'ok'));
    }

    /* Crée les lampes retenues, ou met leur adresse à jour. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $devices = json_decode(init('devices'), true);
        if (!is_array($devices) || count($devices) === 0) {
            throw new Exception(__('Aucune lampe à créer.', __FILE__));
        }
        $created = array();
        foreach ($devices as $device) {
            $found = array();
            foreach (array('ip', 'device', 'sku', 'ble_hard', 'ble_soft', 'wifi_hard', 'wifi_soft') as $key) {
                $found[$key] = govee::cleanText(isset($device[$key]) ? $device[$key] : '', 32);
            }
            if (!filter_var($found['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $eqLogic = govee::createFromScan($found);
            try {
                $eqLogic->refresh();
            } catch (Throwable $e) {
                log::add('govee', 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
            $created[] = $eqLogic->getId();
        }
        ajax::success(array('created' => $created));
    }

    /* Relève l'état d'une lampe maintenant. */
    if (init('action') == 'refresh') {
        $eqLogic = goveeEq();
        $eqLogic->refresh();
        ajax::success();
    }

    /* Dernier état brut renvoyé par la lampe, pour le diagnostic. */
    if (init('action') == 'data') {
        $eqLogic = goveeEq();
        ajax::success(array(
            'id'         => $eqLogic->getId(),
            'status'     => $eqLogic->getCache('last_status', null),
            'status_at'  => (int) $eqLogic->getCache('last_status_at', 0),
            'uplighter'  => $eqLogic->isUplighter(),
            'daemon'     => govee::deamon_info()['state'] == 'ok',
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
