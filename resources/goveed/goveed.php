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
 *   4. La synchro WLED (UDP 21324, voir wledsync.php), pour les lampes où elle
 *      est activée : un paquet reçu d'un WLED du groupe est appliqué à la
 *      lampe, et un changement de la lampe est annoncé aux WLED. Le port
 *      n'est ouvert que si au moins une lampe s'en sert.
 *   5. Le suivi d'un WLED par son WebSocket (ws://ip/ws), qui traverse les
 *      VLAN là où les broadcasts de la synchro s'arrêtent : chaque état
 *      poussé par le WLED est appliqué aux lampes qui le suivent, et un
 *      changement d'une lampe qui annonce lui est renvoyé.
 *      Avec la copie en direct, l'aperçu du WLED (ses LED, 20 images par
 *      seconde) est rejoué sur la lampe par le flux temps réel de Govee.
 *   6. L'émulation WLED : pour chaque lampe où elle est activée, un serveur
 *      HTTP et WebSocket sur un port propre, qui parle comme un WLED à
 *      l'appli WLED. Les ordres reçus sont passés à Jeedom (sans bloquer la
 *      boucle : Jeedom rappelle le démon pour les exécuter), qui rend l'état
 *      WLED de la lampe ; chaque changement est poussé aux WebSocket ouverts.
 *
 * Il ne charge pas core.inc.php : PHP en ligne de commande et curl suffisent.
 *
 *   php goveed.php --callback URL --pid FICHIER --keyfile FICHIER --port 55241
 *                  --interval 10 --loglevel debug --timezone Europe/Brussels
 *                  [--wledport 21324] [--wledtargets 192.168.1.255,192.168.30.20]
 *
 * La clé API arrive dans un fichier que le démon efface aussitôt lu, jamais en
 * argument : ps est lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'keyfile:', 'port:', 'interval:', 'loglevel:', 'timezone:', 'wledport:', 'wledtargets:'));
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$ctlPort  = isset($options['port']) ? (int) $options['port'] : 55241;
$interval = isset($options['interval']) ? max(3, min(300, (int) $options['interval'])) : 10;
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$wledPort = isset($options['wledport']) ? (int) $options['wledport'] : 0;
/* Adresses où annoncer les changements en plus des broadcasts locaux : un
 * broadcast ne traverse pas les VLAN. */
$wledTargets = array();
foreach (preg_split('/[\s,;]+/', isset($options['wledtargets']) ? (string) $options['wledtargets'] : '', -1, PREG_SPLIT_NO_EMPTY) as $t) {
    if (filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $wledTargets[] = $t;
    }
}
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

require_once __DIR__ . '/wledsync.php';
require_once __DIR__ . '/ws.php';
if ($wledPort < 1024 || $wledPort > 65535) {
    $wledPort = WLED_SYNC_PORT;
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
$pushing = false;

function gvDevice($_ip, $_eq, $_name) {
    global $devices;
    $now = gvClock();
    return array('eq' => (int) $_eq, 'name' => $_name, 'nextPoll' => $now + 0.5 + count($devices) * 0.3,
                 'lastSeen' => -INF, 'since' => $now, 'online' => null, 'last' => '', 'pushedAt' => -INF,
                 'wled' => gvWledConfig(null), 'status' => null, 'sig' => '', 'wledAt' => -INF, 'announcedAt' => -INF, 'api' => null,
                 'razer' => false, 'razerOnAt' => -INF, 'razerSentAt' => -INF, 'razerLast' => '', 'quietUntil' => -INF, 'restore' => false, 'link' => null);
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
        $wled = gvWledConfig(isset($d['wled']) ? $d['wled'] : null);
        if ($wled !== $fresh[$ip]['wled'] && ($wled['recv'] || $wled['send'])) {
            gvLog('info', 'Synchro WLED de ' . $name . ' : ' . ($wled['recv'] ? 'réception' : '') . ($wled['recv'] && $wled['send'] ? ' et ' : '')
                . ($wled['send'] ? 'envoi' : '') . ', groupes ' . implode(',', gvWledGroupList($wled['groups'])));
        }
        if ($fresh[$ip]['razer'] && ($wled['mirror'] === '' || $wled['follow'] !== $fresh[$ip]['wled']['follow'])) {
            gvRazerStop($fresh[$ip], $ip, true);
        }
        if ($wled['follow'] !== $fresh[$ip]['wled']['follow'] && $wled['follow'] !== '') {
            gvLog('info', $name . ' suit le WLED ' . $wled['follow'] . ' par WebSocket');
        }
        $fresh[$ip]['wled'] = $wled;
        $fresh[$ip]['api'] = (isset($d['api']['port'], $d['api']['json']) && is_array($d['api']['json']))
            ? array('port' => (int) $d['api']['port'], 'json' => $d['api']['json']) : null;
    }
    foreach (array_diff_key($devices, $fresh) as $ip => $d) {
        gvLog('info', 'Fin du suivi de ' . $d['name'] . ' (' . $ip . ')');
    }
    $devices = $fresh;
    gvWledSocket();
    gvFollowSync();
    gvApiSync();
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
            gvWledObserve($ip, $payload);
            $json = json_encode($state);
            /* Ce qui n'a pas changé n'est repoussé que toutes les cinq minutes. */
            if ($json !== $d['last'] || gvClock() - $d['pushedAt'] > 300) {
                $d['last'] = $json;
                $d['pushedAt'] = gvClock();
                $states[$d['eq']] = array_merge(isset($states[$d['eq']]) ? $states[$d['eq']] : array(), $state);
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
            $d['status'] = null;
            $d['sig'] = '';
            $states[$d['eq']] = array_merge(isset($states[$d['eq']]) ? $states[$d['eq']] : array(), array('online' => 0));
            gvLog('warning', $d['name'] . ' (' . $ip . ') ne répond plus');
        }
    }
    unset($d);
}

/* Pousse les états au plugin, au plus deux fois par seconde. En cas d'échec,
 * ils sont gardés (sauf si un plus récent arrive) et les essais s'espacent.
 * La requête ne bloque pas la boucle : le traitement d'un relevé par Jeedom
 * (scénarios déclenchés) peut durer, et Jeedom doit pouvoir joindre le démon
 * pendant ce temps. Un seul envoi à la fois. */
function gvPush() {
    global $states, $pushRetryAt, $multi, $jobs, $pushing;
    static $pushedAt = -INF;
    $now = gvClock();
    if ($pushing || empty($states) || $now - $pushedAt < 0.5 || $now < $pushRetryAt) {
        return;
    }
    $sending = $states;
    $states = array();
    $pushedAt = $now;
    $pushing = true;
    $ch = gvCurl('action=push');
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode(array('states' => $sending)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    curl_multi_add_handle($multi, $ch);
    $jobs[(int) $ch] = array('type' => 'push', 'ch' => $ch, 'sending' => $sending);
}

function gvPushDone($_job, $_code, $_body, $_err) {
    global $states, $pushRetryAt, $pushFailures, $pushing;
    $pushing = false;
    if ($_code === 200) {
        $reply = json_decode($_body, true);
        if (is_array($reply) && isset($reply['wled']) && is_array($reply['wled'])) {
            gvApiUpdate($reply['wled']);
        }
        if ($pushFailures > 0) {
            gvLog('info', 'Jeedom de nouveau joignable');
        }
        $pushFailures = 0;
        return;
    }
    $states = $states + $_job['sending'];
    $pushFailures++;
    $pushRetryAt = gvClock() + min(30, 2 * (1 << min(4, $pushFailures - 1)));
    if ($pushFailures === 1) {
        gvLog('warning', 'Transmission des états à Jeedom en échec (HTTP ' . $_code . ' ' . $_err . '), nouvel essai en s\'espaçant');
    }
}

/* ------------------------------------------------------------- SYNCHRO WLED */

$wledSock = null;       /* socket UDP de la synchro, ouvert à la demande */
$wledOwn = array();     /* adresses de cette machine : nos propres broadcasts reviennent */
$wledCasts = array();   /* adresses de broadcast des interfaces locales */
$wledWant = array();    /* [ip] => état demandé par un WLED, pas encore envoyé */
$wledBindFailed = false;

/* Réglages de synchro d'une lampe, tels que le plugin les donne. */
function gvWledConfig($_c) {
    $c = is_array($_c) ? $_c : array();
    $k = isset($c['k']) && is_array($c['k']) && count($c['k']) === 2 ? array_map('intval', array_values($c['k'])) : array(2000, 9000);
    return array(
        'recv'   => !empty($c['recv']),
        'send'   => !empty($c['send']),
        'groups' => isset($c['groups']) ? max(1, min(255, (int) $c['groups'])) : 1,
        'k'      => $k,
        /* Adresse IPv4 d'un WLED à suivre par WebSocket, port facultatif. */
        /* Copie en direct : '', 'average' (une couleur) ou 'spread' (la
         * bande répartie selon 'layout'). */
        'mirror' => (isset($c['mirror']) && in_array($c['mirror'], array('average', 'spread'), true)) ? $c['mirror'] : '',
        /* Disposition du flux : n segments pour la bande, m pour l'ambiance. */
        'layout' => (isset($c['layout']['strip'], $c['layout']['ambient']) && (int) $c['layout']['strip'] >= 1
                     && (int) $c['layout']['strip'] + (int) $c['layout']['ambient'] <= 32)
                    ? array('strip' => (int) $c['layout']['strip'], 'ambient' => max(0, (int) $c['layout']['ambient'])) : null,
        'reverse' => !empty($c['reverse']),
        'follow' => (isset($c['follow']) && preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d{1,5})?$/', (string) $c['follow'])) ? (string) $c['follow'] : '',
    );
}

function gvWledGroupList($_mask) {
    $list = array();
    for ($i = 0; $i < 8; $i++) {
        if ($_mask & (1 << $i)) {
            $list[] = $i + 1;
        }
    }
    return $list;
}

/* Ouvre le port de synchro si une lampe s'en sert, le ferme sinon. Un échec
 * (port pris) n'arrête pas le démon : la synchro est seulement absente, et
 * le prochain rechargement de la liste réessaie. */
function gvWledSocket() {
    global $devices, $wledSock, $wledPort, $wledOwn, $wledCasts, $wledBindFailed;
    $needed = false;
    foreach ($devices as $d) {
        $needed = $needed || $d['wled']['recv'] || $d['wled']['send'];
    }
    if (!$needed) {
        if ($wledSock !== null) {
            @fclose($wledSock);
            $wledSock = null;
            gvLog('info', 'Synchro WLED arrêtée (plus aucune lampe ne s\'en sert)');
        }
        return;
    }
    $wledOwn = array();
    $wledCasts = array();
    foreach (function_exists('net_get_interfaces') ? net_get_interfaces() : array() as $name => $itf) {
        foreach (isset($itf['unicast']) ? $itf['unicast'] : array() as $u) {
            if (!isset($u['family'], $u['address']) || $u['family'] !== 2) {   /* AF_INET, sans exiger l'extension sockets */
                continue;
            }
            $wledOwn[$u['address']] = true;
            /* Ni la boucle locale, ni les ponts de conteneurs. */
            if (!empty($u['broadcast']) && $name !== 'lo' && !preg_match('/^(docker|br-|veth|virbr)/', $name)) {
                $wledCasts[$u['broadcast']] = true;
            }
        }
    }
    if ($wledSock !== null) {
        return;
    }
    $ctx = stream_context_create(array('socket' => array('so_broadcast' => true)));
    $sock = @stream_socket_server('udp://0.0.0.0:' . $wledPort, $errno, $error, STREAM_SERVER_BIND, $ctx);
    if ($sock === false) {
        if (!$wledBindFailed) {
            gvLog('error', 'Synchro WLED impossible : le port UDP ' . $wledPort . ' est pris (' . $error . '). Changez-le dans la configuration du plugin.');
        }
        $wledBindFailed = true;
        return;
    }
    $wledBindFailed = false;
    stream_set_blocking($sock, false);
    $wledSock = $sock;
    gvLog('info', 'Synchro WLED à l\'écoute sur le port UDP ' . $wledPort . ', annonces vers ' . implode(', ', gvWledDestinations()));
}

function gvWledDestinations() {
    global $wledCasts, $wledTargets;
    return array_values(array_unique(array_merge(array_keys($wledCasts), $wledTargets)));
}

/* Paquets reçus : chaque lampe du groupe en retient la dernière demande. */
function gvWledRead() {
    global $wledSock, $wledOwn;
    for ($i = 0; $i < 64; $i++) {
        $data = @stream_socket_recvfrom($wledSock, 2048, 0, $peer);
        if ($data === false || $data === '') {
            return;
        }
        $ip = (string) preg_replace('/:\d+$/', '', (string) $peer);
        if (isset($wledOwn[$ip])) {
            continue;
        }
        $packet = wledParse($data);
        if ($packet === null) {
            continue;
        }
        gvLog('debug', '<- WLED ' . $ip . ' ' . json_encode($packet));
        gvWledDispatch($packet, null);
    }
}

/* Une demande de synchro, pour toutes les lampes qui reçoivent ce groupe
 * (sauf $_except, la lampe qui l'a émise). */
function gvWledDispatch($_packet, $_except) {
    global $devices, $wledWant;
    foreach ($devices as $ip => $d) {
        if ($ip === $_except || !$d['wled']['recv'] || !($d['wled']['groups'] & $_packet['groups']) || $d['online'] === false) {
            continue;
        }
        $want = wledWanted($_packet, $d['wled']['k'][0], $d['wled']['k'][1]);
        if ($want !== null) {
            $wledWant[$ip] = $want;
        }
    }
}

/* Applique la dernière demande de chaque lampe, une fois sa file vide : une
 * rafale de paquets (curseur glissé sur un WLED) se résume à quelques ordres. */
function gvWledApply() {
    global $wledWant, $queues, $devices, $confirm;
    foreach ($wledWant as $ip => $want) {
        if (!isset($devices[$ip])) {
            unset($wledWant[$ip]);
            continue;
        }
        if (!empty($queues[$ip]) || gvClock() < $devices[$ip]['quietUntil']) {
            continue;
        }
        unset($wledWant[$ip]);
        $d = &$devices[$ip];
        $msgs = wledMessages($want, $d['status']);
        $d['wledAt'] = gvClock();
        if (!empty($msgs)) {
            foreach ($msgs as $m) {
                gvEnqueue($ip, $m);
            }
            $confirm[$ip] = gvClock() + count($msgs) * GAP + CONFIRM_AFTER;
            gvLog('info', $d['name'] . ' suit la synchro WLED : ' . wledSignature($want));
        }
        /* Ce qui est attendu de la lampe, pour que le relevé suivant ne soit
         * pas pris pour un changement à annoncer. */
        $merged = $want['on'] && is_array($d['status']) && $d['status']['on'] ? array_merge($d['status'], $want) : $want;
        if (isset($want['color'])) {
            unset($merged['kelvin']);
        } elseif (isset($want['kelvin'])) {
            unset($merged['color']);
        }
        $d['status'] = $merged;
        $d['sig'] = wledSignature($merged);
        unset($d);
    }
}

/* Un relevé de la lampe : s'il change quelque chose, les WLED du groupe en
 * sont informés. Le premier relevé (démarrage, retour en ligne) n'est pas
 * annoncé : redémarrer le démon ne doit rien changer chez les WLED. Pendant
 * trois secondes après un ordre venu d'un WLED non plus : les relevés
 * intermédiaires d'une rafale renverraient aux WLED un état déjà dépassé. */
function gvWledObserve($_ip, $_payload) {
    global $devices;
    $d = &$devices[$_ip];
    $status = wledFromStatus($_payload);
    $sig = wledSignature($status);
    $previous = $d['sig'];
    $d['status'] = $status;
    $d['sig'] = $sig;
    if ($previous === '' || $sig === $previous || $status === null || !$d['wled']['send']
        || gvClock() - $d['wledAt'] < 3) {
        return;
    }
    gvWledSend($_ip, $status, $d['wled']['groups']);
}

function gvWledSend($_ip, $_status, $_groups) {
    global $wledSock, $wledPort, $devices;
    $packet = wledPacket($_status, $_groups);
    if ($wledSock !== null) {
        foreach (gvWledDestinations() as $dest) {
            if (@stream_socket_sendto($wledSock, $packet, 0, $dest . ':' . $wledPort) === -1) {
                gvLog('debug', 'Annonce WLED vers ' . $dest . ' impossible');
            }
        }
    }
    gvLog('info', $devices[$_ip]['name'] . ' annoncée aux WLED (groupes ' . implode(',', gvWledGroupList($_groups)) . ') : ' . wledSignature($_status));
    gvFollowAnnounce($_ip, $_status);
    /* Nos broadcasts nous reviennent et sont ignorés : les autres lampes Govee
     * du groupe sont servies directement. */
    gvWledDispatch(wledParse($packet), $_ip);
}

/* ------------------------------------------------------------- SUIVI D'UN WLED */

/*
 * Une connexion WebSocket par WLED suivi, partagée par les lampes qui le
 * suivent : un ESP8266 ferme le plus ancien de ses clients au-delà de trois.
 * WLED y pousse son état complet ({state, info}) à chaque changement. Le
 * premier état après une connexion sert de référence et n'est pas appliqué :
 * redémarrer le démon ne doit pas changer la lampe. Un ping toutes les
 * trente secondes vérifie que la connexion vit ; une connexion perdue est
 * reprise en espaçant les essais jusqu'à deux minutes.
 */

$follows = array();     /* [adresse du WLED] => connexion */

/* Un WLED (surtout un ESP8266, limité à trois clients WebSocket) coupe
 * souvent la connexion : chaque appli ou page WLED ouverte éjecte le client
 * le plus ancien. Une coupure après une connexion qui a tenu FOLLOW_STABLE
 * secondes est donc une éjection, pas une panne : nouvel essai après
 * FOLLOW_RETRY secondes. Pendant FOLLOW_GRACE secondes, la lampe garde sa
 * dernière image ; ensuite seulement, la copie s'arrête et la lampe retrouve
 * son état d'avant. */
const FOLLOW_STABLE = 10;
const FOLLOW_RETRY = 1.5;
const FOLLOW_GRACE = 15;

function gvFollowSync() {
    global $devices, $follows;
    $wanted = array();
    foreach ($devices as $d) {
        if ($d['wled']['follow'] !== '') {
            $wanted[$d['wled']['follow']] = true;
        }
    }
    foreach (array_keys($wanted) as $addr) {
        if (!isset($follows[$addr])) {
            $parts = explode(':', $addr);
            $now = gvClock();
            $follows[$addr] = array('addr' => $addr, 'host' => $parts[0], 'port' => isset($parts[1]) ? (int) $parts[1] : 80,
                'sock' => null, 'state' => 'idle', 'buf' => '', 'frag' => null, 'out' => '', 'accept' => '',
                'fails' => 0, 'down' => false, 'next' => $now, 'last' => $now, 'pingAt' => $now, 'openedAt' => 0,
                'deadline' => 0, 'ref' => null, 'seg' => 0, 'on' => false, 'live' => false, 'liveAt' => -INF, 'liveAskedAt' => -INF,
                'lostAt' => 0, 'why' => '', 'drops' => array());
        }
    }
    foreach (array_keys($follows) as $addr) {
        if (!isset($wanted[$addr])) {
            if (is_resource($follows[$addr]['sock'])) {
                @fclose($follows[$addr]['sock']);
            }
            unset($follows[$addr]);
            gvLog('info', 'Fin du suivi du WLED ' . $addr);
        }
    }
}

function gvFollowClose(&$_c, $_why) {
    if (is_resource($_c['sock'])) {
        if ($_c['state'] === 'open') {
            @fwrite($_c['sock'], wsFrame(8, '', true));
        }
        @fclose($_c['sock']);
    }
    $now = gvClock();
    $evicted = $_c['state'] === 'open' && $now - $_c['openedAt'] >= FOLLOW_STABLE;
    $_c['fails'] = $evicted ? 0 : $_c['fails'] + 1;
    if ($_c['state'] === 'open') {
        /* Coupures de la dernière heure, pour la page Santé. */
        $_c['drops'][] = time();
    }
    $_c['why'] = $_why;
    if ($_c['lostAt'] === 0) {
        $_c['lostAt'] = $now;
    }
    $_c['sock'] = null;
    $_c['state'] = 'idle';
    $_c['buf'] = '';
    $_c['frag'] = null;
    $_c['out'] = '';
    $_c['ref'] = null;
    $_c['live'] = false;
    $delay = $evicted ? FOLLOW_RETRY : min(120, 5 * (1 << min(5, $_c['fails'] - 1)));
    $_c['next'] = $now + $delay;
    gvLog($evicted ? 'info' : 'debug', 'WLED ' . $_c['addr'] . ' : connexion coupée (' . $_why . '), nouvel essai dans ' . $delay . ' s');
}

/* Un WLED injoignable depuis FOLLOW_GRACE secondes : signalé une fois, et
 * les lampes qui le copiaient retrouvent leur état d'avant. */
function gvFollowLost(&$_c, $_now) {
    global $devices;
    if ($_c['state'] === 'open' || $_c['lostAt'] === 0 || $_c['down'] || $_now - $_c['lostAt'] < FOLLOW_GRACE) {
        return;
    }
    $_c['down'] = true;
    $copying = false;
    foreach ($devices as $d) {
        $copying = $copying || ($d['wled']['follow'] === $_c['addr'] && $d['razer']);
    }
    if ($copying) {
        gvLog('error', 'WLED ' . $_c['addr'] . ' injoignable depuis ' . FOLLOW_GRACE . ' s (' . $_c['why'] . ') : copie en direct interrompue, '
            . count($_c['drops']) . ' coupure(s) dans la dernière heure');
        gvRazerStopFollowers($_c['addr'], true);
    } else {
        gvLog('warning', 'WLED ' . $_c['addr'] . ' injoignable (' . $_c['why'] . '), nouvel essai en s\'espaçant');
    }
}

function gvFollowWrite(&$_c) {
    if ($_c['out'] === '' || !is_resource($_c['sock'])) {
        return;
    }
    $n = @fwrite($_c['sock'], $_c['out']);
    if ($n === false) {
        gvFollowClose($_c, $_c['state'] === 'handshake' ? 'connexion refusée' : 'écriture impossible');
        return;
    }
    $_c['out'] = (string) substr($_c['out'], $n);
}

function gvFollowSend(&$_c, $_data) {
    $_c['out'] .= $_data;
    gvFollowWrite($_c);
}

/* Connexions à ouvrir, délais, pings ; rend les sockets à surveiller. */
function gvFollowTick() {
    global $follows;
    $now = gvClock();
    $read = array();
    $write = array();
    foreach ($follows as $addr => &$c) {
        $c['drops'] = array_values(array_filter($c['drops'], function ($_t) {
            return $_t > time() - 3600;
        }));
        gvFollowLost($c, $now);
        if ($c['state'] === 'idle' && $now >= $c['next']) {
            $sock = @stream_socket_client('tcp://' . $c['host'] . ':' . $c['port'], $errno, $error, 0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            $c['state'] = 'connecting';
            if ($sock === false) {
                gvFollowClose($c, $error !== '' ? $error : 'connexion impossible');
                continue;
            }
            stream_set_blocking($sock, false);
            $c['sock'] = $sock;
            $c['deadline'] = $now + 5;
        }
        if ($c['state'] === 'connecting') {
            if ($now > $c['deadline']) {
                gvFollowClose($c, 'délai de connexion dépassé');
                continue;
            }
            $write[$addr] = $c['sock'];
            continue;
        }
        if ($c['state'] === 'handshake' && $now > $c['deadline']) {
            gvFollowClose($c, 'pas de réponse à la poignée de main');
            continue;
        }
        if ($c['state'] === 'handshake' || $c['state'] === 'open') {
            if ($now - $c['last'] > 75) {
                gvFollowClose($c, 'silence');
                continue;
            }
            if ($c['state'] === 'open' && $now - $c['pingAt'] > 30) {
                $c['pingAt'] = $now;
                gvFollowSend($c, wsFrame(9, 'govee', true));
                if ($c['state'] !== 'open') {
                    continue;
                }
            }
            $read[$addr] = $c['sock'];
            if ($c['out'] !== '') {
                $write[$addr] = $c['sock'];
            }
        }
    }
    unset($c);
    return array($read, $write);
}

/* La socket est prête en écriture : poignée de main, ou suite d'un envoi. */
function gvFollowWritable($_addr) {
    global $follows;
    $c = &$follows[$_addr];
    if ($c['state'] === 'connecting') {
        $key = base64_encode(random_bytes(16));
        $c['accept'] = wsAccept($key);
        $c['state'] = 'handshake';
        $c['last'] = gvClock();
        $c['deadline'] = gvClock() + 10;
        gvFollowSend($c, "GET /ws HTTP/1.1\r\nHost: " . $c['host'] . ($c['port'] !== 80 ? ':' . $c['port'] : '') . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
    } else {
        gvFollowWrite($c);
    }
}

function gvFollowReadable($_addr) {
    global $follows;
    $c = &$follows[$_addr];
    $chunk = @fread($c['sock'], 65536);
    if ($chunk === false || ($chunk === '' && feof($c['sock']))) {
        gvFollowClose($c, 'fermée par le WLED');
        return;
    }
    if ($chunk === '') {
        return;
    }
    $c['buf'] .= $chunk;
    $c['last'] = gvClock();
    if ($c['state'] === 'handshake') {
        $end = strpos($c['buf'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($c['buf']) > 8192) {
                gvFollowClose($c, 'réponse illisible');
            }
            return;
        }
        $head = substr($c['buf'], 0, $end);
        if (!preg_match('#^HTTP/1\.[01] 101 #', $head)
            || !preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) || $m[1] !== $c['accept']) {
            gvFollowClose($c, 'refusée : ' . strtok($head, "\r\n"));
            return;
        }
        $c['buf'] = (string) substr($c['buf'], $end + 4);
        $c['state'] = 'open';
        $c['openedAt'] = gvClock();
        $c['pingAt'] = gvClock();
        $c['lostAt'] = 0;
        if ($c['down']) {
            $c['down'] = false;
            gvLog('warning', 'WLED ' . $_addr . ' : connexion rétablie');
        } elseif (empty($c['drops'])) {
            gvLog('info', 'WLED ' . $_addr . ' suivi par WebSocket');
        }
    }
    foreach (wsParse($c['buf'], $c['frag']) as $event) {
        switch ($event[0]) {
            case 'ping':
                gvFollowSend($c, wsFrame(10, $event[1], true));
                break;
            case 'close':
                gvFollowClose($c, 'fermée par le WLED');
                return;
            case 'error':
                gvFollowClose($c, $event[1]);
                return;
            case 'text':
                $data = json_decode($event[1], true);
                if (is_array($data) && isset($data['state'])) {
                    gvFollowState($_addr, $data['state']);
                }
                break;
            case 'binary':
                gvFollowLive($_addr, $event[1]);
                break;
        }
    }
}

/* Un état poussé par le WLED : appliqué aux lampes qui le suivent, s'il
 * change ce que la lampe peut rendre (allumage, luminosité, couleur). */
function gvFollowState($_addr, $_state) {
    global $follows, $devices, $wledWant;
    $packet = wledFromJson($_state);
    if ($packet === null) {
        return;
    }
    $c = &$follows[$_addr];
    $c['seg'] = isset($_state['mainseg']) ? (int) $_state['mainseg'] : 0;
    $c['on'] = $packet['on'];
    if (!$packet['on']) {
        /* WLED éteint : la copie s'arrête, la lampe suit l'extinction. */
        gvRazerStopFollowers($_addr, false);
    }
    $ref = json_encode(array($packet['on'], $packet['bri'], $packet['rgb'], $packet['w'], $packet['fx'] === 0, $packet['kelvin'], $packet['cct']));
    $first = $c['ref'] === null;
    if ($ref === $c['ref']) {
        return;
    }
    $c['ref'] = $ref;
    if ($first) {
        gvLog('debug', 'WLED ' . $_addr . ' : état de référence ' . $ref);
        return;
    }
    gvLog('debug', '<- WLED ' . $_addr . ' (WebSocket) ' . $ref);
    foreach ($devices as $ip => $d) {
        /* L'écho d'une annonce de la lampe elle-même (WLED pousse son nouvel
         * état à tous ses clients) ne lui est pas réappliqué. */
        if ($d['wled']['follow'] === $_addr && $d['online'] !== false && gvClock() - $d['announcedAt'] > 2.5) {
            $want = wledWanted($packet, $d['wled']['k'][0], $d['wled']['k'][1]);
            /* Pendant la copie, la couleur vient de l'aperçu : seuls
             * l'allumage et la luminosité sont suivis. */
            if ($want !== null && $d['wled']['mirror'] !== '') {
                unset($want['color'], $want['kelvin']);
            }
            if ($want !== null) {
                $wledWant[$ip] = $want;
            }
        }
    }
}

/* ---------------------------------------------------- COPIE EN DIRECT */

/* Une image d'aperçu du WLED : rejouée sur chaque lampe qui le copie. */
function gvFollowLive($_addr, $_data) {
    global $follows, $devices;
    $c = &$follows[$_addr];
    $c['liveAt'] = gvClock();
    if (!$c['on']) {
        return;
    }
    $now = gvClock();
    foreach ($devices as $ip => &$d) {
        if ($d['wled']['follow'] !== $_addr || $d['wled']['mirror'] === '' || $d['online'] === false || $now < $d['quietUntil']) {
            continue;
        }
        $layout = ($d['wled']['mirror'] === 'spread' && $d['wled']['layout'] !== null)
            ? $d['wled']['layout'] : array('strip' => 1, 'ambient' => 0);
        $colors = wledLiveFrame($_data, $layout, $d['wled']['reverse']);
        if ($colors === null) {
            continue;
        }
        $frame = wledRazerFrame($colors);
        /* 30 images par seconde au plus ; une image inchangée n'est
         * renvoyée qu'une fois par seconde. */
        if ($now - $d['razerSentAt'] < 1 / 30 || ($frame === $d['razerLast'] && $now - $d['razerSentAt'] < 1)) {
            continue;
        }
        /* Le mode flux est (re)demandé à l'entrée, puis toutes les cinq
         * secondes : un ordre venu d'ailleurs a pu en faire sortir la lampe. */
        if (!$d['razer'] || $now - $d['razerOnAt'] > 5) {
            gvSendTo(RAZER_ON, $ip . ':4003');
            if (!$d['razer']) {
                gvLog('info', $d['name'] . ' copie en direct le WLED ' . $_addr);
            }
            $d['razer'] = true;
            $d['razerOnAt'] = $now;
        }
        gvSendTo($frame, $ip . ':4003');
        $d['razerSentAt'] = $now;
        $d['razerLast'] = $frame;
    }
    unset($d);
}

/* Sortie du flux : la lampe garde sa dernière image ; une seconde plus
 * tard, son état lui est redonné ($_restore), ou l'ordre qui attend (une
 * extinction) part. */
function gvRazerStop(&$_d, $_ip, $_restore) {
    if (!$_d['razer']) {
        return;
    }
    gvSendTo(RAZER_OFF, $_ip . ':4003');
    $_d['razer'] = false;
    $_d['razerLast'] = '';
    $_d['quietUntil'] = gvClock() + 1;
    $_d['restore'] = $_restore;
    gvLog('info', $_d['name'] . ' : fin de la copie en direct');
}

function gvRazerStopFollowers($_addr, $_restore) {
    global $devices;
    foreach ($devices as $ip => &$d) {
        if ($d['wled']['follow'] === $_addr) {
            gvRazerStop($d, $ip, $_restore);
        }
    }
    unset($d);
}

/* Lien de chaque lampe avec le WLED qu'elle suit (connexion ouverte ou
 * non), poussé à Jeedom quand il change (commande « WLED suivi joignable »). */
function gvFollowLinks() {
    global $devices, $follows, $states;
    foreach ($devices as $ip => &$d) {
        $addr = $d['wled']['follow'];
        $link = ($addr !== '' && isset($follows[$addr]) && $follows[$addr]['state'] === 'open') ? 1 : 0;
        if ($d['link'] !== $link) {
            $d['link'] = $link;
            $states[$d['eq']] = array_merge(isset($states[$d['eq']]) ? $states[$d['eq']] : array(), array('wled_link' => $link));
        }
    }
    unset($d);
}

/* Après la pause de sortie, l'état d'avant (dernier relevé) est renvoyé. */
function gvRazerTick() {
    global $devices, $follows, $confirm;
    $now = gvClock();
    foreach ($devices as $ip => &$d) {
        if ($d['restore'] && $now >= $d['quietUntil']) {
            $d['restore'] = false;
            if (is_array($d['status'])) {
                $msgs = wledMessages($d['status'], null);
                foreach ($msgs as $m) {
                    gvEnqueue($ip, $m);
                }
                $confirm[$ip] = $now + count($msgs) * GAP + CONFIRM_AFTER;
            }
        }
    }
    unset($d);
    /* L'aperçu est demandé au WLED tant qu'une lampe le copie. WLED ne le
     * donne qu'à un client : s'il se tait alors que le WLED est allumé (vue
     * « Peek » ouverte ailleurs), il est redemandé toutes les 30 s. */
    foreach ($follows as $addr => &$c) {
        if ($c['state'] !== 'open') {
            continue;
        }
        $wanted = false;
        foreach ($devices as $d) {
            $wanted = $wanted || ($d['wled']['follow'] === $addr && $d['wled']['mirror'] !== '');
        }
        $silent = $c['live'] && $c['on'] && $now - $c['liveAt'] > 5 && $now - $c['liveAskedAt'] > 30;
        if ($wanted !== $c['live'] || ($wanted && $silent)) {
            gvFollowSend($c, wsFrame(1, $wanted ? '{"lv":true}' : '{"lv":false}', true));
            $c['live'] = $wanted;
            $c['liveAskedAt'] = $now;
            $c['liveAt'] = $now;
        }
    }
    unset($c);
}

/* Une lampe qui annonce renvoie ses changements au WLED qu'elle suit. */
function gvFollowAnnounce($_ip, $_status) {
    global $follows, $devices;
    $addr = $devices[$_ip]['wled']['follow'];
    if ($addr === '' || !isset($follows[$addr]) || $follows[$addr]['state'] !== 'open') {
        return;
    }
    $c = &$follows[$addr];
    if (!$_status['on']) {
        $body = array('on' => false);
    } else {
        $rgb = isset($_status['color']) ? $_status['color'] : (isset($_status['kelvin']) ? wledKelvinToRgb($_status['kelvin']) : array(255, 255, 255));
        $seg = array('id' => $c['seg'], 'on' => true, 'col' => array($rgb), 'fx' => 0);
        if (isset($_status['kelvin'])) {
            $seg['cct'] = (int) $_status['kelvin'];
        }
        $body = array('on' => true, 'bri' => max(1, (int) round((isset($_status['brightness']) ? $_status['brightness'] : 100) * 255 / 100)),
                      'seg' => array($seg));
    }
    $devices[$_ip]['announcedAt'] = gvClock();
    gvFollowSend($c, wsFrame(1, json_encode($body), true));
}

/* ------------------------------------------------------------- ÉMULATION WLED */

/*
 * Ce que l'appli WLED appelle (relevé dans son code) : GET /json/info au
 * premier contact, puis tout passe par /ws, où elle attend {state, info} à
 * l'ouverture et à chaque changement, et envoie ses ordres ({"on":…},
 * {"bri":…}) ; ses widgets font POST /json/state. Son écran de commande est
 * la page servie à « / ». Le reste de l'API (/json, /json/si, /json/eff…)
 * est servi pour les autres clients WLED. Une réponse HTTP par connexion.
 *
 * Garde-fous, l'API répondant à tout le réseau local :
 *   - nombre de connexions borné (stream_select ne voit pas au-delà du
 *     descripteur 1023 : le démon entier s'arrêterait de servir) ;
 *   - écritures non bloquantes, tampon de sortie borné par connexion ;
 *   - WebSocket sans trafic depuis 90 s fermé (l'appli envoie un ping toutes
 *     les 30 s, le démon aussi) ;
 *   - un seul ordre en cours chez Jeedom par lampe : une rafale (curseur
 *     glissé) se résume au dernier ordre de chaque sorte ;
 *   - une page web d'une autre origine ne peut ni ouvrir le WebSocket ni
 *     envoyer d'ordre (contrôle de l'en-tête Origin, pas de CORS).
 */

const API_PAGE = __DIR__ . '/www/index.html';
const API_MAX_CONNS = 32;
const API_MAX_PER_LAMP = 12;
const API_OUT_MAX = 262144;
const API_WS_IDLE = 90;
const API_PENDING_MAX = 8;

$apis = array();        /* [ip de la lampe] => array(eq, port, sock, json, clients, busy, pending) */
$conns = array();       /* connexions HTTP et WebSocket des clients WLED */
$connSeq = 0;
$jobs = array();        /* requêtes curl en cours vers Jeedom (ordres WLED, relevés) */
$multi = curl_multi_init();
$startedAt = gvClock();

function gvApiSync() {
    global $devices, $apis;
    /* Fermer d'abord : une lampe qui change d'adresse garde son port. */
    foreach (array_keys($apis) as $ip) {
        $d = isset($devices[$ip]) ? $devices[$ip] : null;
        if ($d === null || $d['api'] === null || $d['api']['port'] !== $apis[$ip]['port']) {
            gvLog('info', 'Fin de l\'émulation WLED sur le port ' . $apis[$ip]['port']);
            gvApiClose($ip);
        }
    }
    foreach ($devices as $ip => $d) {
        if ($d['api'] === null) {
            continue;
        }
        if (!isset($apis[$ip])) {
            $sock = @stream_socket_server('tcp://0.0.0.0:' . $d['api']['port'], $errno, $error);
            if ($sock === false) {
                gvLog('error', 'Émulation WLED de ' . $d['name'] . ' impossible : port TCP ' . $d['api']['port'] . ' pris (' . $error . ')');
                continue;
            }
            stream_set_blocking($sock, false);
            $apis[$ip] = array('eq' => $d['eq'], 'port' => $d['api']['port'], 'sock' => $sock, 'json' => null,
                               'clients' => array(), 'busy' => false, 'pending' => array());
            gvLog('info', $d['name'] . ' se présente comme un WLED sur le port ' . $d['api']['port']);
        }
        gvApiUpdate(array($d['eq'] => $d['api']['json']));
    }
}

function gvApiClose($_ip) {
    global $apis, $conns;
    foreach ($conns as $id => $c) {
        if ($c['ip'] === $_ip) {
            gvConnClose($id);
        }
    }
    @fclose($apis[$_ip]['sock']);
    unset($apis[$_ip]);
}

/* Nouvel état WLED de lampes ([eq] => {state, info, effects}) : retenu, et
 * poussé aux WebSocket ouverts s'il a changé, ou si $_force. */
function gvApiUpdate($_byEq, $_force = false) {
    global $apis;
    foreach ($_byEq as $eq => $json) {
        if (!is_array($json) || !isset($json['state'], $json['info'], $json['effects'])) {
            continue;
        }
        foreach ($apis as $ip => &$api) {
            if ($api['eq'] !== (int) $eq) {
                continue;
            }
            $changed = $api['json'] === null || json_encode($api['json']['state']) !== json_encode($json['state']);
            $api['json'] = $json;
            if ($changed || $_force) {
                foreach (array_keys($api['clients']) as $id) {
                    gvWsPush($id);
                }
            }
        }
        unset($api);
    }
}

/* {state, info} d'une lampe, avec ce que seul le démon sait. */
function gvApiFull($_ip, $_sock) {
    global $apis, $startedAt;
    $json = $apis[$_ip]['json'];
    $info = $json['info'];
    $info['ws'] = count($apis[$_ip]['clients']);
    $info['uptime'] = (int) (gvClock() - $startedAt);
    $local = @stream_socket_get_name($_sock, false);
    $info['ip'] = is_string($local) ? (string) preg_replace('/:\d+$/', '', $local) : '';
    return array('state' => $json['state'], 'info' => $info);
}

function gvApiAccept($_ip) {
    global $apis, $conns, $connSeq;
    while (($c = @stream_socket_accept($apis[$_ip]['sock'], 0, $peer)) !== false) {
        $mine = 0;
        foreach ($conns as $x) {
            $mine += $x['ip'] === $_ip ? 1 : 0;
        }
        if (count($conns) >= API_MAX_CONNS || $mine >= API_MAX_PER_LAMP) {
            @fwrite($c, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            @fclose($c);
            /* Une ligne par minute au plus : un client qui insiste ne doit
             * pas remplir le journal. */
            static $warnedAt = -INF;
            if (gvClock() - $warnedAt > 60) {
                $warnedAt = gvClock();
                gvLog('warning', 'Émulation WLED : trop de connexions (' . API_MAX_CONNS . ' au total, ' . API_MAX_PER_LAMP . ' par lampe), ' . $peer . ' refusé');
            }
            continue;
        }
        stream_set_blocking($c, false);
        $conns[++$connSeq] = array('sock' => $c, 'ip' => $_ip, 'peer' => (string) $peer, 'buf' => '', 'out' => '',
                                   'mode' => 'http', 'closeAfter' => false, 'deadline' => gvClock() + 10,
                                   'frag' => null, 'last' => gvClock(), 'pingAt' => gvClock());
    }
}

function gvConnClose($_id) {
    global $conns, $apis;
    if (!isset($conns[$_id])) {
        return;
    }
    $c = $conns[$_id];
    @fclose($c['sock']);
    if (isset($apis[$c['ip']])) {
        unset($apis[$c['ip']]['clients'][$_id]);
    }
    unset($conns[$_id]);
}

/* Écrit ce que la socket accepte ; le reste attend qu'elle soit prête. Un
 * client qui ne lit plus (tampon plein) est lâché. */
function gvConnFlush($_id) {
    global $conns;
    $c = &$conns[$_id];
    if ($c['out'] !== '') {
        $n = @fwrite($c['sock'], $c['out']);
        if ($n === false) {
            unset($c);
            gvConnClose($_id);
            return;
        }
        $c['out'] = (string) substr($c['out'], $n);
    }
    if ($c['out'] === '' && $c['closeAfter']) {
        unset($c);
        gvConnClose($_id);
    }
}

function gvConnSend($_id, $_data) {
    global $conns;
    if (!isset($conns[$_id])) {
        return;
    }
    if (strlen($conns[$_id]['out']) + strlen($_data) > API_OUT_MAX) {
        gvLog('debug', 'Client WLED ' . $conns[$_id]['peer'] . ' ne lit plus : lâché');
        gvConnClose($_id);
        return;
    }
    $conns[$_id]['out'] .= $_data;
    gvConnFlush($_id);
}

function gvHttpReply($_id, $_code, $_type, $_body, $_head = false) {
    global $conns;
    $reasons = array(200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found',
                     405 => 'Method Not Allowed', 503 => 'Service Unavailable');
    $head = 'HTTP/1.1 ' . $_code . ' ' . (isset($reasons[$_code]) ? $reasons[$_code] : 'OK') . "\r\n"
          . ($_type !== '' ? 'Content-Type: ' . $_type . "\r\n" : '')
          . 'Content-Length: ' . strlen($_body) . "\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
    if (!isset($conns[$_id])) {
        return;
    }
    $conns[$_id]['mode'] = 'done';
    $conns[$_id]['closeAfter'] = true;
    $conns[$_id]['deadline'] = gvClock() + 10;
    gvConnSend($_id, $head . ($_head ? '' : $_body));
}

function gvHttpJson($_id, $_data, $_head = false) {
    gvHttpReply($_id, 200, 'application/json', json_encode($_data), $_head);
}

function gvWsPush($_id, $_error = '') {
    global $conns;
    if (!isset($conns[$_id]) || $conns[$_id]['mode'] !== 'ws') {
        return;
    }
    $full = gvApiFull($conns[$_id]['ip'], $conns[$_id]['sock']);
    if ($_error !== '') {
        $full['state']['gv']['error'] = $_error;
    }
    gvConnSend($_id, wsFrame(1, json_encode($full), false));
}

/* Une page web d'une autre origine ne pilote pas la lampe : l'en-tête
 * Origin, quand il est là, doit désigner cette même adresse. L'appli WLED
 * (hors page) n'en envoie pas. */
function gvOriginOk($_headers) {
    if (!isset($_headers['origin']) || $_headers['origin'] === '' || $_headers['origin'] === 'null') {
        return true;
    }
    $host = (string) parse_url($_headers['origin'], PHP_URL_HOST);
    $port = parse_url($_headers['origin'], PHP_URL_PORT);
    return isset($_headers['host']) && strcasecmp($host . ($port ? ':' . $port : ''), $_headers['host']) === 0;
}

function gvConnRead($_id) {
    global $conns;
    $c = &$conns[$_id];
    $chunk = @fread($c['sock'], 65536);
    if ($chunk === false || ($chunk === '' && feof($c['sock']))) {
        unset($c);
        gvConnClose($_id);
        return;
    }
    if ($chunk === '') {
        return;
    }
    $c['last'] = gvClock();
    if ($c['mode'] !== 'http' && $c['mode'] !== 'ws') {
        return;                             /* réponse en cours : le reste est ignoré */
    }
    $c['buf'] .= $chunk;
    if ($c['mode'] === 'ws') {
        $ip = $c['ip'];
        unset($c);
        gvWsEvents($_id, $ip);
        return;
    }
    if (strlen($c['buf']) > 65536) {
        unset($c);
        gvHttpReply($_id, 400, 'text/plain', 'Too large');
        return;
    }
    $end = strpos($c['buf'], "\r\n\r\n");
    if ($end === false) {
        return;
    }
    $lines = explode("\r\n", substr($c['buf'], 0, $end));
    $request = explode(' ', array_shift($lines));
    $headers = array();
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    if (isset($headers['transfer-encoding'])) {
        unset($c);
        gvHttpReply($_id, 400, 'text/plain', 'Chunked bodies not supported');
        return;
    }
    $length = isset($headers['content-length']) ? max(0, (int) $headers['content-length']) : 0;
    if (strlen($c['buf']) < $end + 4 + $length) {
        return;
    }
    $body = (string) substr($c['buf'], $end + 4, $length);
    /* Ce qui suit la requête (premières trames d'un WebSocket) reste. */
    $c['buf'] = (string) substr($c['buf'], $end + 4 + $length);
    unset($c);
    $path = isset($request[1]) ? parse_url($request[1], PHP_URL_PATH) : null;
    if (!is_string($path)) {
        gvHttpReply($_id, 400, 'text/plain', 'Bad request');
        return;
    }
    gvHttpRoute($_id, strtoupper(isset($request[0]) ? $request[0] : ''), $path, $headers, $body);
}

function gvWsEvents($_id, $_ip) {
    global $conns;
    foreach (wsParse($conns[$_id]['buf'], $conns[$_id]['frag']) as $event) {
        switch ($event[0]) {
            case 'ping':
                gvConnSend($_id, wsFrame(10, $event[1], false));
                break;
            case 'close':
                gvConnSend($_id, wsFrame(8, '', false));
                if (isset($conns[$_id])) {
                    $conns[$_id]['mode'] = 'done';
                    $conns[$_id]['closeAfter'] = true;
                    gvConnFlush($_id);
                }
                return;
            case 'error':
                gvConnClose($_id);
                return;
            case 'text':
                $body = json_decode($event[1], true);
                if (!is_array($body)) {
                    break;
                }
                unset($body['v'], $body['time']);
                if (empty($body)) {
                    gvWsPush($_id);         /* {"v":true} : l'état, à ce seul client */
                } else {
                    gvApiOrder($_ip, $body, $_id, 'ws', false);
                }
                break;
        }
        if (!isset($conns[$_id])) {
            return;
        }
    }
}

function gvHttpRoute($_id, $_method, $_path, $_headers, $_body) {
    global $conns, $apis;
    $ip = $conns[$_id]['ip'];
    $path = rtrim($_path, '/');
    $head = $_method === 'HEAD';
    if ($apis[$ip]['json'] === null) {
        gvHttpReply($_id, 503, 'text/plain', 'Starting', $head);
        return;
    }
    $json = $apis[$ip]['json'];
    if ($_method === 'POST' && in_array($path, array('/json/state', '/json', '/json/si'), true)) {
        if (!gvOriginOk($_headers)) {
            gvHttpReply($_id, 403, 'text/plain', 'Forbidden origin');
            return;
        }
        $order = json_decode($_body, true);
        if (!is_array($order)) {
            gvHttpReply($_id, 400, 'application/json', '{"error":9}');
            return;
        }
        $v = !empty($order['v']);
        unset($order['v'], $order['time']);
        if (empty($order)) {
            gvHttpJson($_id, $v ? $json['state'] : array('success' => true));
            return;
        }
        $conns[$_id]['mode'] = 'wait';
        $conns[$_id]['deadline'] = gvClock() + 20;
        gvApiOrder($ip, $order, $_id, 'http', $v);
        return;
    }
    if ($_method !== 'GET' && $_method !== 'HEAD') {
        gvHttpReply($_id, 405, 'text/plain', 'Method not allowed');
        return;
    }
    switch ($path) {
        case '':
        case '/index.htm':
        case '/index.html':
            $page = @file_get_contents(API_PAGE);
            gvHttpReply($_id, $page === false ? 404 : 200, 'text/html; charset=utf-8', $page === false ? 'Page absente' : $page, $head);
            return;
        case '/ws':
            if (!isset($_headers['sec-websocket-key']) || stripos(isset($_headers['upgrade']) ? $_headers['upgrade'] : '', 'websocket') === false) {
                gvHttpReply($_id, 400, 'text/plain', 'WebSocket expected');
                return;
            }
            if (!gvOriginOk($_headers)) {
                gvHttpReply($_id, 403, 'text/plain', 'Forbidden origin');
                return;
            }
            $conns[$_id]['mode'] = 'ws';
            $conns[$_id]['deadline'] = INF;
            $conns[$_id]['last'] = gvClock();
            $conns[$_id]['pingAt'] = gvClock();
            $apis[$ip]['clients'][$_id] = true;
            gvConnSend($_id, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . 'Sec-WebSocket-Accept: ' . wsAccept($_headers['sec-websocket-key']) . "\r\n\r\n");
            gvLog('debug', 'WebSocket WLED ouvert par ' . $conns[$_id]['peer']);
            gvWsPush($_id);
            if (isset($conns[$_id]) && $conns[$_id]['buf'] !== '') {
                gvWsEvents($_id, $ip);
            }
            return;
        case '/json':
            $full = gvApiFull($ip, $conns[$_id]['sock']);
            gvHttpJson($_id, $full + array('effects' => $json['effects'], 'palettes' => array('Default')), $head);
            return;
        case '/json/si':
            gvHttpJson($_id, gvApiFull($ip, $conns[$_id]['sock']), $head);
            return;
        case '/json/state':
            gvHttpJson($_id, $json['state'], $head);
            return;
        case '/json/info':
            gvHttpJson($_id, gvApiFull($ip, $conns[$_id]['sock'])['info'], $head);
            return;
        case '/json/eff':
        case '/json/effects':
            gvHttpJson($_id, $json['effects'], $head);
            return;
        case '/json/pal':
        case '/json/palettes':
            gvHttpJson($_id, array('Default'), $head);
            return;
        case '/json/fxdata':
            gvHttpJson($_id, array_fill(0, count($json['effects']), ''), $head);
            return;
        case '/json/nodes':
            gvHttpJson($_id, array('nodes' => array()), $head);
            return;
        case '/presets.json':
            gvHttpJson($_id, array('0' => new stdClass()), $head);
            return;
    }
    gvHttpReply($_id, 404, 'text/plain', 'Not found', $head);
}

/*
 * Un ordre de l'appli pour une lampe. Un seul part à la fois chez Jeedom ;
 * les suivants attendent, et un ordre de même sorte (mêmes clés, même
 * commande « gv ») remplace celui qui attend : d'une rafale de curseur ne
 * part que la dernière valeur. Chaque ordre garde la liste de qui attend sa
 * réponse (connexion HTTP, ou client WebSocket).
 */
function gvApiOrder($_ip, $_order, $_conn, $_kind, $_v) {
    global $apis;
    $api = &$apis[$_ip];
    $shape = json_encode(array(array_keys($_order), isset($_order['gv']['cmd']) ? $_order['gv']['cmd'] : '',
                               isset($_order['seg']) && is_array($_order['seg']) ? array_keys($_order['seg']) : null));
    $waiter = array('conn' => $_conn, 'kind' => $_kind, 'v' => $_v);
    $last = count($api['pending']) - 1;
    if ($last >= 0 && $api['pending'][$last]['shape'] === $shape) {
        $api['pending'][$last]['order'] = $_order;
        $api['pending'][$last]['waiters'][] = $waiter;
    } elseif (count($api['pending']) >= API_PENDING_MAX) {
        gvLog('warning', 'Émulation WLED : trop d\'ordres en attente, ordre ignoré');
        unset($api);
        gvApiAnswer($_ip, array($waiter), 'trop d\'ordres en attente');
        return;
    } else {
        $api['pending'][] = array('order' => $_order, 'shape' => $shape, 'waiters' => array($waiter));
    }
    unset($api);
    gvApiNext($_ip);
}

/* Lance l'ordre suivant d'une lampe si aucun n'est en cours chez Jeedom. */
function gvApiNext($_ip) {
    global $apis, $jobs, $multi;
    if (!isset($apis[$_ip]) || $apis[$_ip]['busy'] || empty($apis[$_ip]['pending'])) {
        return;
    }
    $item = array_shift($apis[$_ip]['pending']);
    $apis[$_ip]['busy'] = true;
    gvLog('debug', 'Ordre WLED pour ' . $_ip . ' : ' . json_encode($item['order']));
    $ch = gvCurl('action=wled&eq=' . $apis[$_ip]['eq']);
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($item['order']),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    curl_multi_add_handle($multi, $ch);
    $jobs[(int) $ch] = array('type' => 'wled', 'ch' => $ch, 'ip' => $_ip, 'waiters' => $item['waiters']);
}

/* Réponse aux clients qui attendaient un ordre : l'état de la lampe, et la
 * raison d'un refus. */
function gvApiAnswer($_ip, $_waiters, $_error) {
    global $apis, $conns;
    foreach ($_waiters as $w) {
        $id = $w['conn'];
        if ($id === null || !isset($conns[$id])) {
            continue;
        }
        if ($w['kind'] === 'ws') {
            /* L'état vient d'être poussé à tous : seul un refus est renvoyé,
             * à ce client. */
            if ($_error !== '') {
                gvWsPush($id, $_error);
            }
        } elseif ($conns[$id]['mode'] === 'wait') {
            $state = $apis[$_ip]['json']['state'];
            if ($_error !== '') {
                $state['gv']['error'] = $_error;
            }
            gvHttpJson($id, $w['v'] ? $state : array('success' => $_error === ''));
        }
    }
}

/* Réponses de Jeedom arrivées : ordres WLED et relevés poussés. */
function gvJobs() {
    global $jobs, $multi, $apis;
    if (empty($jobs)) {
        return;
    }
    do {
        $status = curl_multi_exec($multi, $active);
    } while ($status === CURLM_CALL_MULTI_PERFORM);
    while (($done = curl_multi_info_read($multi)) !== false) {
        $ch = $done['handle'];
        $job = isset($jobs[(int) $ch]) ? $jobs[(int) $ch] : null;
        unset($jobs[(int) $ch]);
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        gvRefused($code);
        if ($job === null) {
            continue;
        }
        if ($job['type'] === 'push') {
            gvPushDone($job, $code, (string) $body, $err);
            continue;
        }
        if (!isset($apis[$job['ip']])) {
            continue;
        }
        $apis[$job['ip']]['busy'] = false;
        $reply = $code === 200 ? json_decode((string) $body, true) : null;
        $error = '';
        if (is_array($reply) && array_key_exists('ok', $reply)) {
            /* Poussé à tous, même sans changement : l'appli affiche d'avance
             * ce qu'elle a demandé, et doit voir ce qui a été retenu. */
            $wled = is_array($reply['wled'] ?? null) ? $reply['wled'] : $apis[$job['ip']]['json'];
            gvApiUpdate(array($apis[$job['ip']]['eq'] => $wled), true);
            if (empty($reply['ok'])) {
                $error = isset($reply['error']) ? (string) $reply['error'] : 'refusé';
                gvLog('warning', 'Ordre WLED refusé par Jeedom : ' . $error);
            }
        } else {
            $error = 'Jeedom injoignable';
            gvLog('warning', 'Ordre WLED : Jeedom injoignable (HTTP ' . $code . ' ' . $err . ')');
            gvApiUpdate(array($apis[$job['ip']]['eq'] => $apis[$job['ip']]['json']), true);
        }
        gvApiAnswer($job['ip'], $job['waiters'], $error);
        gvApiNext($job['ip']);
    }
}

/* Délais : requêtes HTTP trop lentes, WebSocket muets, pings. */
function gvConnTimeouts() {
    global $conns;
    $now = gvClock();
    foreach (array_keys($conns) as $id) {
        if (!isset($conns[$id])) {
            continue;
        }
        $c = $conns[$id];
        if ($c['mode'] === 'ws') {
            if ($now - $c['last'] > API_WS_IDLE) {
                gvLog('debug', 'WebSocket WLED de ' . $c['peer'] . ' muet : fermé');
                gvConnClose($id);
            } elseif ($now - $c['pingAt'] > 30) {
                $conns[$id]['pingAt'] = $now;
                gvConnSend($id, wsFrame(9, 'govee', false));
            }
        } elseif ($now > $c['deadline']) {
            gvConnClose($id);
        }
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
            /* Lampe émulée : le relevé de confirmation est poussé même sans
             * changement, sa réponse rapporte l'état WLED (zones, effet). */
            if (isset($devices[$ip]) && $devices[$ip]['api'] !== null) {
                $devices[$ip]['last'] = '';
            }
            /* Relevé de confirmation, une fois la file vidée. */
            global $queues;
            $confirm[$ip] = gvClock() + (isset($queues[$ip]) ? count($queues[$ip]) : 0) * GAP + CONFIRM_AFTER;
            gvReply($_client, array('ok' => true, 'queued' => count($req['msgs'])));
            return;
        case 'status':
            /* Pour la page Santé : suivi WLED, copie, émulation, par lampe. */
            global $follows, $apis, $wledSock, $wledPort;
            $list = array();
            foreach ($devices as $ip => $d) {
                $addr = $d['wled']['follow'];
                $f = ($addr !== '' && isset($follows[$addr])) ? $follows[$addr] : null;
                $list[] = array(
                    'eq'      => $d['eq'],
                    'ip'      => $ip,
                    'online'  => $d['online'],
                    'follow'  => $addr,
                    'link'    => $f === null ? null : $f['state'],
                    'drops'   => $f === null ? 0 : count($f['drops']),
                    'dropAt'  => ($f === null || empty($f['drops'])) ? 0 : end($f['drops']),
                    'why'     => $f === null ? '' : $f['why'],
                    'mirror'  => $d['wled']['mirror'],
                    'razer'   => $d['razer'],
                    'api'     => $d['api'] === null ? null : array('port' => $d['api']['port'], 'listening' => isset($apis[$ip]),
                                                              'clients' => isset($apis[$ip]) ? count($apis[$ip]['clients']) : 0),
                    'sync'    => $d['wled']['recv'] || $d['wled']['send'],
                );
            }
            gvReply($_client, array('ok' => true, 'devices' => $list, 'syncPort' => $wledPort, 'syncOpen' => $wledSock !== null));
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
    gvFollowLinks();
    gvRazerTick();
    gvWledApply();
    gvFlushQueues();

    $read = array($udp, $ctl);
    if ($wledSock !== null) {
        $read[] = $wledSock;
    }
    foreach ($clients as $c) {
        $read[] = $c['sock'];
    }
    foreach ($apis as $api) {
        $read[] = $api['sock'];
    }
    $connWrite = array();
    foreach ($conns as $id => $c) {
        $read[] = $c['sock'];
        if ($c['out'] !== '') {
            $connWrite[$id] = $c['sock'];
        }
    }
    list($followRead, $followWrite) = gvFollowTick();
    $read = array_merge($read, array_values($followRead));
    $write = array_merge(array_values($followWrite), array_values($connWrite));
    if (empty($write)) {
        $write = null;
    }
    $except = null;
    /* Assez court pour respecter l'écart de 150 ms entre deux envois. */
    $wait = !empty($jobs) ? 20000 : ((empty($queues) && empty($wledWant)) ? 200000 : 50000);
    $n = @stream_select($read, $write, $except, 0, $wait);
    if ($n === false) {
        /* Ne doit plus arriver (connexions bornées) ; jamais de boucle folle.
         * Le signal d'arrêt interrompt aussi l'attente : pas une erreur. */
        static $selectWarned = false;
        if ($running && !$selectWarned) {
            gvLog('error', 'stream_select en échec (' . count($read) . ' sockets) : vérifiez le nombre de connexions');
            $selectWarned = true;
        }
        usleep(100000);
        $read = array();
        $write = null;
    }
    if ($n !== false && $n > 0) {
        if (in_array($udp, $read, true)) {
            gvReadUdp();
        }
        if (in_array($ctl, $read, true)) {
            gvAccept();
        }
        if ($wledSock !== null && in_array($wledSock, $read, true)) {
            gvWledRead();
        }
        foreach ($followWrite as $addr => $sock) {
            if (is_array($write) && in_array($sock, $write, true) && isset($follows[$addr]) && $follows[$addr]['sock'] === $sock) {
                gvFollowWritable($addr);
            }
        }
        foreach ($apis as $ip => $api) {
            if (in_array($api['sock'], $read, true)) {
                gvApiAccept($ip);
            }
        }
        foreach ($connWrite as $id => $sock) {
            if (is_array($write) && in_array($sock, $write, true) && isset($conns[$id]) && $conns[$id]['sock'] === $sock) {
                gvConnFlush($id);
            }
        }
        foreach (array_keys($conns) as $id) {
            if (isset($conns[$id]) && in_array($conns[$id]['sock'], $read, true)) {
                gvConnRead($id);
            }
        }
        foreach ($followRead as $addr => $sock) {
            if (in_array($sock, $read, true) && isset($follows[$addr]) && $follows[$addr]['sock'] === $sock) {
                gvFollowReadable($addr);
            }
        }
    }
    gvReadClients(is_array($read) ? $read : array());
    gvFinishScans();
    gvJobs();
    gvConnTimeouts();
    gvPush();
}

/* Lampes en copie : sorties du flux et rendues à leur état. */
$released = false;
foreach ($devices as $ip => &$d) {
    if ($d['razer']) {
        gvSendTo(RAZER_OFF, $ip . ':4003');
        $released = true;
    }
}
unset($d);
if ($released) {
    sleep(1);
    foreach ($devices as $ip => $d) {
        if ($d['razer'] && is_array($d['status'])) {
            foreach (wledMessages($d['status'], null) as $m) {
                gvSendTo($m, $ip . ':4003');
                usleep(200000);
            }
        }
    }
}
foreach ($clients as $c) {
    @fclose($c['sock']);
}
foreach ($follows as $c) {
    if (is_resource($c['sock'])) {
        @fclose($c['sock']);
    }
}
foreach (array_keys($apis) as $ip) {
    gvApiClose($ip);
}
curl_multi_close($multi);
@fclose($ctl);
@fclose($udp);
if ($wledSock !== null) {
    @fclose($wledSock);
}
@unlink($pidFile);
gvLog('info', 'Arrêt du démon Govee');
