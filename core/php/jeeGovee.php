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


/*
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET  ?apikey=…&action=devices                  → lampes à suivre, en JSON
 *   POST ?apikey=…&action=push {"states":{ID:{…}}} → états relevés (devStatus) ;
 *        la réponse donne l'état WLED des lampes émulées concernées
 *   POST ?apikey=…&action=wled&eq=ID {ordre WLED}  → ordre de l'appli WLED,
 *        exécuté ; la réponse donne le nouvel état WLED de la lampe
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/* La seule clé du plugin : jeedom::apiAccess() accepterait aussi celle d'un
 * utilisateur. apiAccess() reste appelé pour son contrôle d'origine (accès du
 * plugin limité à localhost). */
if (!is_string(init('apikey')) || !hash_equals(jeedom::getApiKey('govee'), init('apikey'))
    || !jeedom::apiAccess(init('apikey'), 'govee')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'devices') {
    /* Preuve de vie : un démon qui ne joint plus Jeedom est déclaré arrêté. */
    cache::set('govee::daemon_seen', time());
    header('Content-Type: application/json');
    echo json_encode(govee::daemonDevices());
    die();
}

if (init('action') == 'push') {
    cache::set('govee::daemon_seen', time());
    $payload = json_decode(file_get_contents('php://input'), true);
    $states = (is_array($payload) && isset($payload['states']) && is_array($payload['states'])) ? $payload['states'] : array();
    $wled = array();
    foreach ($states as $id => $state) {
        $eqLogic = eqLogic::byId((int) $id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'govee' || !$eqLogic->getIsEnable() || !is_array($state)) {
            continue;
        }
        try {
            $eqLogic->ingest($state);
        } catch (Throwable $e) {
            log::add('govee', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        try {
            if ($eqLogic->wledApiEnabled()) {
                $wled[(int) $id] = $eqLogic->wledJson();
            }
        } catch (Throwable $e) {
            log::add('govee', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'wled' => (object) $wled));
    die();
}

if (init('action') == 'wled') {
    cache::set('govee::daemon_seen', time());
    $eqLogic = eqLogic::byId((int) init('eq'));
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'govee' || !$eqLogic->getIsEnable() || !$eqLogic->wledApiEnabled()) {
        http_response_code(404);
        echo 'Unknown device';
        die();
    }
    $error = '';
    try {
        $done = $eqLogic->wledApply(json_decode(file_get_contents('php://input'), true));
        log::add('govee', 'debug', $eqLogic->getHumanName() . ' : ordre WLED -> ' . (empty($done) ? 'rien' : implode(', ', $done)));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        log::add('govee', 'warning', $eqLogic->getHumanName() . ' : ordre WLED refusé (' . $error . ')');
    }
    try {
        $wled = $eqLogic->wledJson();
    } catch (Throwable $e) {
        $wled = null;
        $error = $error !== '' ? $error : $e->getMessage();
        log::add('govee', 'error', $eqLogic->getHumanName() . ' : état WLED illisible (' . $e->getMessage() . ')');
    }
    header('Content-Type: application/json');
    echo json_encode(array('ok' => $error === '', 'error' => $error, 'wled' => $wled));
    die();
}

http_response_code(400);
echo 'Unknown action';
