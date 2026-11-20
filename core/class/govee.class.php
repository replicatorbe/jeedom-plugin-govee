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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* Codec de la synchro WLED, partagé avec le démon. */
require_once __DIR__ . '/../../resources/goveed/wledsync.php';

/*
 * Pilotage local des lampes Govee Wi-Fi, par l'API LAN (UDP), sans cloud.
 *
 * Deux familles d'ordres :
 *
 *   - Les commandes documentées par Govee (turn, brightness, colorwc,
 *     devStatus). Elles valent pour toute lampe qui a l'option « LAN Control »
 *     activée dans l'application, et la lampe renvoie son état global :
 *     allumée, luminosité, couleur ou température de blanc.
 *
 *   - Les trames « ptReal » : les octets que l'application Govee envoie en
 *     Bluetooth, encapsulés dans l'API LAN. Elles seules pilotent les zones
 *     d'un lampadaire Uplighter (H60B0) : zone haute (effet projeté au
 *     plafond), anneau latéral, éclairage bas. La lampe n'en renvoie pas
 *     l'état : celui des zones est le dernier ordre envoyé.
 *
 * Tout ordre passe par le démon, qui seul peut recevoir les réponses (port
 * 4002 fixe) et espace les envois. Démon arrêté, le plugin envoie lui-même, et
 * le cron relève l'état chaque minute.
 *
 * L'identifiant logique d'un équipement est l'identifiant Govee de la lampe
 * (« device », huit octets) : une adresse IP qui change est retrouvée par la
 * recherche sans recréer l'équipement.
 */
class govee extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const DEFAULT_PORT = 55241;
    const DEFAULT_INTERVAL = 10;
    /* Port de la synchro WLED (« UDP notifier »), celui de WLED par défaut. */
    const DEFAULT_WLED_PORT = 21324;
    /* Port HTTP par défaut d'une lampe émulée en WLED (appli WLED). */
    const WLED_API_PORT = 8180;
    /* Ports qu'une lampe émulée ne peut pas prendre. */
    const WLED_API_RESERVED = array(80, 443, 4001, 4002, 4003, 21324);
    /* Segments acceptés par le flux temps réel (« razer ») : au-delà, la
     * lampe rejette la trame et s'éteint. Relevé sur la lampe (H60B0 : 7,
     * du bas vers le haut ; 8 rejetés). Un modèle absent ne copie qu'en
     * couleur moyenne. */
    const RAZER_SEGMENTS = array('H60B0' => 7);
    /* Zones d'un Uplighter, dans l'ordre des segments WLED. */
    const WLED_ZONE_ORDER = array('top', 'side', 'bottom');
    /* LED fictives par segment : l'appli n'en affiche que le nombre. */
    const WLED_SEG_LEN = 30;

    /* Écart minimal entre deux datagrammes vers une même lampe, quand le
     * plugin envoie lui-même (démon arrêté). */
    const GAP_US = 150000;

    /* Trames ptReal par datagramme : au-delà d'une vingtaine, la lampe perd le
     * paquet entier. */
    const MAX_FRAMES = 12;

    /* Modèles au pilotage par zones connu. Les codes viennent du projet
     * govee-lan-bridge, relevés sur un vrai H60B0 et vérifiés ici. */
    const UPLIGHTER_SKUS = array('H60B0');

    /* Zones d'un Uplighter : numéro dans les trames, couleur possible, nom. */
    const ZONES = array(
        'top'    => array('id' => 1, 'rgb' => true,  'name' => 'Zone haute'),
        'side'   => array('id' => 2, 'rgb' => true,  'name' => 'Anneau latéral'),
        'bottom' => array('id' => 3, 'rgb' => false, 'name' => 'Éclairage bas'),
    );

    /* Hors de cette plage, un H60B0 s'éteint au lieu de changer de blanc. */
    const UPLIGHTER_K = array(2700, 6500);
    /* Plage de l'API LAN pour les autres modèles. */
    const GENERIC_K = array(2000, 9000);

    /* Bibliothèque publique des effets (« scènes ») de l'application Govee,
     * par modèle. Lue sans compte : seule la description des effets en vient,
     * leur envoi reste local. */
    const SCENES_URL = 'https://app2.govee.com/appsku/v1/light-effect-libraries?sku=';

    /* Modes musique d'un Uplighter, au son de son micro intégré. Rythme et
     * Spectre vérifiés sur la lampe ; les autres suivent la même trame. */
    const MUSIC_MODES = array(
        114 => 'Rythme',
        131 => 'Spectre',
        48  => 'Éclosion',
        49  => 'Scintillement',
        51  => 'Sauts',
        80  => 'Vortex',
        81  => 'Lucioles',
        83  => 'Flux',
    );
    const MUSIC_DEFAULT = 114;
    /* Sensibilité par défaut : 100 réagit au moindre bruit. */
    const MUSIC_SENSITIVITY = 50;

    /* ================================================================ CRON */

    /* Démon arrêté : le cron relève lui-même l'état, une fois par minute. */
    public static function cron() {
        if (self::deamon_info()['state'] == 'ok') {
            return;
        }
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip', '') === '') {
                continue;
            }
            try {
                $eqLogic->pollDirect();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* Une fois par heure : une recherche retrouve une lampe dont l'adresse a
     * changé (même réseau seulement : le multicast ne traverse pas les VLAN). */
    public static function cronHourly() {
        if (count(self::byType(__CLASS__, true)) === 0) {
            return;
        }
        try {
            foreach (self::scan(array(), 3) as $found) {
                try {
                    self::updateAddress($found);
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'warning', 'Recherche horaire, ' . $found['ip'] . ' : ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', 'Recherche horaire : ' . $e->getMessage());
        }
    }

    public static function health() {
        $return = array();
        $daemon = self::deamon_info()['state'] == 'ok';
        $return[] = array(
            'test'   => __('Démon', __FILE__),
            'result' => $daemon ? __('OK', __FILE__) : __('Arrêté : état relu chaque minute par le cron', __FILE__),
            'advice' => $daemon ? '' : __('Démarrez le démon depuis la page du plugin.', __FILE__),
            'state'  => $daemon,
        );
        $offline = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $cmd = $eqLogic->getCmd('info', 'online');
            $value = is_object($cmd) ? $cmd->execCmd() : '';
            if ($value !== '' && $value !== null && (int) $value === 0) {
                $offline[] = $eqLogic->getHumanName();
            }
        }
        $return[] = array(
            'test'   => __('Lampes', __FILE__),
            'result' => count($offline) === 0 ? __('Toutes répondent', __FILE__) : __('Muettes :', __FILE__) . ' ' . htmlspecialchars(implode(', ', $offline), ENT_QUOTES),
            'advice' => count($offline) === 0 ? '' : __('Vérifiez l\'adresse IP et que « LAN Control » est activé dans l\'application Govee.', __FILE__),
            'state'  => count($offline) === 0,
        );
        return $return;
    }

    /* =============================================================== DÉMON */

    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'ok');
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = (int) trim(file_get_contents($pid_file));
            if ($pid > 0 && self::isDaemonPid($pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }
        /* Un démon vivant qui ne joint plus Jeedom est déclaré arrêté, pour
         * que la gestion automatique le relance. Il relit la liste des lampes
         * chaque minute. */
        if ($return['state'] == 'ok' && time() - (int) @filemtime($pid_file) > 150
            && time() - (int) cache::byKey('govee::daemon_seen')->getValue(0) > 150) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $daemon = realpath(__DIR__ . '/../../resources/goveed/goveed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback ' . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --port ' . (int) self::controlPort();
        $cmd .= ' --interval ' . (int) config::byKey('poll_interval', __CLASS__, self::DEFAULT_INTERVAL);
        $cmd .= ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --timezone ' . escapeshellarg(date_default_timezone_get());
        $cmd .= ' --wledport ' . (int) self::wledPort();
        $targets = self::wledTargets();
        if (!empty($targets)) {
            $cmd .= ' --wledtargets ' . escapeshellarg(implode(',', $targets));
        }
        /* La clé API passe par un fichier lisible du seul www-data, que le
         * démon efface après l'avoir lu : jamais en argument, que ps montre à
         * tous. */
        $keyFile = jeedom::getTmpFolder(__CLASS__) . '/daemon.key';
        @unlink($keyFile);
        $old = umask(0077);
        file_put_contents($keyFile, jeedom::getApiKey(__CLASS__));
        umask($old);
        $cmd .= ' --keyfile ' . escapeshellarg($keyFile);
        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($cmd . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &');
        for ($i = 1; $i <= 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__) . ' ' . __CLASS__ . 'd.');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = (int) trim(file_get_contents($pid_file));
            if ($pid > 0 && self::isDaemonPid($pid)) {
                posix_kill($pid, 15);
                for ($i = 0; $i < 20 && self::isDaemonPid($pid); $i++) {
                    usleep(250000);
                }
                if (self::isDaemonPid($pid)) {
                    posix_kill($pid, 9);
                }
            }
            @unlink($pid_file);
        }
        /* Filet : un démon lancé à la main, ou dont le PID a été perdu. */
        if (count(system::ps('resources/goveed/goveed.php')) > 0) {
            system::kill('resources/goveed/goveed.php', false);
            for ($i = 0; $i < 20 && count(system::ps('resources/goveed/goveed.php')) > 0; $i++) {
                usleep(250000);
            }
            if (count(system::ps('resources/goveed/goveed.php')) > 0) {
                system::kill('resources/goveed/goveed.php');
            }
        }
        return true;
    }

    /* Le PID est-il toujours notre démon ? Il a pu être réattribué. */
    private static function isDaemonPid($_pid) {
        $cmdline = @file_get_contents('/proc/' . (int) $_pid . '/cmdline');
        return $cmdline !== false && strpos($cmdline, 'goveed.php') !== false;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/govee/core/php/jeeGovee.php';
    }

    public static function controlPort() {
        $port = (int) config::byKey('daemon_port', __CLASS__, self::DEFAULT_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::DEFAULT_PORT;
    }

    /* Une requête au démon : une ligne JSON, une ligne JSON en retour. Rend
     * null si le démon ne répond pas. */
    public static function daemonRequest($_request, $_timeout = 2) {
        $sock = @stream_socket_client('tcp://127.0.0.1:' . self::controlPort(), $errno, $error, 1);
        if ($sock === false) {
            return null;
        }
        $_request['key'] = jeedom::getApiKey(__CLASS__);
        stream_set_timeout($sock, (int) ceil($_timeout));
        fwrite($sock, json_encode($_request) . "\n");
        $line = fgets($sock, 1048576);
        fclose($sock);
        $reply = is_string($line) ? json_decode($line, true) : null;
        return is_array($reply) ? $reply : null;
    }

    public static function wledPort() {
        $port = (int) config::byKey('wled_port', __CLASS__, self::DEFAULT_WLED_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::DEFAULT_WLED_PORT;
    }

    /* Adresses où annoncer les changements, en plus du broadcast local. */
    public static function wledTargets() {
        $list = array();
        foreach (preg_split('/[\s,;]+/', (string) config::byKey('wled_targets', __CLASS__, ''), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $list[] = $ip;
            }
        }
        return array_values(array_unique($list));
    }

    /* Adresse IPv4 d'un WLED, port facultatif ; '' si illisible. */
    public static function wledAddress($_text) {
        $text = trim((string) $_text);
        if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?$/', $text, $m)
            || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 65535))) {
            return '';
        }
        return $text;
    }

    /* WLED connus du plugin WLED (wledbe), pour proposer une adresse. */
    public static function knownWled() {
        $list = array();
        if (!class_exists('wledbe')) {
            return $list;
        }
        foreach (eqLogic::byType('wledbe') as $eq) {
            $address = self::wledAddress($eq->getConfiguration('ip', ''));
            if ($address !== '') {
                $list[$address] = $eq->getHumanName();
            }
        }
        return $list;
    }

    /* Segments du flux temps réel pour ce modèle, 0 si inconnu. */
    public function razerSegments() {
        $sku = strtoupper((string) $this->getConfiguration('sku', ''));
        return isset(self::RAZER_SEGMENTS[$sku]) ? self::RAZER_SEGMENTS[$sku] : 0;
    }

    /* Réglages de synchro WLED d'une lampe, pour le démon. */
    public function wledConfig() {
        return array(
            'recv'   => (int) $this->getConfiguration('wled_recv', 0) === 1,
            'send'   => (int) $this->getConfiguration('wled_send', 0) === 1,
            'groups' => wledGroupMask($this->getConfiguration('wled_groups', '1')),
            'k'      => $this->kelvinRange(),
            'follow' => self::wledAddress($this->getConfiguration('wled_follow', '')),
            'mirror' => in_array($this->getConfiguration('wled_mirror', ''), array('average', 'spread'), true) ? $this->getConfiguration('wled_mirror') : '',
            'segs'   => $this->razerSegments(),
        );
    }

    /* Les lampes que le démon doit suivre. */
    public static function daemonDevices() {
        $list = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $ip = (string) $eqLogic->getConfiguration('ip', '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $api = null;
                if ($eqLogic->wledApiEnabled()) {
                    /* Une lampe en défaut ne prive pas le démon des autres. */
                    try {
                        $api = array('port' => $eqLogic->wledApiPort(), 'json' => $eqLogic->wledJson());
                    } catch (Throwable $e) {
                        log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . __('émulation WLED impossible', __FILE__) . ' (' . $e->getMessage() . ')');
                    }
                }
                $list[] = array('eq' => (int) $eqLogic->getId(), 'ip' => $ip, 'name' => $eqLogic->getHumanName(),
                                'wled' => $eqLogic->wledConfig(), 'api' => $api);
            }
        }
        return $list;
    }

    public static function notifyDaemon() {
        if (self::deamon_info()['state'] == 'ok') {
            self::daemonRequest(array('action' => 'reload'), 3);
        }
    }

    /* ======================================================== RÉSEAU (UDP) */

    /*
     * Échange direct avec les lampes, sans démon : on écoute le port 4002 le
     * temps d'attendre les réponses. Impossible quand le démon tourne (il
     * occupe ce port) : il faut alors passer par lui.
     *
     * $_targets : liste de [adresse, port, message]. Rend les réponses
     * reçues : liste de [ip, message décodé].
     */
    public static function exchangeDirect($_targets, $_seconds) {
        $sock = @stream_socket_server('udp://0.0.0.0:4002', $errno, $error, STREAM_SERVER_BIND);
        if ($sock === false) {
            throw new Exception(__('Port UDP 4002 occupé (démon en marche, ou autre logiciel Govee) :', __FILE__) . ' ' . $error);
        }
        stream_set_blocking($sock, false);
        foreach ($_targets as $i => $t) {
            if ($i > 0) {
                usleep(self::GAP_US);
            }
            @stream_socket_sendto($sock, $t[2], 0, $t[0] . ':' . $t[1]);
        }
        $replies = array();
        $end = microtime(true) + $_seconds;
        while (($left = $end - microtime(true)) > 0) {
            $r = array($sock);
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 0, (int) min(200000, $left * 1000000)) > 0) {
                while (($data = @stream_socket_recvfrom($sock, 65535, 0, $peer)) !== false && $data !== '') {
                    $msg = json_decode($data, true);
                    if (is_array($msg) && isset($msg['msg']['cmd'])) {
                        $replies[] = array((string) preg_replace('/:\d+$/', '', (string) $peer), $msg['msg']);
                    }
                }
            }
        }
        fclose($sock);
        return $replies;
    }

    /* Envoi sans attendre de réponse, depuis un port quelconque. */
    public static function sendDirect($_ip, $_payloads) {
        $sock = @stream_socket_client('udp://' . $_ip . ':4003', $errno, $error);
        if ($sock === false) {
            throw new Exception(__('Envoi UDP impossible vers', __FILE__) . ' ' . $_ip . ' : ' . $error);
        }
        foreach (array_values($_payloads) as $i => $payload) {
            if ($i > 0) {
                usleep(self::GAP_US);
            }
            fwrite($sock, $payload);
        }
        fclose($sock);
    }

    public static function statusMsg() {
        return '{"msg":{"cmd":"devStatus","data":{}}}';
    }

    public static function scanMsg() {
        return '{"msg":{"cmd":"scan","data":{"account_topic":"reserve"}}}';
    }

    /* Recherche : multicast sur le réseau de Jeedom, et unicast vers chaque
     * adresse donnée (les lampes d'un autre VLAN). Rend les réponses telles
     * que les lampes les décrivent : ip, device, sku, versions. */
    public static function scan($_ips = array(), $_seconds = 3) {
        $ips = array();
        foreach ($_ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new Exception(__('Adresse IP invalide :', __FILE__) . ' ' . $ip);
            }
            $ips[] = $ip;
        }
        if (self::deamon_info()['state'] == 'ok') {
            $reply = self::daemonRequest(array('action' => 'scan', 'ips' => $ips, 'seconds' => $_seconds), $_seconds + 3);
            if (!is_array($reply) || empty($reply['ok'])) {
                throw new Exception(__('Le démon n\'a pas répondu à la recherche.', __FILE__));
            }
            $found = $reply['devices'];
        } else {
            foreach (self::byType(__CLASS__) as $eqLogic) {
                $ips[] = (string) $eqLogic->getConfiguration('ip', '');
            }
            $targets = array(array('239.255.255.250', 4001, self::scanMsg()));
            foreach (array_unique(array_filter($ips)) as $ip) {
                $targets[] = array($ip, 4001, self::scanMsg());
            }
            $found = array();
            foreach (self::exchangeDirect($targets, $_seconds) as $reply) {
                if ($reply[1]['cmd'] === 'scan' && isset($reply[1]['data']) && is_array($reply[1]['data'])) {
                    $data = $reply[1]['data'];
                    if (!isset($data['ip'])) {
                        $data['ip'] = $reply[0];
                    }
                    $found[isset($data['device']) ? $data['device'] : $data['ip']] = $data;
                }
            }
            $found = array_values($found);
        }
        $clean = array();
        foreach ($found as $d) {
            $device = self::cleanText(isset($d['device']) ? $d['device'] : '', 32);
            $ip = isset($d['ip']) && filter_var($d['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $d['ip'] : '';
            if ($ip === '' || $device === '') {
                continue;
            }
            $existing = self::byLogicalId($device, __CLASS__);
            $clean[] = array(
                'ip'       => $ip,
                'device'   => $device,
                'sku'      => self::cleanText(isset($d['sku']) ? $d['sku'] : '', 16),
                'ble_hard' => self::cleanText(isset($d['bleVersionHard']) ? $d['bleVersionHard'] : '', 16),
                'ble_soft' => self::cleanText(isset($d['bleVersionSoft']) ? $d['bleVersionSoft'] : '', 16),
                'wifi_hard' => self::cleanText(isset($d['wifiVersionHard']) ? $d['wifiVersionHard'] : '', 16),
                'wifi_soft' => self::cleanText(isset($d['wifiVersionSoft']) ? $d['wifiVersionSoft'] : '', 16),
                'known'    => is_object($existing) ? $existing->getHumanName() : '',
            );
        }
        usort($clean, function ($a, $b) { return strnatcmp($a['ip'], $b['ip']); });
        return $clean;
    }

    /* Ce qui vient d'une lampe est du texte court, sans balisage. */
    public static function cleanText($_value, $_max) {
        $text = preg_replace('/[^A-Za-z0-9:._ -]/', '', (string) $_value);
        return substr((string) $text, 0, $_max);
    }

    /* Crée l'équipement d'une lampe trouvée, ou met son adresse à jour. */
    public static function createFromScan($_found) {
        $device = self::cleanText(isset($_found['device']) ? $_found['device'] : '', 32);
        if ($device === '') {
            throw new Exception(__('Lampe sans identifiant.', __FILE__));
        }
        $eqLogic = self::byLogicalId($device, __CLASS__);
        if (!is_object($eqLogic)) {
            $eqLogic = new govee();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId($device);
            $base = 'Govee ' . ($_found['sku'] !== '' ? $_found['sku'] : substr($device, -5));
            $name = $base;
            for ($i = 2; self::nameTaken($name); $i++) {
                $name = $base . ' ' . $i;
            }
            $eqLogic->setName($name);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setCategory('light', 1);
        }
        $eqLogic->applyScan($_found);
        $eqLogic->save();
        return $eqLogic;
    }

    private static function nameTaken($_name) {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->getName() === $_name && $eqLogic->getObject_id() === null) {
                return true;
            }
        }
        return false;
    }

    public function applyScan($_found) {
        foreach (array('ip', 'sku', 'ble_hard', 'ble_soft', 'wifi_hard', 'wifi_soft') as $key) {
            if (isset($_found[$key]) && $_found[$key] !== '') {
                $this->setConfiguration($key, $_found[$key]);
            }
        }
    }

    /* Une lampe connue qui répond depuis une autre adresse. */
    public static function updateAddress($_found) {
        $eqLogic = self::byLogicalId($_found['device'], __CLASS__);
        if (!is_object($eqLogic)) {
            return;
        }
        $old = (string) $eqLogic->getConfiguration('ip', '');
        $changed = $old !== $_found['ip'] || $eqLogic->getConfiguration('wifi_soft', '') !== $_found['wifi_soft']
            || $eqLogic->getConfiguration('ble_soft', '') !== $_found['ble_soft'];
        if (!$changed) {
            return;
        }
        if ($old !== $_found['ip']) {
            log::add(__CLASS__, 'info', $eqLogic->getHumanName() . ' : ' . __('nouvelle adresse', __FILE__) . ' ' . $_found['ip'] . ' (' . $old . ')');
        }
        $eqLogic->applyScan($_found);
        $eqLogic->save(true);
        self::notifyDaemon();
    }

    /* ======================================================== ÉQUIPEMENT */

    public function preSave() {
        $ip = trim((string) $this->getConfiguration('ip', ''));
        $this->setConfiguration('ip', $ip);
        /* Une saisie illisible est refusée plutôt qu'ignorée en silence. */
        $follow = trim((string) $this->getConfiguration('wled_follow', ''));
        $this->setConfiguration('wled_follow', $follow);
        if ($follow !== '' && self::wledAddress($follow) === '') {
            throw new Exception(sprintf(__('Adresse du WLED à suivre illisible : %s. Donnez son adresse IP (192.168.1.50), éventuellement suivie du port (192.168.1.50:80).', __FILE__), $follow));
        }
        if ($follow === '' && in_array($this->getConfiguration('wled_mirror', ''), array('average', 'spread'), true)) {
            throw new Exception(__('La copie en direct demande l\'adresse du WLED à suivre.', __FILE__));
        }
        $groups = trim((string) $this->getConfiguration('wled_groups', ''));
        if ($groups !== '' && !preg_match('/^\s*[1-8](\s*[,; ]\s*[1-8])*\s*$/', $groups)) {
            throw new Exception(sprintf(__('Groupes de synchro illisibles : %s. Des numéros de 1 à 8, séparés par des virgules.', __FILE__), $groups));
        }
        if ($this->wledApiEnabled()) {
            $port = (int) $this->getConfiguration('wled_api_port', self::WLED_API_PORT);
            if ($port <= 1024 || $port >= 65536 || in_array($port, self::WLED_API_RESERVED, true) || $port === self::controlPort()) {
                throw new Exception(sprintf(__('Port %s refusé pour l\'émulation WLED : choisissez-en un entre 1025 et 65535, libre.', __FILE__), $port));
            }
            foreach (self::byType(__CLASS__) as $other) {
                if ($other->getId() != $this->getId() && $other->wledApiEnabled() && $other->wledApiPort() === $port) {
                    throw new Exception(sprintf(__('Le port %s est déjà pris par %s.', __FILE__), $port, $other->getHumanName()));
                }
            }
        }
        /* Pas d'exception à la création (le coeur crée l'équipement avec son
         * seul nom) : une adresse invalide est simplement ignorée par le
         * démon et signalée à l'envoi. */
    }

    public function postSave() {
        $this->createCommands();
        self::notifyDaemon();
    }

    public function postRemove() {
        self::notifyDaemon();
    }

    /* Profil effectif : réglé à la main, ou déduit du modèle. */
    public function isUplighter() {
        $profile = $this->getConfiguration('profile', 'auto');
        if ($profile === 'uplighter') {
            return true;
        }
        if ($profile === 'standard') {
            return false;
        }
        return in_array(strtoupper((string) $this->getConfiguration('sku', '')), self::UPLIGHTER_SKUS, true);
    }

    public function kelvinRange() {
        return $this->isUplighter() ? self::UPLIGHTER_K : self::GENERIC_K;
    }

    /* ========================================================== COMMANDES */

    public function createCommands() {
        if ($this->getId() == '') {
            return;
        }
        list($kMin, $kMax) = $this->kelvinRange();
        $online = $this->addCmdIfMissing('online', 'Joignable', 'info', 'binary', array('order' => 0));
        $state = $this->addCmdIfMissing('state', 'Etat', 'info', 'binary', array('order' => 1, 'generic' => 'LIGHT_STATE_BOOL'));
        $bri = $this->addCmdIfMissing('brightness', 'Luminosité', 'info', 'numeric', array(
            'order' => 2, 'generic' => 'LIGHT_BRIGHTNESS', 'unite' => '%', 'min' => 0, 'max' => 100));
        $color = $this->addCmdIfMissing('color', 'Couleur', 'info', 'string', array('order' => 3, 'generic' => 'LIGHT_COLOR'));
        $temp = $this->addCmdIfMissing('colortemp', 'Température de blanc', 'info', 'numeric', array(
            'order' => 4, 'generic' => 'LIGHT_COLOR_TEMP', 'unite' => 'K', 'min' => $kMin, 'max' => $kMax));

        $this->addCmdIfMissing('on', 'Allumer', 'action', 'other', array('order' => 100, 'isVisible' => 1, 'generic' => 'LIGHT_ON', 'value' => $state));
        $this->addCmdIfMissing('off', 'Éteindre', 'action', 'other', array('order' => 101, 'isVisible' => 1, 'generic' => 'LIGHT_OFF', 'value' => $state));
        $this->addCmdIfMissing('toggle', 'Basculer', 'action', 'other', array('order' => 102, 'generic' => 'LIGHT_TOGGLE', 'value' => $state));
        $this->addCmdIfMissing('brightness_set', 'Régler la luminosité', 'action', 'slider', array(
            'order' => 103, 'isVisible' => 1, 'generic' => 'LIGHT_SLIDER', 'value' => $bri, 'min' => 0, 'max' => 100));
        $this->addCmdIfMissing('color_set', 'Régler la couleur', 'action', 'color', array(
            'order' => 104, 'isVisible' => 1, 'generic' => 'LIGHT_SET_COLOR', 'value' => $color));
        $this->addCmdIfMissing('colortemp_set', 'Régler le blanc', 'action', 'slider', array(
            'order' => 105, 'isVisible' => 1, 'generic' => 'LIGHT_SET_COLOR_TEMP', 'value' => $temp, 'min' => $kMin, 'max' => $kMax));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 106, 'isVisible' => 1));
        $this->addCmdIfMissing('frames', 'Envoyer des trames', 'action', 'message', array(
            'order' => 107, 'display' => array('title_disable' => 1, 'message_placeholder' => '33 30 01 01')));

        /* Effets : seulement si le catalogue du modèle est connu. La liste
         * suit le catalogue, mis à jour depuis la page de l'équipement. */
        $scenes = self::sceneCatalogue((string) $this->getConfiguration('sku', ''));
        if (!empty($scenes)) {
            $scene = $this->addCmdIfMissing('scene', 'Effet', 'info', 'string', array('order' => 5));
            $set = $this->addCmdIfMissing('scene_set', 'Lancer un effet', 'action', 'select', array(
                'order' => 108, 'isVisible' => 1, 'value' => $scene));
            $list = self::sceneListValue($scenes);
            if ((string) $set->getConfiguration('listValue', '') !== $list) {
                $set->setConfiguration('listValue', $list);
                $set->save();
            }
        }

        if ($this->isUplighter()) {
            $order = 10;
            foreach (self::ZONES as $zone => $spec) {
                $name = $spec['name'];
                $zs = $this->addCmdIfMissing('zone_' . $zone . '_state', $name . ' état', 'info', 'binary', array('order' => $order++));
                $zb = $this->addCmdIfMissing('zone_' . $zone . '_brightness', $name . ' luminosité', 'info', 'numeric', array(
                    'order' => $order++, 'unite' => '%', 'min' => 1, 'max' => 100));
                $zt = $this->addCmdIfMissing('zone_' . $zone . '_colortemp', $name . ' blanc', 'info', 'numeric', array(
                    'order' => $order++, 'unite' => 'K', 'min' => $kMin, 'max' => $kMax));
                $zc = $spec['rgb'] ? $this->addCmdIfMissing('zone_' . $zone . '_color', $name . ' couleur', 'info', 'string', array('order' => $order++)) : null;
                $this->addCmdIfMissing('zone_' . $zone . '_on', $name . ' allumer', 'action', 'other', array(
                    'order' => 100 + $order++, 'isVisible' => 1, 'value' => $zs));
                $this->addCmdIfMissing('zone_' . $zone . '_off', $name . ' éteindre', 'action', 'other', array(
                    'order' => 100 + $order++, 'isVisible' => 1, 'value' => $zs));
                $this->addCmdIfMissing('zone_' . $zone . '_brightness_set', $name . ' régler la luminosité', 'action', 'slider', array(
                    'order' => 100 + $order++, 'isVisible' => 1, 'value' => $zb, 'min' => 1, 'max' => 100));
                $this->addCmdIfMissing('zone_' . $zone . '_colortemp_set', $name . ' régler le blanc', 'action', 'slider', array(
                    'order' => 100 + $order++, 'isVisible' => 1, 'value' => $zt, 'min' => $kMin, 'max' => $kMax));
                if ($spec['rgb']) {
                    $this->addCmdIfMissing('zone_' . $zone . '_color_set', $name . ' régler la couleur', 'action', 'color', array(
                        'order' => 100 + $order++, 'isVisible' => 1, 'value' => $zc));
                }
            }
            $flow = $this->addCmdIfMissing('flow', 'Vitesse de l\'effet', 'info', 'numeric', array('order' => $order++, 'min' => 0, 'max' => 100));
            $this->addCmdIfMissing('flow_set', 'Régler la vitesse de l\'effet', 'action', 'slider', array(
                'order' => 100 + $order++, 'isVisible' => 1, 'value' => $flow, 'min' => 0, 'max' => 100));

            /* Mode musique : la lampe suit le son de son micro. */
            $music = $this->addCmdIfMissing('music', 'Mode musique', 'info', 'binary', array('order' => $order++));
            $musicMode = $this->addCmdIfMissing('music_mode', 'Mode musique choisi', 'info', 'string', array('order' => $order++));
            $sens = $this->addCmdIfMissing('music_sensitivity', 'Sensibilité au son', 'info', 'numeric', array(
                'order' => $order++, 'unite' => '%', 'min' => 0, 'max' => 100));
            $this->addCmdIfMissing('music_on', 'Activer le mode musique', 'action', 'other', array(
                'order' => 100 + $order++, 'isVisible' => 1, 'value' => $music));
            $this->addCmdIfMissing('music_off', 'Désactiver le mode musique', 'action', 'other', array(
                'order' => 100 + $order++, 'isVisible' => 1, 'value' => $music));
            $set = $this->addCmdIfMissing('music_set', 'Choisir le mode musique', 'action', 'select', array(
                'order' => 100 + $order++, 'isVisible' => 1, 'value' => $musicMode));
            $list = array();
            foreach (self::MUSIC_MODES as $code => $label) {
                $list[] = $code . '|' . $label;
            }
            $list = implode(';', $list);
            if ((string) $set->getConfiguration('listValue', '') !== $list) {
                $set->setConfiguration('listValue', $list);
                $set->save();
            }
            $this->addCmdIfMissing('music_sensitivity_set', 'Régler la sensibilité au son', 'action', 'slider', array(
                'order' => 100 + $order++, 'isVisible' => 1, 'value' => $sens, 'min' => 0, 'max' => 100));
        } else {
            /* Profil changé : les commandes de zone n'ont plus d'objet. */
            foreach ($this->getCmd() as $cmd) {
                if (preg_match('/^(zone_|flow|music)/', (string) $cmd->getLogicalId())) {
                    $cmd->remove();
                }
            }
        }
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            /* Les bornes suivent le profil (plage de blanc d'un Uplighter). */
            $changed = false;
            foreach (array('min' => 'minValue', 'max' => 'maxValue') as $opt => $key) {
                if (isset($_options[$opt]) && (string) $cmd->getConfiguration($key) !== (string) $_options[$opt]) {
                    $cmd->setConfiguration($key, $_options[$opt]);
                    $changed = true;
                }
            }
            if ($changed) {
                $cmd->save();
            }
            return $cmd;
        }
        $cmd = new goveeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /* Unicité (eqLogic_id, name) en base. */
        $name = cleanComponanteName(__($_name, __FILE__));
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(0);
        if (isset($_options['order']))    { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))   { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['generic'])) { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['min']))      { $cmd->setConfiguration('minValue', $_options['min']); }
        if (isset($_options['max']))      { $cmd->setConfiguration('maxValue', $_options['max']); }
        if (isset($_options['value']) && is_object($_options['value'])) {
            $cmd->setValue($_options['value']->getId());
        }
        foreach (isset($_options['display']) ? $_options['display'] : array() as $key => $value) {
            $cmd->setDisplay($key, $value);
        }
        $cmd->save();
        return $cmd;
    }

    public static function rebuildCommands() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->createCommands();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    private function info($_logicalId, $_default = null) {
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return $_default;
        }
        $value = $cmd->execCmd();
        return ($value === '' || $value === null) ? $_default : $value;
    }

    private function publishValues($_values) {
        foreach ($_values as $logicalId => $value) {
            $this->checkAndUpdateCmd($logicalId, $value);
        }
    }

    /* ============================================================== ÉTAT */

    /* État renvoyé par la lampe (devStatus), poussé par le démon ou relevé
     * par le cron. */
    public function ingest($_state) {
        if (isset($_state['online']) && (int) $_state['online'] === 0) {
            $this->publishValues(array('online' => 0));
            return;
        }
        $this->setCache('last_status', $_state);
        $this->setCache('last_status_at', time());
        $values = self::statusValues($_state);
        $values['online'] = 1;
        $this->publishValues($values);
    }

    /* Traduit un devStatus en valeurs de commandes info. En blanc, la lampe
     * rend une couleur noire et une température ; en couleur, une
     * température nulle : la température affichée reste alors la dernière. */
    public static function statusValues($_state) {
        $values = array();
        if (isset($_state['onOff'])) {
            $values['state'] = (int) $_state['onOff'] ? 1 : 0;
        }
        if (isset($_state['brightness'])) {
            $values['brightness'] = max(0, min(100, (int) $_state['brightness']));
        }
        $k = isset($_state['colorTemInKelvin']) ? (int) $_state['colorTemInKelvin'] : 0;
        if ($k > 0) {
            $values['colortemp'] = $k;
            $values['color'] = self::rgbToHex(self::kelvinToRgb($k));
        } elseif (isset($_state['color']) && is_array($_state['color'])) {
            $values['color'] = self::rgbToHex(array(
                isset($_state['color']['r']) ? $_state['color']['r'] : 0,
                isset($_state['color']['g']) ? $_state['color']['g'] : 0,
                isset($_state['color']['b']) ? $_state['color']['b'] : 0,
            ));
        }
        return $values;
    }

    /* Relevé sans démon. */
    public function pollDirect() {
        $ip = $this->getConfiguration('ip', '');
        foreach (self::exchangeDirect(array(array($ip, 4003, self::statusMsg())), 2) as $reply) {
            if ($reply[0] === $ip && $reply[1]['cmd'] === 'devStatus' && isset($reply[1]['data'])) {
                $this->ingest($reply[1]['data']);
                return true;
            }
        }
        $this->publishValues(array('online' => 0));
        return false;
    }

    public function refresh() {
        $ip = $this->getConfiguration('ip', '');
        if (self::deamon_info()['state'] == 'ok') {
            $reply = self::daemonRequest(array('action' => 'poll', 'ip' => $ip));
            if (is_array($reply) && !empty($reply['ok'])) {
                return true;
            }
        }
        return $this->pollDirect();
    }

    /* ==================================================== ÉMULATION WLED */

    /*
     * Une lampe peut se présenter comme un WLED à l'appli WLED : le démon
     * sert l'API JSON et le WebSocket de WLED sur un port propre à la lampe,
     * et passe ici chaque ordre reçu. Jeedom reste la source de vérité : un
     * ordre WLED devient l'exécution des commandes de l'équipement, et l'état
     * WLED se déduit de ses commandes info.
     *
     * Une lampe simple est un seul segment ; un Uplighter en a trois, un par
     * zone (0 haute, 1 anneau, 2 basse). Les effets WLED sont les effets de
     * l'application Govee, précédés de « Solid ». Une clé « gv », ignorée
     * des clients WLED, donne accès aux commandes de la lampe et à leur état :
     * c'est par elle que la page de commande servie à l'appli travaille.
     */

    public function wledApiEnabled() {
        return (int) $this->getConfiguration('wled_api', 0) === 1;
    }

    public function wledApiPort() {
        $port = (int) $this->getConfiguration('wled_api_port', self::WLED_API_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::WLED_API_PORT;
    }

    /* Une MAC stable et propre à la lampe : l'appli WLED en fait sa clé. Les
     * six derniers octets de l'identifiant Govee, qui sont ceux de la MAC
     * Wi-Fi de la lampe. */
    public function wledMac() {
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', (string) $this->getLogicalId()));
        if (strlen($hex) < 12) {
            $hex = substr(md5('govee-' . $this->getId()), 0, 12);
        }
        return substr($hex, -12);
    }

    /* Effets de la lampe, dans l'ordre des effets WLED (le 0, « Solid »,
     * mis à part) : nom unique (un doublon prend sa catégorie, comme dans la
     * liste de la commande) et code Govee. */
    public function wledScenes() {
        $count = array();
        $scenes = self::sceneCatalogue((string) $this->getConfiguration('sku', ''));
        foreach ($scenes as $s) {
            $n = strtolower($s['name']);
            $count[$n] = isset($count[$n]) ? $count[$n] + 1 : 1;
        }
        $list = array();
        foreach ($scenes as $s) {
            $list[] = array('name' => $count[strtolower($s['name'])] > 1 ? $s['name'] . ' (' . $s['category'] . ')' : $s['name'],
                            'code' => (int) $s['code']);
        }
        return $list;
    }

    public function wledEffects() {
        return array_merge(array('Solid'), array_column($this->wledScenes(), 'name'));
    }

    /* Numéro d'effet WLED de l'effet Govee en cours, 0 sans effet. */
    private function wledFx() {
        $name = (string) $this->info('scene', '');
        if ($name === '') {
            return 0;
        }
        $code = (int) $this->getCache('scene_code', 0);
        $byName = 0;
        foreach ($this->wledScenes() as $i => $s) {
            if ($code > 0 && $s['code'] === $code) {
                return $i + 1;
            }
            if ($byName === 0 && strcasecmp(preg_replace('/ \(.*\)$/', '', $s['name']), $name) === 0) {
                $byName = $i + 1;
            }
        }
        return $byName;
    }

    /* Une couleur lue dans une commande info, sans exception : un état
     * illisible ne doit pas priver le démon de la liste des lampes. */
    private function infoRgb($_logicalId, $_default) {
        try {
            return self::hexToRgb((string) $this->info($_logicalId, $_default));
        } catch (Throwable $e) {
            return self::hexToRgb($_default);
        }
    }

    /* Ce que la lampe fait : musique, effet, blanc ou couleur. Le blanc se
     * lit dans le dernier relevé (une température non nulle). */
    private function wledMode($_fx) {
        if ((int) $this->info('music', 0) === 1) {
            return 'music';
        }
        if ($_fx > 0) {
            return 'scene';
        }
        $status = $this->getCache('last_status', array());
        return (is_array($status) && isset($status['colorTemInKelvin']) && (int) $status['colorTemInKelvin'] > 0) ? 'white' : 'color';
    }

    /* Kelvin vers le CCT relatif (0 chaud, 255 froid) de l'état WLED. */
    private function wledCct($_kelvin) {
        list($kMin, $kMax) = $this->kelvinRange();
        return (int) max(0, min(255, round(($_kelvin - $kMin) * 255 / max(1, $kMax - $kMin))));
    }

    private function wledBri($_percent) {
        return max(1, min(255, (int) round((int) $_percent * 255 / 100)));
    }

    private function wledSegment($_id, $_on, $_bri, $_rgb, $_kelvin, $_fx) {
        return array(
            'id' => $_id, 'start' => $_id * self::WLED_SEG_LEN, 'stop' => ($_id + 1) * self::WLED_SEG_LEN, 'len' => self::WLED_SEG_LEN,
            'grp' => 1, 'spc' => 0, 'of' => 0, 'on' => (bool) $_on, 'frz' => false, 'bri' => $_bri, 'cct' => $this->wledCct($_kelvin),
            'set' => 0, 'n' => '', 'col' => array(array_values($_rgb), array(0, 0, 0), array(0, 0, 0)), 'fx' => $_fx, 'sx' => 128,
            'ix' => 128, 'pal' => 0, 'c1' => 128, 'c2' => 128, 'c3' => 16,
            /* Seul le segment 0 est sélectionné : c'est lui que vise un ordre
             * de segment sans numéro. */
            'sel' => $_id === 0, 'rev' => false, 'mi' => false,
            'o1' => false, 'o2' => false, 'o3' => false, 'si' => 0, 'm12' => 0,
        );
    }

    public function wledState() {
        $on = (int) $this->info('state', 0) === 1;
        $kelvin = (int) $this->info('colortemp', 4000);
        $scene = (string) $this->info('scene', '');
        $fx = $this->wledFx();
        $segs = array();
        $zones = array();
        if ($this->isUplighter()) {
            foreach (self::WLED_ZONE_ORDER as $id => $zone) {
                $zk = (int) $this->info('zone_' . $zone . '_colortemp', 4000);
                $rgb = self::ZONES[$zone]['rgb'] ? $this->infoRgb('zone_' . $zone . '_color', '#ffffff') : self::kelvinToRgb($zk);
                $zOn = (int) $this->info('zone_' . $zone . '_state', 0) === 1;
                $zBri = (int) $this->info('zone_' . $zone . '_brightness', 100);
                $segs[] = $this->wledSegment($id, $zOn, $this->wledBri($zBri), $rgb, $zk, $id === 0 ? $fx : 0);
                $zones[$zone] = array('on' => $zOn, 'brightness' => $zBri, 'color' => self::rgbToHex($rgb), 'colortemp' => $zk);
            }
        } else {
            $segs[] = $this->wledSegment(0, true, 255, $this->infoRgb('color', '#ffffff'), $kelvin, $fx);
        }
        return array(
            'on' => $on, 'bri' => $this->wledBri($this->info('brightness', 100)), 'transition' => 7, 'ps' => -1, 'pl' => -1,
            'nl' => array('on' => false, 'dur' => 60, 'mode' => 1, 'tbri' => 0, 'rem' => -1),
            'udpn' => array('send' => false, 'recv' => false, 'sgrp' => 1, 'rgrp' => 1),
            'lor' => 0, 'mainseg' => 0, 'seg' => $segs,
            'gv' => array(
                'mode' => $this->wledMode($fx),
                'online' => (int) $this->info('online', 0) === 1,
                'brightness' => (int) $this->info('brightness', 100),
                'color' => self::rgbToHex($this->infoRgb('color', '#ffffff')),
                'colortemp' => $kelvin,
                'scene' => $scene,
                'music' => (int) $this->info('music', 0) === 1,
                'music_mode' => (string) $this->info('music_mode', ''),
                'music_sensitivity' => (int) $this->info('music_sensitivity', self::MUSIC_SENSITIVITY),
                'flow' => (int) $this->info('flow', 0),
                'zones' => $zones,
            ),
        );
    }

    public function wledInfo() {
        $uplighter = $this->isUplighter();
        $count = $uplighter ? count(self::WLED_ZONE_ORDER) : 1;
        /* Capacités par segment : 1 couleur, 4 blanc réglable. */
        $seglc = $uplighter ? array(5, 5, 4) : array(5);
        $zones = array();
        if ($uplighter) {
            foreach (self::WLED_ZONE_ORDER as $zone) {
                $zones[$zone] = array('name' => self::ZONES[$zone]['name'], 'rgb' => self::ZONES[$zone]['rgb']);
            }
        }
        $sku = (string) $this->getConfiguration('sku', '');
        return array(
            'ver' => '0.15.0', 'vid' => 2410270,
            'leds' => array('count' => $count * self::WLED_SEG_LEN, 'pwr' => 0, 'fps' => 0, 'maxpwr' => 0, 'maxseg' => $count,
                            'rgbw' => false, 'wv' => 0, 'cct' => true, 'lc' => 5, 'seglc' => $seglc),
            'str' => false, 'name' => $this->getName(), 'udpport' => self::wledPort(), 'live' => false, 'liveseg' => -1,
            'lm' => '', 'lip' => '', 'ws' => 0, 'fxcount' => count($this->wledEffects()), 'palcount' => 1, 'cpalcount' => 0,
            'maps' => array(array('id' => 0)), 'wifi' => array('bssid' => '', 'rssi' => -50, 'signal' => 100, 'channel' => 1),
            'fs' => array('u' => 0, 't' => 0, 'pmt' => 0), 'ndc' => 0, 'arch' => 'govee', 'core' => 'jeedom', 'lwip' => 0,
            'freeheap' => 0, 'uptime' => 0, 'opt' => 0, 'brand' => 'WLED', 'product' => trim('Govee ' . $sku),
            'mac' => $this->wledMac(), 'ip' => '',
            'gv' => array('sku' => $sku, 'uplighter' => $uplighter, 'k' => $this->kelvinRange(), 'zones' => $zones,
                          'music' => $uplighter ? array_values(self::MUSIC_MODES) : array()),
        );
    }

    /* Ce que le démon sert pour cette lampe. */
    public function wledJson() {
        return array('state' => $this->wledState(), 'info' => $this->wledInfo(), 'effects' => $this->wledEffects());
    }

    /* Un CCT de WLED : un kelvin (≥ 1900), ou 0-255 du plus chaud au plus
     * froid, ramené à la plage de la lampe. */
    private function wledKelvin($_cct) {
        list($kMin, $kMax) = $this->kelvinRange();
        $cct = (int) $_cct;
        $k = $cct >= 1900 ? $cct : $kMin + max(0, min(255, $cct)) * ($kMax - $kMin) / 255;
        return (int) max($kMin, min($kMax, round($k)));
    }

    private static function wledRgbHex($_col) {
        if (!is_array($_col) || count($_col) < 3) {
            return null;
        }
        $c = array_values($_col);
        return self::rgbToHex(array((int) $c[0], (int) $c[1], (int) $c[2]));
    }

    /*
     * Un ordre WLED (POST /json/state, ou trame du WebSocket), traduit en
     * commandes de l'équipement, exécutées comme depuis Jeedom (actions
     * avant/après, valeurs calculées, timeline). Rend la liste des
     * commandes exécutées.
     *
     * Sémantique de WLED : « on » vaut true, false ou "t" (bascule) ; « bri »
     * à 0 éteint ; « seg » est une liste, ou un seul objet qui vise les
     * segments sélectionnés (ici le 0) ; un champ absent ne change rien. Une
     * luminosité de segment ne l'allume pas : sur une zone éteinte, elle est
     * seulement retenue.
     */
    public function wledApply($_body) {
        if (!is_array($_body)) {
            throw new Exception(__('Ordre WLED illisible.', __FILE__));
        }
        $done = array();
        $run = function ($_logicalId, $_options = array()) use (&$done) {
            $cmd = $this->getCmd('action', $_logicalId);
            if (!is_object($cmd)) {
                throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
            }
            $cmd->execCmd($_options);
            $done[] = $_logicalId;
        };

        /* Extension de la page de commande : une commande action de la
         * lampe, par son identifiant logique. */
        if (isset($_body['gv']) && is_array($_body['gv']) && isset($_body['gv']['cmd'])) {
            $logicalId = (string) $_body['gv']['cmd'];
            if (!preg_match('/^[a-z0-9_]+$/', $logicalId) || in_array($logicalId, array('frames', 'refresh'), true)) {
                throw new Exception(__('Commande refusée :', __FILE__) . ' ' . $logicalId);
            }
            $options = isset($_body['gv']['options']) && is_array($_body['gv']['options']) ? $_body['gv']['options'] : array();
            $run($logicalId, array_intersect_key($options, array_flip(array('slider', 'color', 'select'))));
        }

        $on = array_key_exists('on', $_body) ? $_body['on'] : null;
        if ($on === 't') {
            $on = (int) $this->info('state', 0) !== 1;
        }
        if ($on === false || (isset($_body['bri']) && (int) $_body['bri'] <= 0)) {
            $run('off');
            return $done;
        }
        $segs = array();
        if (isset($_body['seg']) && is_array($_body['seg'])) {
            $segs = array_keys($_body['seg']) === range(0, count($_body['seg']) - 1) ? $_body['seg'] : array($_body['seg']);
        }
        $uplighter = $this->isUplighter();
        foreach ($segs as $rank => $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $id = isset($seg['id']) ? (int) $seg['id'] : (count($segs) === 1 ? 0 : (int) $rank);
            $fx = isset($seg['fx']) ? (int) $seg['fx'] : -1;
            $hex = isset($seg['col'][0]) ? self::wledRgbHex($seg['col'][0]) : null;
            $cct = isset($seg['cct']) ? $this->wledKelvin($seg['cct']) : null;

            /* Un effet, sur le segment principal : un effet Govee, désigné par
             * son code (deux effets peuvent porter le même nom). */
            if ($fx > 0 && $id === 0) {
                $scenes = $this->wledScenes();
                if (isset($scenes[$fx - 1])) {
                    $run('scene_set', array('select' => (string) $scenes[$fx - 1]['code']));
                }
                continue;
            }
            /* « Solid » demandé pendant un effet, sans couleur : la lampe
             * reprend sa couleur ou son blanc d'avant. */
            $leaveEffect = $fx === 0 && $id === 0 && $hex === null && $cct === null && (string) $this->info('scene', '') !== '';

            if ($uplighter) {
                if (!isset(self::WLED_ZONE_ORDER[$id])) {
                    continue;
                }
                $zone = self::WLED_ZONE_ORDER[$id];
                $prefix = 'zone_' . $zone . '_';
                $lit = (int) $this->info($prefix . 'state', 0) === 1;
                $segOn = isset($seg['on']) ? ($seg['on'] === 't' ? !$lit : (bool) $seg['on']) : null;
                if ($segOn === false) {
                    $run($prefix . 'off');
                    continue;
                }
                $acted = false;
                if ($hex !== null && self::ZONES[$zone]['rgb'] && ($hex !== self::rgbToHex($this->infoRgb($prefix . 'color', '#000000')) || $leaveEffect)) {
                    $run($prefix . 'color_set', array('color' => $hex));
                    $acted = true;
                } elseif ($cct !== null && abs($cct - (int) $this->info($prefix . 'colortemp', 0)) > 50) {
                    $run($prefix . 'colortemp_set', array('slider' => $cct));
                    $acted = true;
                } elseif ($leaveEffect) {
                    $run('color_set', array('color' => self::rgbToHex($this->infoRgb('color', '#ffffff'))));
                    $acted = true;
                }
                if (isset($seg['bri'])) {
                    $p = max(1, (int) round((int) $seg['bri'] * 100 / 255));
                    if ($lit || $acted || $segOn === true) {
                        if ($p !== (int) $this->info($prefix . 'brightness', 0)) {
                            $run($prefix . 'brightness_set', array('slider' => $p));
                        }
                        $acted = true;
                    } else {
                        $this->publishValues(array($prefix . 'brightness' => $p));
                    }
                }
                if (!$acted && $segOn === true) {
                    $run($prefix . 'on');
                }
                continue;
            }
            if (isset($seg['on']) && ($seg['on'] === false || ($seg['on'] === 't' && (int) $this->info('state', 0) === 1))) {
                $run('off');
                return $done;
            }
            if ($hex !== null) {
                $run('color_set', array('color' => $hex));
            } elseif ($cct !== null) {
                $run('colortemp_set', array('slider' => $cct));
            } elseif ($leaveEffect) {
                $k = (int) $this->info('colortemp', 0);
                $run('color_set', array('color' => self::rgbToHex($this->infoRgb('color', '#ffffff'))));
            }
        }
        if (isset($_body['bri'])) {
            $run('brightness_set', array('slider' => max(1, (int) round((int) $_body['bri'] * 100 / 255))));
        } elseif ($on === true && empty($done)) {
            $run('on');
        }
        return $done;
    }

    /* ============================================================ ENVOI */

    /* Message standard de l'API LAN. */
    public static function msg($_cmd, $_data) {
        return json_encode(array('msg' => array('cmd' => $_cmd, 'data' => $_data)));
    }

    /* Trame de 20 octets : données, bourrage de zéros, somme XOR. */
    public static function frame($_bytes) {
        $bytes = array_slice(array_values($_bytes), 0, 19);
        $bytes = array_pad($bytes, 19, 0);
        $x = 0;
        foreach ($bytes as $b) {
            $x ^= ($b & 0xFF);
        }
        $bytes[] = $x;
        return pack('C*', ...$bytes);
    }

    /* Trames ptReal, au plus MAX_FRAMES par datagramme. */
    public static function ptReal($_frames) {
        $msgs = array();
        foreach (array_chunk(array_values($_frames), self::MAX_FRAMES) as $chunk) {
            $msgs[] = self::msg('ptReal', array('command' => array_map('base64_encode', $chunk)));
        }
        return $msgs;
    }

    /* Trames saisies à la main, en hexadécimal : une par ligne, ou séparées par
     * « ; » ou « | ». Moins de 20 octets : bourrage et somme ajoutés ; 20
     * octets : envoyée telle quelle. */
    public static function parseFrames($_text) {
        $frames = array();
        foreach (preg_split('/[\r\n;|]+/', (string) $_text) as $line) {
            $hex = preg_replace('/[\s,:]|0x/i', '', $line);
            if ($hex === '') {
                continue;
            }
            if (!ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
                throw new Exception(__('Trame illisible :', __FILE__) . ' ' . $line);
            }
            $bytes = array_map('hexdec', str_split($hex, 2));
            if (count($bytes) > 20) {
                throw new Exception(__('Trame de plus de 20 octets :', __FILE__) . ' ' . $line);
            }
            $frames[] = count($bytes) === 20 ? pack('C*', ...$bytes) : self::frame($bytes);
        }
        if (empty($frames)) {
            throw new Exception(__('Aucune trame à envoyer.', __FILE__));
        }
        return $frames;
    }

    /* Envoie des messages à la lampe, par le démon si possible. */
    public function send($_msgs) {
        $ip = (string) $this->getConfiguration('ip', '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception(__('Adresse IP de la lampe absente ou invalide.', __FILE__));
        }
        if (empty($_msgs)) {
            return;
        }
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' -> ' . implode(' ', $_msgs));
        if (self::deamon_info()['state'] == 'ok') {
            $reply = self::daemonRequest(array('action' => 'send', 'ip' => $ip, 'msgs' => array_values($_msgs)));
            if (is_array($reply) && !empty($reply['ok'])) {
                return;
            }
            log::add(__CLASS__, 'warning', __('Le démon ne répond pas : envoi direct', __FILE__));
        }
        self::sendDirect($ip, $_msgs);
    }

    /* ======================================================= TRAMES ZONES */

    public static function fPower($_on)          { return self::frame(array(0x33, 0x01, $_on ? 1 : 0)); }
    public static function fZone($_id, $_on)     { return self::frame(array(0x33, 0x30, $_id, $_on ? 1 : 0)); }
    public static function fFlow($_v)            { return self::frame(array(0x33, 0x05, 0x2C, 0x01, 0x03, max(0, min(100, (int) $_v)))); }

    /* L'anneau latéral prend un masque de segments : 0xFF, tous les huit. */
    public static function fZoneBrightness($_id, $_p) {
        $p = max(1, min(100, (int) $_p));
        $bytes = array(0x33, 0x05, 0x2C, $_id, 0x02, $p);
        if ($_id === 2) {
            $bytes[] = 0xFF;
        }
        return self::frame($bytes);
    }

    public static function fZoneRgb($_id, $_rgb) {
        if ($_id === 3) {
            throw new Exception(__('L\'éclairage bas n\'a pas de couleur.', __FILE__));
        }
        $bytes = array(0x33, 0x05, 0x2C, $_id, 0x01, $_rgb[0], $_rgb[1], $_rgb[2], 0, 0);
        if ($_id === 2) {
            $bytes[] = 0xFF;
        }
        return self::frame($bytes);
    }

    /* L'éclairage bas ne reçoit QUE la température : des octets de couleur
     * l'éteignent. */
    public static function fZoneKelvin($_id, $_k) {
        $k = max(self::UPLIGHTER_K[0], min(self::UPLIGHTER_K[1], (int) $_k));
        if ($_id === 3) {
            return self::frame(array(0x33, 0x05, 0x2C, 3, 0x01, $k >> 8, $k & 0xFF));
        }
        $rgb = self::kelvinToRgb($k);
        $bytes = array(0x33, 0x05, 0x2C, $_id, 0x01, $rgb[0], $rgb[1], $rgb[2], $k >> 8, $k & 0xFF);
        if ($_id === 2) {
            $bytes[] = 0xFF;
        }
        return self::frame($bytes);
    }

    /* ============================================================ EFFETS */

    /* Catalogue téléchargé (data/scenes), sinon celui livré avec le plugin
     * (core/config/scenes). */
    private static function sceneFiles($_sku) {
        $sku = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $_sku));
        if ($sku === '') {
            return array();
        }
        return array(
            __DIR__ . '/../../data/scenes/' . $sku . '.json',
            __DIR__ . '/../config/scenes/' . $sku . '.json',
        );
    }

    /* Liste des effets d'un modèle : code, nom, catégorie, paramètre
     * (base64, vide pour un effet intégré à la lampe). */
    public static function sceneCatalogue($_sku) {
        static $cache = array();
        $key = strtoupper((string) $_sku);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $scenes = array();
        foreach (self::sceneFiles($_sku) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['scenes']) && is_array($data['scenes'])) {
                $scenes = $data['scenes'];
                break;
            }
        }
        return $cache[$key] = $scenes;
    }

    /* Valeurs d'une liste Jeedom : « code|nom » séparés par « ; ». Un nom
     * présent deux fois prend sa catégorie. */
    public static function sceneListValue($_scenes) {
        $count = array();
        foreach ($_scenes as $s) {
            $n = strtolower($s['name']);
            $count[$n] = isset($count[$n]) ? $count[$n] + 1 : 1;
        }
        $items = array();
        foreach ($_scenes as $s) {
            $label = $s['name'];
            if ($count[strtolower($s['name'])] > 1) {
                $label .= ' (' . $s['category'] . ')';
            }
            $items[] = (int) $s['code'] . '|' . str_replace(array('|', ';'), array('/', ','), $label);
        }
        return implode(';', $items);
    }

    /* Télécharge le catalogue d'un modèle et le range dans data/scenes. */
    public static function fetchScenes($_sku) {
        $sku = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $_sku));
        if ($sku === '') {
            throw new Exception(__('Modèle de lampe inconnu : lancez d\'abord une recherche.', __FILE__));
        }
        $http = new com_http(self::SCENES_URL . rawurlencode($sku));
        $http->setHeader(array('AppVersion: 9999999', 'Accept: application/json'));
        $data = json_decode((string) $http->exec(15, 1), true);
        if (!is_array($data) || !isset($data['data']['categories']) || !is_array($data['data']['categories'])) {
            throw new Exception(__('Réponse inattendue de la bibliothèque d\'effets Govee.', __FILE__));
        }
        $scenes = array();
        foreach ($data['data']['categories'] as $cat) {
            foreach (isset($cat['scenes']) ? $cat['scenes'] : array() as $s) {
                foreach (isset($s['lightEffects']) ? $s['lightEffects'] : array() as $e) {
                    if (!isset($e['sceneCode'])) {
                        continue;
                    }
                    $param = isset($e['scenceParam']) ? (string) $e['scenceParam'] : '';
                    if ($param !== '' && base64_decode($param, true) === false) {
                        continue;
                    }
                    $name = self::cleanText((string) $s['sceneName'] . (!empty($e['scenceName']) ? ' ' . $e['scenceName'] : ''), 64);
                    $scenes[] = array(
                        'code'     => (int) $e['sceneCode'],
                        'name'     => $name !== '' ? $name : '#' . (int) $e['sceneCode'],
                        'category' => self::cleanText(isset($cat['categoryName']) ? (string) $cat['categoryName'] : '', 64),
                        'param'    => $param,
                    );
                }
            }
        }
        if (empty($scenes)) {
            throw new Exception(__('Govee ne publie aucun effet pour ce modèle :', __FILE__) . ' ' . $sku);
        }
        $dir = __DIR__ . '/../../data/scenes';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new Exception(__('Impossible de créer le dossier', __FILE__) . ' ' . $dir);
        }
        $json = json_encode(array('sku' => $sku, 'fetched' => date('Y-m-d'), 'scenes' => $scenes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($dir . '/' . $sku . '.json', $json) === false) {
            throw new Exception(__('Impossible d\'écrire le catalogue d\'effets.', __FILE__));
        }
        return count($scenes);
    }

    public function findScene($_value) {
        $value = trim((string) $_value);
        $scenes = self::sceneCatalogue((string) $this->getConfiguration('sku', ''));
        foreach ($scenes as $s) {
            if ((string) $s['code'] === $value) {
                return $s;
            }
        }
        /* Par le nom aussi, pour les scénarios. */
        foreach ($scenes as $s) {
            if (strcasecmp($s['name'], $value) === 0) {
                return $s;
            }
        }
        throw new Exception(__('Effet inconnu pour ce modèle :', __FILE__) . ' ' . $value);
    }

    /*
     * Trames d'un effet. Un effet intégré à la lampe (paramètre vide) tient en
     * une trame « 33 05 04 code ». Les autres envoient d'abord leur
     * description en plusieurs trames « a3 » :
     *
     *   a3 00 01 <nb de trames> <données…>, a3 01 <données…>, …, a3 ff <fin>
     *
     * Sur un H60B0, la description de la bibliothèque (51, longueur, 0a, …)
     * s'envoie sous la forme 5a, longueur moins un, sans le 0a : vérifié sur la
     * lampe. Pour les autres modèles, préfixe 02, comme govee2mqtt.
     */
    public static function sceneFrames($_code, $_param) {
        $code = (int) $_code;
        $mode = self::frame(array(0x33, 0x05, 0x04, $code & 0xFF, ($code >> 8) & 0xFF));
        $param = (string) $_param === '' ? '' : base64_decode((string) $_param, true);
        if ($param === false || $param === '') {
            return array($mode);
        }
        $bytes = array_values(unpack('C*', $param));
        if (count($bytes) > 4 && $bytes[0] === 0x51 && $bytes[3] === 0x0A) {
            $len = (($bytes[1] << 8) | $bytes[2]) - 1;
            $body = array_merge(array(0x5A, ($len >> 8) & 0xFF, $len & 0xFF), array_slice($bytes, 4));
        } else {
            $body = array_merge(array(0x02), $bytes);
        }
        $lines = (int) ceil((count($body) + 2) / 17);
        if ($lines > 254) {
            throw new Exception(__('Effet trop long.', __FILE__));
        }
        $chunks = array_chunk(array_merge(array(0x01, $lines), $body), 17);
        $frames = array();
        foreach ($chunks as $i => $chunk) {
            $index = ($i === count($chunks) - 1) ? 0xFF : $i;
            $frames[] = self::frame(array_merge(array(0xA3, $index), $chunk));
        }
        $frames[] = $mode;
        return $frames;
    }

    /* ========================================================= ACTIONS */

    public function runAction($_logicalId, $_options) {
        if ($_logicalId === 'refresh') {
            $this->refresh();
            return;
        }
        if ($_logicalId === 'frames') {
            $this->send(self::ptReal(self::parseFrames(isset($_options['message']) ? $_options['message'] : '')));
            return;
        }
        if (preg_match('/^zone_(top|side|bottom)_(on|off|brightness_set|colortemp_set|color_set)$/', $_logicalId, $m)) {
            $this->zoneAction($m[1], $m[2], $_options);
            return;
        }
        if ($_logicalId === 'scene_set') {
            $scene = $this->findScene(isset($_options['select']) ? $_options['select'] : '');
            $this->send(self::ptReal(array_merge(array(self::fPower(true)), self::sceneFrames($scene['code'], $scene['param']))));
            $this->setCache('scene_code', (int) $scene['code']);
            $this->publishValues(array('state' => 1, 'scene' => $scene['name'], 'music' => 0));
            return;
        }
        if (preg_match('/^music_(on|off|set|sensitivity_set)$/', $_logicalId, $m)) {
            $this->musicAction($m[1], $_options);
            return;
        }
        if ($_logicalId === 'flow_set') {
            $v = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 0)));
            $this->send(self::ptReal(array(self::fFlow($v))));
            $this->publishValues(array('flow' => $v));
            return;
        }

        $on = (int) $this->info('state', 0) === 1;
        $msgs = array();
        $values = array();
        switch ($_logicalId) {
            case 'on':
                $msgs[] = self::msg('turn', array('value' => 1));
                $values['state'] = 1;
                break;
            case 'off':
                $msgs[] = self::msg('turn', array('value' => 0));
                $values['state'] = 0;
                break;
            case 'toggle':
                $msgs[] = self::msg('turn', array('value' => $on ? 0 : 1));
                $values['state'] = $on ? 0 : 1;
                break;
            case 'brightness_set':
                $p = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 0)));
                if ($p === 0) {
                    $msgs[] = self::msg('turn', array('value' => 0));
                    $values['state'] = 0;
                    break;
                }
                if (!$on) {
                    $msgs[] = self::msg('turn', array('value' => 1));
                }
                $msgs[] = self::msg('brightness', array('value' => $p));
                $values += array('state' => 1, 'brightness' => $p);
                break;
            case 'color_set':
                $rgb = self::hexToRgb(isset($_options['color']) ? $_options['color'] : '');
                if (!$on) {
                    $msgs[] = self::msg('turn', array('value' => 1));
                }
                $msgs[] = self::msg('colorwc', array('color' => array('r' => $rgb[0], 'g' => $rgb[1], 'b' => $rgb[2]), 'colorTemInKelvin' => 0));
                $values += array('state' => 1, 'color' => self::rgbToHex($rgb), 'scene' => '', 'music' => 0);
                break;
            case 'colortemp_set':
                list($kMin, $kMax) = $this->kelvinRange();
                $k = max($kMin, min($kMax, (int) (isset($_options['slider']) ? $_options['slider'] : $kMin)));
                if (!$on) {
                    $msgs[] = self::msg('turn', array('value' => 1));
                }
                $msgs[] = self::msg('colorwc', array('color' => array('r' => 0, 'g' => 0, 'b' => 0), 'colorTemInKelvin' => $k));
                $values += array('state' => 1, 'colortemp' => $k, 'color' => self::rgbToHex(self::kelvinToRgb($k)), 'scene' => '', 'music' => 0);
                break;
            default:
                return;
        }
        $this->send($msgs);
        /* Affiché tout de suite ; le relevé qui suit (démon) le confirme. */
        $this->publishValues($values);
    }

    /*
     * Ordres de zone d'un Uplighter. La lampe n'en renvoie pas l'état : il est
     * tenu ici. Elle n'accepte que deux zones allumées à la fois : allumer une
     * troisième éteint celle allumée depuis le plus longtemps.
     */
    public function zoneAction($_zone, $_action, $_options) {
        if (!$this->isUplighter()) {
            throw new Exception(__('Commande de zone sur une lampe sans zones.', __FILE__));
        }
        $spec = self::ZONES[$_zone];
        $id = $spec['id'];
        $values = array();
        if ($_action === 'off') {
            $this->send(self::ptReal(array(self::fZone($id, false))));
            $this->zoneOrder($_zone, false);
            $this->publishValues(array('zone_' . $_zone . '_state' => 0));
            return;
        }
        /* Allumer la lampe et la zone, à chaque fois : l'état des zones n'est
         * que supposé, et « allumer » une zone allumée est sans effet. */
        $frames = array();
        if ((int) $this->info('zone_' . $_zone . '_state', 0) !== 1) {
            foreach ($this->zonesToSwitchOff($_zone) as $victim) {
                $frames[] = self::fZone(self::ZONES[$victim]['id'], false);
                $values['zone_' . $victim . '_state'] = 0;
                $this->zoneOrder($victim, false);
            }
        }
        $frames[] = self::fPower(true);
        $frames[] = self::fZone($id, true);
        switch ($_action) {
            case 'on':
                break;
            case 'brightness_set':
                $p = max(1, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 100)));
                $frames[] = self::fZoneBrightness($id, $p);
                $values['zone_' . $_zone . '_brightness'] = $p;
                break;
            case 'colortemp_set':
                $k = max(self::UPLIGHTER_K[0], min(self::UPLIGHTER_K[1], (int) (isset($_options['slider']) ? $_options['slider'] : 4000)));
                $frames[] = self::fZoneKelvin($id, $k);
                $values['zone_' . $_zone . '_colortemp'] = $k;
                if ($spec['rgb']) {
                    $values['zone_' . $_zone . '_color'] = self::rgbToHex(self::kelvinToRgb($k));
                }
                break;
            case 'color_set':
                $rgb = self::hexToRgb(isset($_options['color']) ? $_options['color'] : '');
                $frames[] = self::fZoneRgb($id, $rgb);
                $values['zone_' . $_zone . '_color'] = self::rgbToHex($rgb);
                break;
        }
        $this->send(self::ptReal($frames));
        $this->zoneOrder($_zone, true);
        if ($_action === 'color_set' || $_action === 'colortemp_set') {
            $values['scene'] = '';
            $values['music'] = 0;
        }
        $values['zone_' . $_zone . '_state'] = 1;
        $values['state'] = 1;
        $this->publishValues($values);
    }

    /*
     * Mode musique d'un Uplighter : « 33 05 13 mode sensibilité ». La lampe
     * garde les réglages de chaque mode (palette, style) : seule la trame
     * d'activation est envoyée. Une couleur, un blanc ou un effet en sortent ;
     * « désactiver » remet ce qui tournait avant.
     */
    public static function fMusic($_mode, $_sensitivity) {
        return self::frame(array(0x33, 0x05, 0x13, (int) $_mode & 0xFF, max(0, min(100, (int) $_sensitivity))));
    }

    public function musicAction($_action, $_options) {
        if (!$this->isUplighter()) {
            throw new Exception(__('Mode musique non pris en charge sur ce modèle.', __FILE__));
        }
        $active = (int) $this->info('music', 0) === 1;
        $mode = $this->musicModeCode();
        $sens = (int) $this->info('music_sensitivity', self::MUSIC_SENSITIVITY);
        switch ($_action) {
            case 'off':
                if ($active) {
                    $this->restoreBeforeMusic();
                }
                $this->publishValues(array('music' => 0));
                return;
            case 'set':
                $value = trim((string) (isset($_options['select']) ? $_options['select'] : ''));
                $mode = null;
                foreach (self::MUSIC_MODES as $code => $label) {
                    if ((string) $code === $value || strcasecmp($label, $value) === 0) {
                        $mode = $code;
                    }
                }
                if ($mode === null) {
                    throw new Exception(__('Mode musique inconnu :', __FILE__) . ' ' . $value);
                }
                break;
            case 'sensitivity_set':
                $sens = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : self::MUSIC_SENSITIVITY)));
                if (!$active) {
                    /* Retenue pour la prochaine activation. */
                    $this->publishValues(array('music_sensitivity' => $sens));
                    return;
                }
                break;
        }
        if (!$active) {
            $this->saveBeforeMusic();
        }
        $this->send(self::ptReal(array(self::fPower(true), self::fMusic($mode, $sens))));
        $this->publishValues(array(
            'state' => 1, 'music' => 1, 'music_mode' => self::MUSIC_MODES[$mode],
            'music_sensitivity' => $sens, 'scene' => '',
        ));
    }

    private function musicModeCode() {
        $label = (string) $this->info('music_mode', '');
        foreach (self::MUSIC_MODES as $code => $name) {
            if ($name === $label) {
                return $code;
            }
        }
        return self::MUSIC_DEFAULT;
    }

    /* Ce qui tournait avant la musique : un effet, sinon le dernier état relu
     * sur la lampe (blanc ou couleur). */
    private function saveBeforeMusic() {
        $scene = (string) $this->info('scene', '');
        if ($scene !== '') {
            $this->setCache('before_music', 'scene:' . $scene);
            return;
        }
        $status = $this->getCache('last_status', array());
        $k = is_array($status) && isset($status['colorTemInKelvin']) ? (int) $status['colorTemInKelvin'] : 0;
        if ($k > 0) {
            $this->setCache('before_music', 'kelvin:' . $k);
        } elseif (is_array($status) && isset($status['color']) && is_array($status['color'])) {
            $this->setCache('before_music', 'color:' . self::rgbToHex(array(
                (int) $status['color']['r'], (int) $status['color']['g'], (int) $status['color']['b'])));
        } else {
            $this->setCache('before_music', '');
        }
    }

    private function restoreBeforeMusic() {
        $before = (string) $this->getCache('before_music', '');
        list($kind, $value) = array_pad(explode(':', $before, 2), 2, '');
        try {
            if ($kind === 'scene') {
                $this->runAction('scene_set', array('select' => $value));
                return;
            }
            if ($kind === 'color' && $value !== '#000000') {
                $this->runAction('color_set', array('color' => $value));
                return;
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
        }
        list($kMin) = $this->kelvinRange();
        $this->runAction('colortemp_set', array('slider' => $kind === 'kelvin' ? (int) $value : max($kMin, 3000)));
    }

    /* Ordre d'allumage des zones, pour la limite de deux. */
    private function zoneOrder($_zone, $_on) {
        $order = array_values(array_diff($this->zoneOrderList(), array($_zone)));
        if ($_on) {
            $order[] = $_zone;
        }
        $this->setCache('zone_order', implode(',', $order));
    }

    /* Rangé en chaîne « top,side » : le cache des équipements gère mal les
     * tableaux. */
    private function zoneOrderList() {
        return array_values(array_filter(explode(',', (string) $this->getCache('zone_order', ''))));
    }

    /* Zones à éteindre pour en allumer une : les plus anciennes, jusqu'à n'en
     * laisser qu'une autre. Une zone allumée hors de Jeedom (appli) est
     * supposée la plus ancienne. */
    public function zonesToSwitchOff($_zone) {
        $lit = array();
        foreach (array_keys(self::ZONES) as $zone) {
            if ($zone !== $_zone && (int) $this->info('zone_' . $zone . '_state', 0) === 1) {
                $lit[] = $zone;
            }
        }
        $order = $this->zoneOrderList();
        usort($lit, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            return ($ia === false ? -1 : $ia) <=> ($ib === false ? -1 : $ib);
        });
        return array_slice($lit, 0, max(0, count($lit) - 1));
    }

    /* =========================================================== COULEURS */

    public static function hexToRgb($_hex) {
        $hex = ltrim(trim((string) $_hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            throw new Exception(__('Couleur invalide :', __FILE__) . ' ' . $_hex);
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    public static function rgbToHex($_rgb) {
        return sprintf('#%02x%02x%02x', max(0, min(255, (int) $_rgb[0])), max(0, min(255, (int) $_rgb[1])), max(0, min(255, (int) $_rgb[2])));
    }

    /* Couleur approchée d'un blanc (l'application l'envoie avec la
     * température). */
    public static function kelvinToRgb($_k) {
        $t = $_k / 100.0;
        if ($t <= 66) {
            $r = 255;
            $g = 99.4708025861 * log($t) - 161.1195681661;
            $b = $t <= 19 ? 0 : 138.5177312231 * log($t - 10) - 305.0447927307;
        } else {
            $r = 329.698727446 * pow($t - 60, -0.1332047592);
            $g = 288.1221695283 * pow($t - 60, -0.0755148492);
            $b = 255;
        }
        return array_map(function ($c) { return (int) max(0, min(255, $c)); }, array($r, $g, $b));
    }
}

class goveeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}
