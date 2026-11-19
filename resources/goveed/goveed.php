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
 * Démon Govee : le seul processus qui parle aux lampes.
 *
 * L'API LAN de Govee impose trois ports UDP fixes :
 *   - 4001 : la lampe y écoute la recherche (« scan ») ;
 *   - 4003 : la lampe y écoute les ordres (turn, brightness, colorwc,
 *            devStatus, ptReal) ;
 *   - 4002 : la lampe y RÉPOND, toujours sur ce port, quel que soit le port
 *            d'où l'on a envoyé.
 * Un seul processus de la machine peut donc recevoir les réponses : c'est
 * lui. Il tient trois rôles :
 *
 *   1. Une file d'envoi par lampe. Une lampe perd silencieusement un datagramme
 *      qui arrive moins de ~150 ms après le précédent (relevé par le projet
 *      govee-lan-bridge sur un H60B0) : tout ce qui part vers une lampe passe
 *      par sa file, espacé d'autant.
 *   2. Un relevé d'état (devStatus) régulier de chaque lampe, et juste après
 *      chaque ordre. Ce qui change est poussé au plugin (callback HTTP). Une
 *      lampe muette depuis trois relevés est déclarée hors ligne.
 *   3. Une oreille locale (TCP 127.0.0.1) par laquelle le plugin dépose ses
 *      ordres et demande une recherche. Une ligne JSON par requête, une ligne
 *      JSON en réponse. Chaque requête porte la clé API du plugin.
 *
 * Il ne charge pas core.inc.php : PHP en ligne de commande et curl suffisent.
 *
 *   php goveed.php --callback URL --pid FICHIER --keyfile FICHIER --port 55241
 *                  --interval 10 --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive dans un fichier que le démon efface aussitôt lu, jamais en
 * argument : ps est lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'keyfile:', 'port:', 'interval:', 'loglevel:', 'timezone:'));
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$ctlPort  = isset($options['port']) ? (int) $options['port'] : 55241;
$interval = isset($options['interval']) ? max(3, min(300, (int) $options['interval'])) : 10;
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = '';
if (!empty($options['keyfile']) && is_readable($options['keyfile'])) {
    $apiKey = trim((string) file_get_contents($options['keyfile']));
    @unlink($options['keyfile']);
}

/* Tous les niveaux que log::convertLogLevel() du coeur peut rendre. */
$levels = array('debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3,
                'critical' => 3, 'alert' => 3, 'emergency' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function gvLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    gvLog('error', 'Arguments manquants : --callback, --pid et --keyfile (contenant la clé API) sont obligatoires.');
    exit(1);
}
if (!function_exists('curl_init')) {
    gvLog('error', 'L\'extension PHP curl est absente.');
    exit(1);
}

/* Écart minimal entre deux datagrammes vers une même lampe. */
const GAP = 0.15;
/* Délai entre la fin d'un ordre et le relevé qui le confirme. */
const CONFIRM_AFTER = 0.35;
/* Adresse de recherche multicast de l'API LAN. */
const SCAN_GROUP = '239.255.255.250';

$udp = @stream_socket_server('udp://0.0.0.0:4002', $errno, $error, STREAM_SERVER_BIND);
if ($udp === false) {
    gvLog('error', 'Impossible d\'écouter le port UDP 4002 (' . $error . ') : un autre logiciel Govee (govee2mqtt, Home Assistant…) l\'occupe-t-il sur cette machine ?');
    exit(1);
}
stream_set_blocking($udp, false);
$ctl = @stream_socket_server('tcp://127.0.0.1:' . $ctlPort, $errno, $error);
if ($ctl === false) {
    gvLog('error', 'Impossible d\'ouvrir le port de contrôle TCP ' . $ctlPort . ' (' . $error . ') : changez-le dans la configuration du plugin.');
    exit(1);
}
stream_set_blocking($ctl, false);

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* Horloge monotone : un recalage NTP ne doit pas figer les relevés. */
function gvClock() {
    return hrtime(true) / 1e9;
}

/* ------------------------------------------------------------- CALLBACK */

function gvCurl($_query) {
    global $callback, $apiKey;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&')
         . 'apikey=' . rawurlencode($apiKey) . '&' . $_query;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_PROXY          => '',
        /* Boucle locale, parfois en https auto-signé. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    return $ch;
}

/* Jeedom refuse la clé (régénérée, accès API restreint) : inutile d'insister.
 * La gestion automatique du coeur relance le démon avec la clé du moment. */
function gvRefused($_code) {
    global $pidFile;
    if ($_code === 401 || $_code === 403) {
        gvLog('error', 'Jeedom refuse l\'accès (HTTP ' . $_code . ') : clé API changée ou accès API du plugin restreint. Arrêt du démon.');
        @unlink($pidFile);
        exit(1);
    }
}

function gvCall($_ch) {
    $body = curl_exec($_ch);
    $code = (int) curl_getinfo($_ch, CURLINFO_HTTP_CODE);
    $err = curl_error($_ch);
    curl_close($_ch);
    gvRefused($code);
    return array($code, $body, $err);
}

/* ------------------------------------------------------------- LAMPES */

$devices = array();     /* [ip] => array(eq, name, nextPoll, lastSeen, pending, online, lastPush, pushedAt) */
$queues = array();      /* [ip] => liste de datagrammes à envoyer */
$nextSend = array();    /* [ip] => instant (monotone) du prochain envoi permis */
$confirm = array();     /* [ip] => instant du relevé de confirmation */
$scans = array();       /* recherches en cours */
$states = array();      /* [eq] => état à pousser */
$pushRetryAt = -INF;
$pushFailures = 0;

function gvDevice($_ip, $_eq, $_name) {
    global $devices;
    $now = gvClock();
    return array('eq' => (int) $_eq, 'name' => $_name, 'nextPoll' => $now + 0.5 + count($devices) * 0.3,
                 'lastSeen' => -INF, 'since' => $now, 'online' => null, 'last' => '', 'pushedAt' => -INF);
}

/* Liste des lampes, relue chez Jeedom au démarrage, chaque minute, et quand le
 * plugin le demande (équipement créé, adresse changée). C'est aussi la preuve
 * de vie du démon côté plugin. */
function gvLoadDevices() {
    global $devices;
    list($code, $body, $err) = gvCall(gvCurl('action=devices'));
    $list = ($code === 200) ? json_decode((string) $body, true) : null;
    if (!is_array($list)) {
        return 'HTTP ' . $code . ' ' . $err;
    }
    $fresh = array();
    foreach ($list as $d) {
        if (!isset($d['ip'], $d['eq']) || !filter_var($d['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue;
        }
        $ip = $d['ip'];
        $name = isset($d['name']) ? (string) $d['name'] : '#' . $d['eq'];
        if (isset($devices[$ip]) && $devices[$ip]['eq'] === (int) $d['eq']) {
            $fresh[$ip] = $devices[$ip];
            $fresh[$ip]['name'] = $name;
        } else {
            $fresh[$ip] = gvDevice($ip, $d['eq'], $name);
            gvLog('info', 'Suivi de ' . $name . ' (' . $ip . ')');
        }
    }
    foreach (array_diff_key($devices, $fresh) as $ip => $d) {
        gvLog('info', 'Fin du suivi de ' . $d['name'] . ' (' . $ip . ')');
    }
    $devices = $fresh;
    return '';
}

function gvEnqueue($_ip, $_payload) {
    global $queues;
    if (!isset($queues[$_ip])) {
        $queues[$_ip] = array();
    }
    /* Un relevé déjà en attente suffit. */
    if ($_payload === gvStatusMsg() && in_array($_payload, $queues[$_ip], true)) {
        return;
    }
    $queues[$_ip][] = $_payload;
}

function gvStatusMsg() {
    return '{"msg":{"cmd":"devStatus","data":{}}}';
}

function gvScanMsg() {
    return '{"msg":{"cmd":"scan","data":{"account_topic":"reserve"}}}';
}

function gvSendTo($_payload, $_addr) {
    global $udp;
    $n = @stream_socket_sendto($udp, $_payload, 0, $_addr);
    if ($n === false || $n < 0) {
        gvLog('debug', 'Envoi vers ' . $_addr . ' impossible');
    }
}

/* Vide les files, chacune à son rythme. */
function gvFlushQueues() {
    global $queues, $nextSend;
    $now = gvClock();
    foreach ($queues as $ip => $queue) {
        if (empty($queue) || (isset($nextSend[$ip]) && $now < $nextSend[$ip])) {
            continue;
        }
        $payload = array_shift($queues[$ip]);
        gvSendTo($payload, $ip . ':4003');
        gvLog('debug', '-> ' . $ip . ' ' . $payload);
        $nextSend[$ip] = $now + GAP;
        if (empty($queues[$ip])) {
            unset($queues[$ip]);
        }
    }
}

/* Réponses des lampes, sur le port 4002. */
function gvReadUdp() {
    global $udp, $devices, $scans, $states;
    for ($i = 0; $i < 64; $i++) {
        $data = @stream_socket_recvfrom($udp, 65535, 0, $peer);
        if ($data === false || $data === '') {
            return;
        }
        $ip = (string) preg_replace('/:\d+$/', '', (string) $peer);
        $msg = json_decode($data, true);
        if (!is_array($msg) || !isset($msg['msg']['cmd'])) {
            gvLog('debug', '<- ' . $ip . ' illisible : ' . substr($data, 0, 200));
            continue;
        }
        gvLog('debug', '<- ' . $ip . ' ' . $data);
        $cmd = $msg['msg']['cmd'];
        $payload = isset($msg['msg']['data']) && is_array($msg['msg']['data']) ? $msg['msg']['data'] : array();
        if ($cmd === 'scan') {
            $found = $payload;
            if (!isset($found['ip']) || !filter_var($found['ip'], FILTER_VALIDATE_IP)) {
                $found['ip'] = $ip;
            }
            foreach ($scans as &$scan) {
                $scan['found'][isset($found['device']) ? (string) $found['device'] : $found['ip']] = $found;
            }
            unset($scan);
            continue;
        }
        if ($cmd === 'devStatus' && isset($devices[$ip])) {
            $d = &$devices[$ip];
            $d['lastSeen'] = gvClock();
            if ($d['online'] !== true) {
                if ($d['online'] === false) {
                    gvLog('info', $d['name'] . ' répond de nouveau');
                }
                $d['online'] = true;
            }
            $state = array('online' => 1);
            foreach (array('onOff', 'brightness', 'color', 'colorTemInKelvin') as $key) {
                if (isset($payload[$key])) {
                    $state[$key] = $payload[$key];
                }
            }
            $json = json_encode($state);
            /* Ce qui n'a pas changé n'est repoussé que toutes les cinq minutes. */
            if ($json !== $d['last'] || gvClock() - $d['pushedAt'] > 300) {
                $d['last'] = $json;
                $d['pushedAt'] = gvClock();
                $states[$d['eq']] = $state;
            }
            unset($d);
        }
    }
}

/* Relevés réguliers, relevés de confirmation, et lampes muettes. */
function gvPolls() {
    global $devices, $confirm, $interval, $states;
    $now = gvClock();
    foreach ($confirm as $ip => $at) {
        if ($now >= $at) {
            gvEnqueue($ip, gvStatusMsg());
            unset($confirm[$ip]);
            if (isset($devices[$ip])) {
                $devices[$ip]['nextPoll'] = $now + $interval;
            }
        }
    }
    $silence = max(3 * $interval, 30);
    foreach ($devices as $ip => &$d) {
        if ($now >= $d['nextPoll']) {
            gvEnqueue($ip, gvStatusMsg());
            $d['nextPoll'] = $now + $interval;
        }
        $since = max($d['lastSeen'], $d['since']);
        if ($d['online'] !== false && $now - $since > $silence) {
            $d['online'] = false;
            $d['last'] = '';
            $states[$d['eq']] = array('online' => 0);
            gvLog('warning', $d['name'] . ' (' . $ip . ') ne répond plus');
        }
    }
    unset($d);
}

/* Pousse les états au plugin, au plus deux fois par seconde. En cas d'échec,
 * ils sont gardés (sauf si un plus récent arrive) et les essais s'espacent. */
function gvPush() {
    global $states, $pushRetryAt, $pushFailures;
    static $pushedAt = -INF;
    $now = gvClock();
    if (empty($states) || $now - $pushedAt < 0.5 || $now < $pushRetryAt) {
        return;
    }
    $sending = $states;
    $states = array();
    $pushedAt = $now;
    $ch = gvCurl('action=push');
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode(array('states' => $sending)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    list($code, , $err) = gvCall($ch);
    if ($code === 200) {
        if ($pushFailures > 0) {
            gvLog('info', 'Jeedom de nouveau joignable');
        }
        $pushFailures = 0;
        return;
    }
    $states = $states + $sending;
    $pushFailures++;
    $pushRetryAt = gvClock() + min(30, 2 * (1 << min(4, $pushFailures - 1)));
    if ($pushFailures === 1) {
        gvLog('warning', 'Transmission des états à Jeedom en échec (HTTP ' . $code . ' ' . $err . '), nouvel essai en s\'espaçant');
    }
}

/* ------------------------------------------------------------- CONTRÔLE */

$clients = array();     /* connexions TCP du plugin */

function gvReply($_client, $_data) {
    @stream_set_blocking($_client, true);
    @fwrite($_client, json_encode($_data) . "\n");
    @fclose($_client);
}

function gvHandle($_client, $_line) {
    global $apiKey, $devices, $scans, $confirm, $interval;
    $req = json_decode($_line, true);
    if (!is_array($req) || !isset($req['key']) || !is_string($req['key']) || !hash_equals($apiKey, $req['key'])) {
        gvReply($_client, array('ok' => false, 'error' => 'refusé'));
        return;
    }
    $action = isset($req['action']) ? $req['action'] : '';
    switch ($action) {
        case 'ping':
            gvReply($_client, array('ok' => true, 'pid' => getmypid(), 'devices' => count($devices)));
            return;
        case 'reload':
            $problem = gvLoadDevices();
            gvReply($_client, array('ok' => $problem === '', 'error' => $problem));
            return;
        case 'send':
            /* Ordres pour une lampe, déjà mis en forme par le plugin. */
            $ip = isset($req['ip']) ? (string) $req['ip'] : '';
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !isset($req['msgs']) || !is_array($req['msgs'])) {
                gvReply($_client, array('ok' => false, 'error' => 'requête invalide'));
                return;
            }
            foreach ($req['msgs'] as $msg) {
                gvEnqueue($ip, is_string($msg) ? $msg : json_encode($msg));
            }
            /* Relevé de confirmation, une fois la file vidée. */
            global $queues;
            $confirm[$ip] = gvClock() + (isset($queues[$ip]) ? count($queues[$ip]) : 0) * GAP + CONFIRM_AFTER;
            gvReply($_client, array('ok' => true, 'queued' => count($req['msgs'])));
            return;
        case 'poll':
            $ip = isset($req['ip']) ? (string) $req['ip'] : '';
            if (isset($devices[$ip])) {
                $devices[$ip]['last'] = '';     /* repoussé même s'il n'a pas changé */
                gvEnqueue($ip, gvStatusMsg());
            }
            gvReply($_client, array('ok' => isset($devices[$ip])));
            return;
        case 'scan':
            /* Multicast pour le réseau local, et unicast vers chaque adresse
             * donnée : le multicast ne traverse pas les VLAN. La réponse part
             * quand le délai est écoulé. */
            $seconds = isset($req['seconds']) ? max(1, min(10, (int) $req['seconds'])) : 3;
            gvSendTo(gvScanMsg(), SCAN_GROUP . ':4001');
            $ips = isset($req['ips']) && is_array($req['ips']) ? $req['ips'] : array();
            foreach (array_unique(array_merge($ips, array_keys($devices))) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    gvSendTo(gvScanMsg(), $ip . ':4001');
                }
            }
            $scans[] = array('client' => $_client, 'deadline' => gvClock() + $seconds, 'found' => array());
            return;
    }
    gvReply($_client, array('ok' => false, 'error' => 'action inconnue'));
}

function gvAccept() {
    global $ctl, $clients;
    while (($c = @stream_socket_accept($ctl, 0)) !== false) {
        stream_set_blocking($c, false);
        $clients[] = array('sock' => $c, 'buf' => '', 'deadline' => gvClock() + 5);
    }
}

function gvReadClients($_ready) {
    global $clients;
    foreach ($clients as $k => &$c) {
        if (in_array($c['sock'], $_ready, true)) {
            $chunk = @fread($c['sock'], 65536);
            if ($chunk === false || ($chunk === '' && feof($c['sock']))) {
                @fclose($c['sock']);
                unset($clients[$k]);
                continue;
            }
            $c['buf'] .= $chunk;
        }
        $nl = strpos($c['buf'], "\n");
        if ($nl !== false) {
            $sock = $c['sock'];
            $line = substr($c['buf'], 0, $nl);
            unset($clients[$k]);
            gvHandle($sock, $line);
        } elseif (strlen($c['buf']) > 262144 || gvClock() > $c['deadline']) {
            @fclose($c['sock']);
            unset($clients[$k]);
        }
    }
    unset($c);
}

function gvFinishScans() {
    global $scans;
    $now = gvClock();
    foreach ($scans as $k => $scan) {
        if ($now >= $scan['deadline']) {
            gvReply($scan['client'], array('ok' => true, 'devices' => array_values($scan['found'])));
            unset($scans[$k]);
        }
    }
}

/* ----------------------------------------------------------------- BOUCLE */

gvLog('info', 'Démarrage du démon Govee (PID ' . getmypid() . ', relevé toutes les ' . $interval . ' s)');
$loadedAt = -INF;
$loadFailures = 0;
$loadRetryAt = -INF;

while ($running) {
    $now = gvClock();
    if ($now - $loadedAt > 60 && $now >= $loadRetryAt) {
        $problem = gvLoadDevices();
        if ($problem === '') {
            if ($loadFailures > 0) {
                gvLog('info', 'Jeedom de nouveau joignable');
            }
            $loadedAt = $now;
            $loadFailures = 0;
        } else {
            $loadFailures++;
            $loadRetryAt = $now + min(60, 5 * (1 << min(4, $loadFailures - 1)));
            if ($loadFailures === 1) {
                gvLog('warning', 'Callback Jeedom en échec (' . $problem . '), nouvel essai en s\'espaçant');
            }
        }
    }

    gvPolls();
    gvFlushQueues();

    $read = array($udp, $ctl);
    foreach ($clients as $c) {
        $read[] = $c['sock'];
    }
    $write = null;
    $except = null;
    /* Assez court pour respecter l'écart de 150 ms entre deux envois. */
    $wait = empty($queues) ? 200000 : 50000;
    $n = @stream_select($read, $write, $except, 0, $wait);
    if ($n !== false && $n > 0) {
        if (in_array($udp, $read, true)) {
            gvReadUdp();
        }
        if (in_array($ctl, $read, true)) {
            gvAccept();
        }
    }
    gvReadClients(is_array($read) ? $read : array());
    gvFinishScans();
    gvPush();
}

foreach ($clients as $c) {
    @fclose($c['sock']);
}
@fclose($ctl);
@fclose($udp);
@unlink($pidFile);
gvLog('info', 'Arrêt du démon Govee');
