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
 * Synchro WLED : le paquet que les WLED s'envoient entre eux (« UDP
 * notifier », port 21324), lu et écrit pour qu'une lampe Govee entre dans un
 * groupe de synchro comme un WLED de plus.
 *
 * Disposition relevée dans le code de WLED (wled00/udp.cpp, notify() et
 * parseNotifyPacket()) :
 *    0  protocole (0 : notifier)          1  callMode (> 199 : ignoré)
 *    2  luminosité (0 : éteint)         3-5  couleur principale R G B
 *    8  effet (0 : couleur fixe)          9  vitesse
 *   10  blanc (W)                        11  version du paquet
 *   16  intensité                     17-18  transition (ms, petit-boutiste)
 *   36  groupes de synchro (version ≥ 9, masque de 8 bits)
 *   37  255 : pas de blanc réglable ; 0 : octet 38 en CCT 0-255 ;
 *       sinon kelvin = (37 << 8) | 38 (version ≥ 10)
 *   39+ segments (version ≥ 11), que la lampe ignore.
 *
 * Le paquet envoyé est en version 10 : un WLED qui le reçoit n'applique que
 * la couleur principale, le blanc, la luminosité et l'effet « couleur fixe »,
 * sans toucher à ses segments.
 *
 * Fonctions pures, sans état : le démon et le jeu d'essai les partagent.
 */

const WLED_SYNC_PORT = 21324;
const WLED_PACKET_VERSION = 10;
/* CALL_MODE_DIRECT_CHANGE de WLED. */
const WLED_CALL_DIRECT = 1;

/*
 * Lit un paquet de synchro. Rend null s'il ne s'agit pas d'un paquet de
 * synchro WLED, sinon :
 *   on, bri (0-255), rgb [r,g,b], w, fx, groups (masque),
 *   kelvin (0 si absent), cct (-1 si absent).
 */
function wledParse($_data) {
    $len = strlen($_data);
    if ($len < 12 || ord($_data[0]) !== 0 || ord($_data[1]) > 199) {
        return null;
    }
    $b = array_values(unpack('C*', $_data));
    $version = $b[11];
    $packet = array(
        'version' => $version,
        'on'      => $b[2] > 0,
        'bri'     => $b[2],
        'rgb'     => array($b[3], $b[4], $b[5]),
        'w'       => $version > 0 ? $b[10] : 0,
        'fx'      => $b[8],
        /* Avant la version 9, tout paquet vaut pour le groupe 1. */
        'groups'  => ($version >= 9 && $len > 36) ? $b[36] : 1,
        'kelvin'  => 0,
        'cct'     => -1,
    );
    if ($version > 9 && $len > 38 && $b[37] < 255) {
        if ($b[37] > 0) {
            $packet['kelvin'] = ($b[37] << 8) | $b[38];
        } else {
            $packet['cct'] = $b[38];
        }
    }
    return $packet;
}

/*
 * Ce qu'un paquet demande à une lampe, en unités Govee : null si rien
 * d'applicable, sinon on (bool), et selon le cas brightness (1-100),
 * color [r,g,b] ou kelvin.
 *
 * Un WLED qui joue un effet envoie sa couleur principale, qui n'est alors
 * qu'un ingrédient de l'effet : seuls l'allumage et la luminosité sont
 * suivis. En couleur fixe, la couleur l'est aussi ; un blanc pur (RGB nul,
 * canal W allumé) devient une température de blanc dans la plage de la
 * lampe.
 */
function wledWanted($_packet, $_kMin, $_kMax) {
    if (!is_array($_packet)) {
        return null;
    }
    if (!$_packet['on']) {
        return array('on' => false);
    }
    $want = array('on' => true, 'brightness' => max(1, min(100, (int) round($_packet['bri'] * 100 / 255))));
    if ($_packet['fx'] !== 0) {
        return $want;
    }
    $rgb = $_packet['rgb'];
    /* Un kelvin explicite fait foi : un blanc annoncé par une lampe Govee
     * (ou un WLED à blanc réglable) l'accompagne d'un RVB approché, qui ne
     * doit pas le changer en couleur. */
    if ($_packet['kelvin'] > 0) {
        $want['kelvin'] = (int) max($_kMin, min($_kMax, $_packet['kelvin']));
    } elseif ($rgb[0] + $rgb[1] + $rgb[2] > 0) {
        $want['color'] = $rgb;
    } elseif ($_packet['w'] > 0) {
        if ($_packet['cct'] >= 0) {
            /* CCT de WLED : 0 le plus chaud, 255 le plus froid. */
            $k = $_kMin + $_packet['cct'] * ($_kMax - $_kMin) / 255;
        } else {
            $k = ($_kMin + $_kMax) / 2;
        }
        $want['kelvin'] = (int) max($_kMin, min($_kMax, round($k)));
    }
    return $want;
}

/*
 * État d'une lampe (devStatus : onOff, brightness 0-100, color {r,g,b},
 * colorTemInKelvin) ramené à la forme de wledWanted(), pour comparer ce qui
 * est demandé à ce qui est déjà là.
 */
function wledFromStatus($_status) {
    if (!is_array($_status) || !isset($_status['onOff'])) {
        return null;
    }
    if ((int) $_status['onOff'] !== 1) {
        return array('on' => false);
    }
    $state = array('on' => true, 'brightness' => isset($_status['brightness']) ? max(0, min(100, (int) $_status['brightness'])) : 100);
    $k = isset($_status['colorTemInKelvin']) ? (int) $_status['colorTemInKelvin'] : 0;
    if ($k > 0) {
        $state['kelvin'] = $k;
    } elseif (isset($_status['color']) && is_array($_status['color'])) {
        $c = $_status['color'];
        $state['color'] = array(
            isset($c['r']) ? (int) $c['r'] : 0,
            isset($c['g']) ? (int) $c['g'] : 0,
            isset($c['b']) ? (int) $c['b'] : 0,
        );
    }
    return $state;
}

/*
 * Messages de l'API LAN qui mènent la lampe de $_current (forme
 * wledFromStatus, ou null si inconnu) à $_want. Seul ce qui change est
 * envoyé : un curseur de luminosité glissé sur un WLED produit une rafale de
 * paquets, et la lampe ne prend qu'un ordre toutes les 150 ms.
 */
function wledMessages($_want, $_current) {
    $msg = function ($_cmd, $_data) {
        return json_encode(array('msg' => array('cmd' => $_cmd, 'data' => $_data)));
    };
    $known = is_array($_current);
    if (!$_want['on']) {
        return ($known && !$_current['on']) ? array() : array($msg('turn', array('value' => 0)));
    }
    $out = array();
    if (!$known || !$_current['on']) {
        $out[] = $msg('turn', array('value' => 1));
    }
    if (isset($_want['color']) && (!$known || !isset($_current['color']) || $_current['color'] !== $_want['color'])) {
        $out[] = $msg('colorwc', array('color' => array('r' => $_want['color'][0], 'g' => $_want['color'][1], 'b' => $_want['color'][2]),
                                       'colorTemInKelvin' => 0));
    }
    if (isset($_want['kelvin']) && (!$known || !isset($_current['kelvin']) || abs($_current['kelvin'] - $_want['kelvin']) > 50)) {
        $out[] = $msg('colorwc', array('color' => array('r' => 0, 'g' => 0, 'b' => 0), 'colorTemInKelvin' => $_want['kelvin']));
    }
    if (isset($_want['brightness']) && (!$known || !isset($_current['brightness']) || $_current['brightness'] !== $_want['brightness'])) {
        $out[] = $msg('brightness', array('value' => $_want['brightness']));
    }
    return $out;
}

/* Signature d'un état, pour reconnaître l'écho d'un ordre venu d'un WLED. */
function wledSignature($_state) {
    if (!is_array($_state)) {
        return '';
    }
    if (!$_state['on']) {
        return 'off';
    }
    $sig = 'on/' . (isset($_state['brightness']) ? $_state['brightness'] : '');
    if (isset($_state['color'])) {
        $sig .= '/rgb' . implode(',', $_state['color']);
    }
    if (isset($_state['kelvin'])) {
        /* Une lampe rend parfois le blanc demandé à quelques kelvins près. */
        $sig .= '/k' . (int) round($_state['kelvin'] / 100);
    }
    return $sig;
}

/* Température de blanc vers RVB, comme govee::kelvinToRgb(). */
function wledKelvinToRgb($_k) {
    $t = max(1000, min(40000, $_k)) / 100;
    $r = $t <= 66 ? 255 : 329.698727446 * pow($t - 60, -0.1332047592);
    $g = $t <= 66 ? 99.4708025861 * log($t) - 161.1195681661 : 288.1221695283 * pow($t - 60, -0.0755148492);
    $b = $t >= 66 ? 255 : ($t <= 19 ? 0 : 138.5177312231 * log($t - 10) - 305.0447927307);
    return array_map(function ($c) { return (int) max(0, min(255, $c)); }, array($r, $g, $b));
}

/*
 * Paquet de synchro pour un état de lampe (forme wledFromStatus). En blanc,
 * la couleur principale est le RVB de la température, et le kelvin suit en
 * octets 37-38 pour les WLED à blanc réglable.
 */
function wledPacket($_state, $_groups) {
    $b = array_fill(0, 41, 0);
    $b[1] = WLED_CALL_DIRECT;
    $on = is_array($_state) && $_state['on'];
    $b[2] = $on ? max(1, min(255, (int) round((isset($_state['brightness']) ? $_state['brightness'] : 100) * 255 / 100))) : 0;
    $rgb = array(255, 255, 255);
    $kelvin = 0;
    if (is_array($_state) && isset($_state['color'])) {
        $rgb = $_state['color'];
    } elseif (is_array($_state) && isset($_state['kelvin'])) {
        $kelvin = (int) $_state['kelvin'];
        $rgb = wledKelvinToRgb($kelvin);
    }
    list($b[3], $b[4], $b[5]) = $rgb;
    $b[9] = 128;                    /* vitesse et intensité par défaut de WLED */
    $b[11] = WLED_PACKET_VERSION;
    $b[16] = 128;
    $b[17] = 750 & 0xFF;            /* transition de 750 ms, celle de WLED */
    $b[18] = (750 >> 8) & 0xFF;
    $b[36] = $_groups & 0xFF;
    if ($kelvin > 0) {
        $b[37] = ($kelvin >> 8) & 0xFF;
        $b[38] = $kelvin & 0xFF;
    } else {
        $b[37] = 255;
    }
    $b[40] = 36;                    /* taille d'un segment (aucun n'est envoyé) */
    return pack('C*', ...$b);
}

/* « 1, 3 » ou « 1 3 » → masque des groupes 1 à 8. Vide : groupe 1. */
function wledGroupMask($_text) {
    $mask = 0;
    foreach (preg_split('/[^0-9]+/', (string) $_text, -1, PREG_SPLIT_NO_EMPTY) as $n) {
        $n = (int) $n;
        if ($n >= 1 && $n <= 8) {
            $mask |= 1 << ($n - 1);
        }
    }
    return $mask === 0 ? 1 : $mask;
}

/*
 * État JSON d'un WLED (celui que pousse son WebSocket, ou /json/state),
 * ramené à la forme de wledParse() : la lampe suit son segment principal.
 * Rend null si l'état est illisible.
 */
function wledFromJson($_state) {
    if (!is_array($_state) || !isset($_state['on'], $_state['bri'])) {
        return null;
    }
    $segs = isset($_state['seg']) && is_array($_state['seg']) ? array_values($_state['seg']) : array();
    $seg = null;
    $main = isset($_state['mainseg']) ? (int) $_state['mainseg'] : 0;
    foreach ($segs as $s) {
        if (is_array($s) && isset($s['id']) && (int) $s['id'] === $main) {
            $seg = $s;
        }
    }
    if ($seg === null && !empty($segs) && is_array($segs[0])) {
        $seg = $segs[0];
    }
    $col = ($seg !== null && isset($seg['col'][0]) && is_array($seg['col'][0])) ? array_values($seg['col'][0]) : array(255, 160, 0);
    $packet = array(
        'version' => 12,
        'on'      => (bool) $_state['on'] && (int) $_state['bri'] > 0 && ($seg === null || !isset($seg['on']) || $seg['on']),
        'bri'     => max(0, min(255, (int) $_state['bri'])),
        'rgb'     => array((int) ($col[0] ?? 0), (int) ($col[1] ?? 0), (int) ($col[2] ?? 0)),
        'w'       => (int) ($col[3] ?? 0),
        'fx'      => $seg !== null && isset($seg['fx']) ? (int) $seg['fx'] : 0,
        'groups'  => 255,
        'kelvin'  => 0,
        'cct'     => -1,
    );
    if ($seg !== null && isset($seg['cct'])) {
        $cct = (int) $seg['cct'];
        if ($cct >= 1900) {
            $packet['kelvin'] = $cct;
        } else {
            $packet['cct'] = max(0, min(255, $cct));
        }
    }
    /* La luminosité du segment s'ajoute à la luminosité générale. */
    if ($seg !== null && isset($seg['bri']) && (int) $seg['bri'] < 255) {
        $packet['bri'] = (int) round($packet['bri'] * max(0, (int) $seg['bri']) / 255);
        $packet['on'] = $packet['on'] && $packet['bri'] > 0;
    }
    return $packet;
}
