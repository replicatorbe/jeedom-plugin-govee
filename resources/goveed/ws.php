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
 * WebSocket minimal (RFC 6455), dans les deux rôles :
 *   - client, pour suivre un WLED (ws://ip/ws) ;
 *   - serveur, pour l'appli WLED qui pilote une lampe émulée.
 * Un client masque ses trames, un serveur jamais. Trames texte fragmentées
 * réassemblées, ping/pong, fermeture. Fonctions pures, sans socket : le démon
 * fait les lectures et écritures, le jeu d'essai vérifie l'encodage.
 * Repris du démon du plugin wledbe (même auteur, même licence).
 */

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const WS_MAX = 1048576;

function wsAccept($_key) {
    return base64_encode(sha1($_key . WS_GUID, true));
}

/* Une trame. $_mask : vrai côté client. */
function wsFrame($_opcode, $_payload, $_mask) {
    $len = strlen($_payload);
    $head = chr(0x80 | $_opcode);
    $bit = $_mask ? 0x80 : 0;
    if ($len < 126) {
        $head .= chr($bit | $len);
    } elseif ($len < 65536) {
        $head .= chr($bit | 126) . pack('n', $len);
    } else {
        $head .= chr($bit | 127) . pack('J', $len);
    }
    if (!$_mask) {
        return $head . $_payload;
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $_payload[$i] ^ $mask[$i % 4];
    }
    return $head . $mask . $masked;
}

/*
 * Découpe ce qui est arrivé. $_buf est consommé ; $_frag garde un message
 * texte en cours de réassemblage (array('op' => …, 'data' => …)).
 * Rend une liste d'événements : array('text', message), array('ping',
 * contenu), array('close', ''), ou array('error', raison).
 */
function wsParse(&$_buf, &$_frag) {
    $events = array();
    if (!is_array($_frag)) {
        $_frag = array('op' => 1, 'data' => '');
    }
    while (strlen($_buf) >= 2) {
        $b1 = ord($_buf[0]);
        $b2 = ord($_buf[1]);
        $len = $b2 & 127;
        $off = 2;
        if ($len === 126) {
            if (strlen($_buf) < 4) {
                break;
            }
            $len = unpack('n', substr($_buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($_buf) < 10) {
                break;
            }
            $len = unpack('J', substr($_buf, 2, 8))[1];
            $off = 10;
        }
        if ($len < 0 || $len > WS_MAX) {
            $events[] = array('error', 'trame démesurée');
            return $events;
        }
        $masked = ($b2 & 128) !== 0;
        if ($masked) {
            $off += 4;
        }
        if (strlen($_buf) < $off + $len) {
            break;
        }
        $payload = (string) substr($_buf, $off, $len);
        if ($masked) {
            $mask = substr($_buf, $off - 4, 4);
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        $_buf = (string) substr($_buf, $off + $len);
        $opcode = $b1 & 15;
        $fin = ($b1 & 128) !== 0;
        switch ($opcode) {
            case 1:
            case 2:
                $_frag = array('op' => $opcode, 'data' => $payload);
                break;
            case 0:
                $_frag['data'] .= $payload;
                break;
            case 8:
                $events[] = array('close', '');
                return $events;
            case 9:
                $events[] = array('ping', $payload);
                continue 2;
            default:
                continue 2;
        }
        if (strlen($_frag['data']) > WS_MAX) {
            $events[] = array('error', 'message démesuré');
            return $events;
        }
        if ($fin) {
            if ($_frag['op'] === 1) {
                $events[] = array('text', $_frag['data']);
            }
            $_frag = array('op' => 1, 'data' => '');
        }
    }
    return $events;
}
