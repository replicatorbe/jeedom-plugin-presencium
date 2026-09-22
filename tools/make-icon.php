<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : trois
 * couleurs, une maison et deux silhouettes, qu'on peut relire et refaire. Le
 * dessin est fait en 1024 puis réduit en 256, ce qui donne les bords lissés que
 * GD ne produit pas sur un remplissage direct.
 *
 * Le sujet : qui est là. Une maison pleine, une silhouette franche — la
 * personne présente — et la même silhouette à peine posée sur le fond — celle
 * qui est partie. C'est tout ce que le plugin publie, et c'est lisible à 24
 * pixels, où il ne reste qu'un toit et deux taches d'inégale densité.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$ardoise  = imagecolorallocate($grand, 0x37, 0x47, 0x4F);
$present  = imagecolorallocate($grand, 0x3F, 0xC4, 0x6B);
/* L'absent est le même vert, presque effacé : il se lit comme la même personne
   et non comme une seconde, ce qui serait le contresens du dessin.
   Le mélange est calculé ici — ce vert posé à 28 % sur l'ardoise de la maison —
   plutôt que confié au canal alpha de GD : la silhouette est faite de deux
   formes qui se recouvrent, et deux couches translucides superposées laissent
   une couture visible là où elles se chevauchent. Elle est tout entière sur le
   corps de la maison, d'une seule couleur : la teinte est donc exacte. */
$absent   = imagecolorallocate($grand, 0x39, 0x69, 0x56);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* imagefilledpolygon() a perdu son paramètre de comptage en PHP 8 : l'appeler
   avec quatre arguments lève une ArgumentCountError sur PHP 8.1 et plus, avec
   trois sur PHP 7.4. Les deux versions existent sur les Jeedom en service. */
$polygone = function ($_image, $_points, $_couleur) {
    if (PHP_VERSION_ID >= 80000) {
        imagefilledpolygon($_image, $_points, $_couleur);
        return;
    }
    imagefilledpolygon($_image, $_points, (int) (count($_points) / 2), $_couleur);
};

/* La maison : un toit large et un corps trapu. Les débords du toit donnent la
   forme reconnaissable à petite taille — un triangle posé sur un carré ne se
   lit pas comme une maison, un toit qui dépasse, oui. */
$polygone($grand, array(
    $e(128), $e(16),
    $e(242), $e(124),
    $e(14),  $e(124),
), $ardoise);
imagefilledrectangle($grand, $e(46), $e(116), $e(210), $e(236), $ardoise);

/* Une silhouette : une tête et un buste, posés dans le corps de la maison.
   Fermée ci-dessous par un rectangle, sinon l'arc laisse un ventre vide. */
$silhouette = function ($_cx, $_couleur) use ($grand, $e) {
    imagefilledellipse($grand, $e($_cx), $e(154), $e(36), $e(36), $_couleur);
    imagefilledarc($grand, $e($_cx), $e(216), $e(62), $e(78), 180, 360, $_couleur, IMG_ARC_PIE);
    imagefilledrectangle($grand, $e($_cx - 31), $e(214), $e($_cx + 31), $e(226), $_couleur);
};

$silhouette(92, $present);
$silhouette(166, $absent);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/presencium_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
