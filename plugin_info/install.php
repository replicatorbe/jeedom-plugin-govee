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


require_once __DIR__ . '/../../../core/php/core.inc.php';

function govee_install() {
    /* Le point d'entrée du démon ne parle qu'à un processus local. */
    config::save('api::govee::mode', 'localhost', 'core');
}

/* Exécutée dans la requête de la page des plugins : rien de lent, aucune
 * lampe interrogée. On rattrape seulement les commandes. */
function govee_update() {
    try {
        govee::rebuildCommands();
    } catch (Throwable $e) {
        log::add('govee', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

/* Appelée aussi à la simple désactivation : ne rien y détruire. */
function govee_remove() {
    try {
        govee::deamon_stop();
    } catch (Throwable $e) {
        log::add('govee', 'error', __('Arrêt du démon :', __FILE__) . ' ' . $e->getMessage());
    }
}
