<?php
/* Jeu d'essai hors ligne : trames et décodage d'état.
 *
 *   php tests/run.php
 *
 * Les trames attendues sont celles que produit govee-lan-bridge (bridge.py),
 * dont les codes ont été relevés sur un vrai H60B0. Les états décodés sont
 * des réponses réelles du H60B0 de développement (firmware Wi-Fi 1.06.24). */

$core = '/var/www/html/core/php/core.inc.php';
if (!is_readable($core)) {
    echo "Jeedom introuvable : jeu d'essai ignoré.\n";
    exit(0);
}
require_once $core;
/* La classe charge le coeur par un chemin relatif valable seulement depuis
 * /var/www/html/plugins : on l'inclut sans ce require. */
$copy = sys_get_temp_dir() . '/govee.test.' . getmypid() . '.php';
/* __DIR__ garde le dossier de la classe, pour les catalogues d'effets. */
file_put_contents($copy, str_replace('__DIR__', var_export(realpath(__DIR__ . '/../core/class'), true),
    preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '',
    file_get_contents(__DIR__ . '/../core/class/govee.class.php'))));
register_shutdown_function(function () use ($copy) { @unlink($copy); });
require_once $copy;
require_once __DIR__ . '/../resources/goveed/ws.php';

$passed = 0;
$failed = 0;
function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %s\n", $_label);
    } else {
        $failed++;
        printf("  ECHEC %s : obtenu %s, attendu %s\n", $_label, var_export($_actual, true), var_export($_expected, true));
    }
}

echo "Trames (comparées à govee-lan-bridge)\n";
check('allumer', bin2hex(govee::fPower(true)), '3301010000000000000000000000000000000033');
check('anneau latéral allumé', bin2hex(govee::fZone(2, true)), '3330020100000000000000000000000000000000');
check('vitesse 80', bin2hex(govee::fFlow(80)), '33052c0103500000000000000000000000000048');
check('luminosité anneau 60 (masque)', bin2hex(govee::fZoneBrightness(2, 60)), '33052c02023cff000000000000000000000000d9');
check('luminosité haut 80', bin2hex(govee::fZoneBrightness(1, 80)), '33052c0102500000000000000000000000000049');
check('couleur haut bleu', bin2hex(govee::fZoneRgb(1, array(0, 0, 255))), '33052c01010000ff0000000000000000000000e5');
check('couleur anneau vert', bin2hex(govee::fZoneRgb(2, array(0, 255, 0))), '33052c020100ff000000ff000000000000000019');
check('blanc bas 4000 K (sans RGB)', bin2hex(govee::fZoneKelvin(3, 4000)), '33052c03010fa0000000000000000000000000b7');
check('blanc anneau 3000 K', bin2hex(govee::fZoneKelvin(2, 3000)), '33052c0201ffb16d0bb8ff000000000000000076');
check('blanc haut 9000 K borné à 6500', bin2hex(govee::fZoneKelvin(1, 9000)), '33052c0101fffefa19640000000000000000009c');
check('kelvin -> RGB 4000', govee::kelvinToRgb(4000), array(255, 205, 166));
$threw = false;
try { govee::fZoneRgb(3, array(1, 2, 3)); } catch (Exception $e) { $threw = true; }
check('couleur refusée sur l\'éclairage bas', $threw, true);

echo "Trames saisies à la main\n";
check('bourrage et somme', array_map('bin2hex', govee::parseFrames('33 30 01 01')), array('3330010100000000000000000000000000000003'));
check('plusieurs trames', count(govee::parseFrames("33 01 01\n33 30 02 01; 33 04 32")), 3);
check('20 octets envoyés tels quels', bin2hex(govee::parseFrames('3301010000000000000000000000000000000099')[0]), '3301010000000000000000000000000000000099');
$threw = false;
try { govee::parseFrames('33 3'); } catch (Exception $e) { $threw = true; }
check('trame illisible refusée', $threw, true);

echo "Messages ptReal\n";
$msgs = govee::ptReal(array_fill(0, 13, govee::fPower(true)));
check('13 trames -> 2 datagrammes', count($msgs), 2);
check('base64', json_decode($msgs[1], true)['msg']['data']['command'][0], base64_encode(govee::fPower(true)));

echo "Effets\n";
check('effet intégré (Rainbow)', bin2hex(implode('', govee::sceneFrames(3, ''))), '3305040300000000000000000000000000000031');
/* Format H60B0 (5a), vérifié sur la lampe avec « Ocean ». */
$ocean = null;
foreach (govee::sceneCatalogue('H60B0') as $s) {
    if ($s['name'] === 'Ocean') { $ocean = $s; }
}
check('catalogue H60B0 livré', $ocean !== null && count(govee::sceneCatalogue('H60B0')) > 100, true);
check('Ocean (H60B0, 5a)', bin2hex(implode('', govee::sceneFrames($ocean['code'], $ocean['param']))),
    'a30001095a006c0325000000010202cc1801bad5a3011414cc1903ba1414000014010773ff000050a302800000800000020001ff240000000102017ba303990003001414030014033916ff8b00ff39b3a30416ff1000cc0000e500010100ff1f00020590a305020201ff0003c914140000140107ff9c00e3a30600800000800000020001ff0022030a010073a307011e3200320001000a0100011e32003200aea3ff01000a0100011e32003200010000000000483305040b3600000000000000000000000000000f');
/* Format générique (02) : vecteur de test de govee2mqtt (Forest, code 212). */
check('Forest (générique, 02)', bin2hex(implode('', govee::sceneFrames(212, 'AyYAAQAKAgH/GQG0CgoCyBQF//8AAP//////AP//lP8AFAGWAAAAACMAAg8FAgH/FAH7AAAB+goEBP8AtP8AR///4/8AAAAAAAAAABoAAAABAgH/BQHIFBQC7hQBAP8AAAAAAAAAAA=='))),
    str_replace(array(' ', "\n"), '', 'a3 00 01 07 02 03 26 00 01 00 0a 02 01 ff 19 01 b4 0a 0a d9
a3 01 02 c8 14 05 ff ff 00 00 ff ff ff ff ff 00 ff ff 94 12
a3 02 ff 00 14 01 96 00 00 00 00 23 00 02 0f 05 02 01 ff 0a
a3 03 14 01 fb 00 00 01 fa 0a 04 04 ff 00 b4 ff 00 47 ff b3
a3 04 ff e3 ff 00 00 00 00 00 00 00 00 1a 00 00 00 01 02 5d
a3 05 01 ff 05 01 c8 14 14 02 ee 14 01 00 ff 00 00 00 00 92
a3 ff 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 5c
33 05 04 d4 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 e6'));
check('liste : doublon suffixé de sa catégorie', strpos(govee::sceneListValue(govee::sceneCatalogue('H60B0')), 'Meditation (') !== false, true);

echo "Mode musique\n";
check('Rythme, sensibilité 100 (vérifié sur la lampe)', bin2hex(govee::fMusic(114, 100)), '3305137264000000000000000000000000000033');
check('sensibilité bornée', bin2hex(govee::fMusic(131, 150)), bin2hex(govee::fMusic(131, 100)));

echo "Décodage d'état (réponses réelles du H60B0)\n";
check('blanc 2700 K', govee::statusValues(json_decode('{"onOff":1,"brightness":100,"color":{"r":0,"g":0,"b":0},"colorTemInKelvin":2700}', true)),
    array('state' => 1, 'brightness' => 100, 'colortemp' => 2700, 'color' => govee::rgbToHex(govee::kelvinToRgb(2700))));
check('rouge', govee::statusValues(json_decode('{"onOff":1,"brightness":40,"color":{"r":255,"g":0,"b":0},"colorTemInKelvin":0}', true)),
    array('state' => 1, 'brightness' => 40, 'color' => '#ff0000'));
check('éteinte', govee::statusValues(array('onOff' => 0))['state'], 0);

echo "Synchro WLED\n";
/* Paquet d'un WLED 0.15 (version 12) : rouge, luminosité 128, groupes 1 et 3. */
$in = array_fill(0, 41, 0);
$in[1] = 1; $in[2] = 128; $in[3] = 255; $in[11] = 12; $in[36] = 0b101; $in[37] = 255;
$p = wledParse(pack('C*', ...$in));
check('lecture : groupes', $p['groups'], 5);
check('lecture : couleur', $p['rgb'], array(255, 0, 0));
check('rouge à 50 %', wledWanted($p, 2700, 6500), array('on' => true, 'brightness' => 50, 'color' => array(255, 0, 0)));
$in[8] = 9;
check('effet : couleur non suivie', wledWanted(wledParse(pack('C*', ...$in)), 2700, 6500), array('on' => true, 'brightness' => 50));
$in[8] = 0; $in[2] = 0;
check('luminosité 0 : éteindre', wledWanted(wledParse(pack('C*', ...$in)), 2700, 6500), array('on' => false));
$in[2] = 255; $in[3] = 0; $in[10] = 255; $in[37] = 0; $in[38] = 255;
check('blanc pur, CCT froid -> borne haute', wledWanted(wledParse(pack('C*', ...$in)), 2700, 6500)['kelvin'], 6500);
$in[37] = 0x0f; $in[38] = 0xa0;
check('blanc pur, 4000 K', wledWanted(wledParse(pack('C*', ...$in)), 2700, 6500)['kelvin'], 4000);
check('paquet custom (callMode > 199) ignoré', wledParse(pack('C*', 0, 200, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10)), null);
check('realtime (WARLS) ignoré', wledParse(pack('C*', 1, 2, 0, 255, 0, 0, 1, 2, 3, 4, 5, 6)), null);
check('ancien paquet (version 8) : groupe 1', wledParse(pack('C*', ...array_slice(array_replace($in, array(11 => 8)), 0, 24)))['groups'], 1);

$out = wledPacket(array('on' => true, 'brightness' => 100, 'kelvin' => 4000), 2);
$ob = array_values(unpack('C*', $out));
check('envoi : 41 octets, version 10', array(strlen($out), $ob[11]), array(41, 10));
check('envoi : kelvin en octets 37-38', ($ob[37] << 8) | $ob[38], 4000);
check('envoi : RVB du blanc', array($ob[3], $ob[4], $ob[5]), govee::kelvinToRgb(4000));
check('aller-retour', wledWanted(wledParse(wledPacket(array('on' => true, 'brightness' => 40, 'color' => array(0, 128, 255)), 1)), 2000, 9000),
    array('on' => true, 'brightness' => 40, 'color' => array(0, 128, 255)));
check('éteinte : luminosité 0', ord(wledPacket(array('on' => false), 1)[2]), 0);
check('un blanc annoncé reste un blanc (pas son RVB approché)',
    wledWanted(wledParse(wledPacket(array('on' => true, 'brightness' => 60, 'kelvin' => 3000), 1)), 2700, 6500),
    array('on' => true, 'brightness' => 60, 'kelvin' => 3000));

$status = wledFromStatus(json_decode('{"onOff":1,"brightness":40,"color":{"r":255,"g":0,"b":0},"colorTemInKelvin":0}', true));
check('rien à envoyer si déjà là', wledMessages(array('on' => true, 'brightness' => 40, 'color' => array(255, 0, 0)), $status), array());
check('seule la luminosité change', count(wledMessages(array('on' => true, 'brightness' => 80, 'color' => array(255, 0, 0)), $status)), 1);
check('éteinte -> allumer + couleur + luminosité', count(wledMessages(array('on' => true, 'brightness' => 80, 'color' => array(1, 2, 3)), array('on' => false))), 3);
check('état inconnu, éteindre', wledMessages(array('on' => false), null), array(govee::msg('turn', array('value' => 0))));
check('groupes « 1, 3 »', wledGroupMask('1, 3'), 5);
check('groupes vides -> 1', wledGroupMask(''), 1);
check('groupe 9 ignoré', wledGroupMask('9 2'), 2);

echo "Suivi d'un WLED (WebSocket)\n";
/* Extrait d'un vrai état poussé par le WLED de développement (16.0.0). */
$json = json_decode('{"on":true,"bri":128,"mainseg":0,"seg":[{"id":0,"on":true,"bri":255,"col":[[0,0,255],[0,0,0],[0,0,0]],"fx":0,"cct":127}]}', true);
check('bleu à 50 %', wledWanted(wledFromJson($json), 2700, 6500), array('on' => true, 'brightness' => 50, 'color' => array(0, 0, 255)));
$json['seg'][0]['bri'] = 128;
check('luminosité du segment comptée', wledWanted(wledFromJson($json), 2700, 6500)['brightness'], 25);
$json['seg'][0]['on'] = false;
check('segment éteint : lampe éteinte', wledWanted(wledFromJson($json), 2700, 6500), array('on' => false));
check('segment principal choisi par mainseg', wledFromJson(array('on' => true, 'bri' => 255, 'mainseg' => 1,
    'seg' => array(array('id' => 0, 'col' => array(array(1, 1, 1))), array('id' => 1, 'col' => array(array(9, 9, 9))))))['rgb'], array(9, 9, 9));
check('état illisible', wledFromJson(array('seg' => array())), null);
check('accept (exemple de la RFC 6455)', wsAccept('dGhlIHNhbXBsZSBub25jZQ=='), 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=');
$long = str_repeat('x', 70000);
$buf = wsFrame(1, 'bonjour', true) . wsFrame(9, 'p', false) . wsFrame(1, $long, false);
$frag = null;
check('trames masquées, ping, 64 bits', wsParse($buf, $frag), array(array('text', 'bonjour'), array('ping', 'p'), array('text', $long)));
$whole = wsFrame(1, 'coupé', false);
$buf = substr($whole, 0, 4);
check('trame incomplète : attente', wsParse($buf, $frag), array());
$buf .= substr($whole, 4);
check('trame complétée', wsParse($buf, $frag), array(array('text', 'coupé')));

echo "Ordres de l'appli WLED (lampe simulée)\n";
/* Lampe simulée : ses commandes info rendent des valeurs fixées, ses
 * commandes action notent leur appel au lieu d'envoyer quoi que ce soit. */
class goveeCmdStub {
    public $value; public $owner; public $id;
    public function execCmd($_options = null) {
        if ($this->owner !== null) { $this->owner->runs[] = array($this->id, $_options); }
        return $this->value;
    }
}
class goveeStub extends govee {
    public $values = array(); public $runs = array(); public $actions = array();
    public function getCmd($_type = null, $_logicalId = null, $_visible = null, $_multiple = false) {
        $c = new goveeCmdStub();
        if ($_type === 'action') {
            if (!in_array($_logicalId, $this->actions, true)) { return null; }
            $c->owner = $this; $c->id = $_logicalId;
            return $c;
        }
        if (!array_key_exists($_logicalId, $this->values)) { return null; }
        $c->value = $this->values[$_logicalId];
        return $c;
    }
}
function goveeStub($_sku, $_values) {
    $e = new goveeStub();
    $e->setConfiguration('sku', $_sku);
    $e->values = $_values;
    $e->actions = array('on', 'off', 'brightness_set', 'color_set', 'colortemp_set', 'scene_set', 'music_on', 'refresh', 'frames');
    foreach (array('top', 'side', 'bottom') as $z) {
        foreach (array('on', 'off', 'brightness_set', 'color_set', 'colortemp_set') as $a) { $e->actions[] = 'zone_' . $z . '_' . $a; }
    }
    return $e;
}
$lamp = goveeStub('H6008', array('state' => 1, 'brightness' => 50, 'color' => '#ff0000', 'colortemp' => 4000, 'scene' => ''));
$lamp->wledApply(array('bri' => 128));
check('bri 128 -> luminosité 50 %', $lamp->runs, array(array('brightness_set', array('slider' => 50))));
$lamp->runs = array(); $lamp->wledApply(array('on' => false, 'bri' => 200));
check('on false : éteindre seulement', $lamp->runs, array(array('off', array())));
$lamp->runs = array(); $lamp->wledApply(array('on' => 't'));
check('bascule d\'une lampe allumée', $lamp->runs, array(array('off', array())));
$lamp->runs = array(); $lamp->wledApply(array('seg' => array('col' => array(array(0, 0, 255)))));
check('segment en objet, sans numéro', $lamp->runs, array(array('color_set', array('color' => '#0000ff'))));
$lamp->runs = array(); $lamp->wledApply(array('seg' => array(array('id' => 0, 'cct' => 255))));
check('CCT 255 -> blanc le plus froid', $lamp->runs, array(array('colortemp_set', array('slider' => 9000))));
$threw = false;
$lamp->runs = array();
try { $lamp->wledApply(array('gv' => array('cmd' => 'frames', 'options' => array('message' => '33 01 00')))); } catch (Exception $e) { $threw = true; }
check('gv : trames brutes refusées, rien exécuté', array($threw, $lamp->runs), array(true, array()));
$lamp->wledApply(array('gv' => array('cmd' => 'color_set', 'options' => array('color' => '#00ff00', 'message' => 'x'))));
check('gv : options filtrées', $lamp->runs, array(array('color_set', array('color' => '#00ff00'))));

$up = goveeStub('H60B0', array('state' => 1, 'brightness' => 100, 'color' => '#ffffff', 'colortemp' => 4000, 'scene' => 'Ocean',
    'zone_top_state' => 1, 'zone_top_color' => '#ff0000', 'zone_top_brightness' => 80,
    'zone_side_state' => 0, 'zone_side_color' => '#00ff00', 'zone_side_brightness' => 60, 'zone_bottom_state' => 0));
$scenes = $up->wledScenes();
$names = array_column($scenes, 'name');
check('noms d\'effets uniques', count($names), count(array_unique($names)));
$i = array_search('Meditation (Soothing)', $names, true);
$up->runs = array(); $up->wledApply(array('seg' => array(array('id' => 0, 'fx' => $i + 1))));
check('doublon « Meditation » : lancé par son code', $up->runs, array(array('scene_set', array('select' => (string) $scenes[$i]['code']))));
$up->runs = array(); $up->wledApply(array('seg' => array(array('id' => 0, 'fx' => 0))));
check('« Solid » pendant un effet : couleur d\'avant', $up->runs, array(array('color_set', array('color' => '#ffffff'))));
$up->runs = array(); $up->wledApply(array('seg' => array(array('id' => 1, 'on' => true))));
check('allumer l\'anneau (segment 1)', $up->runs, array(array('zone_side_on', array())));
$up->runs = array(); $up->wledApply(array('seg' => array(array('id' => 0, 'col' => array(array(255, 0, 0)), 'bri' => 204))));
check('zone haute : même couleur, même luminosité -> rien', $up->runs, array());
$up->runs = array(); $up->wledApply(array('seg' => array(array('id' => 2, 'on' => false))));
check('éteindre la zone basse', $up->runs, array(array('zone_bottom_off', array())));
$state = $up->wledState();
check('état : trois segments, seul le 0 sélectionné', array(count($state['seg']), array_column($state['seg'], 'sel')), array(3, array(true, false, false)));
check('état : CCT relatif 0-255', $state['seg'][0]['cct'], (int) round((4000 - 2700) * 255 / (6500 - 2700)));

echo "Couleurs\n";
check('#abc', govee::hexToRgb('#abc'), array(170, 187, 204));
check('aller-retour', govee::rgbToHex(govee::hexToRgb('#12aBef')), '#12abef');

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
