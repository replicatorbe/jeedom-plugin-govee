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

echo "Couleurs\n";
check('#abc', govee::hexToRgb('#abc'), array(170, 187, 204));
check('aller-retour', govee::rgbToHex(govee::hexToRgb('#12aBef')), '#12abef');

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
