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
 *   POST ?apikey=…&action=push {"states":{ID:{…}}} → états relevés (devStatus)
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
    }
    echo 'OK';
    die();
}

http_response_code(400);
echo 'Unknown action';
