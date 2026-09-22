<?php
/* Jeu d'essai hors ligne du plugin Presencium.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base de données, ni balise. Les deux
 * classes éprouvées ici — la décision de présence et la lecture des règles —
 * ignorent volontairement Jeedom, et c'est précisément ce qui rend ce fichier
 * possible.
 *
 * Ce qu'on vérifie n'est pas décoratif. Un plugin de présence se trompe en
 * silence : il ne lève pas d'erreur quand il invente un départ, il arme
 * l'alarme. Le coeur du sujet est à la section 5, où l'on rejoue une demi-heure
 * d'historique réellement mesurée sur une balise qui rebondit — le genre
 * d'épreuve qu'on ne refait pas à la demande dans une vraie maison, et qui
 * passe ici en quelques millisecondes.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/presenciumPersonne.class.php';
require_once __DIR__ . '/../core/class/presenciumRegles.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-58s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-58s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition) {
    verifie($_titre, $_condition ? true : false, true);
}

/* Les transitions se lisent mieux à plat qu'en tableau de tableaux. */
function resume($_transitions) {
    $morceaux = array();
    foreach ($_transitions as $transition) {
        $morceaux[] = $transition['type'] . ':' . $transition['personne'];
    }
    return implode(' ', $morceaux);
}

/* Un instantané de foyer, tel que presencium::instantane() le rend. */
function instantane($_presents, $_total) {
    $presents = array();
    foreach ($_presents as $id) {
        $presents[$id] = 'Personne ' . $id;
    }
    return array('presents' => $presents, 'total' => $_total);
}

/* ------------------------------------------------------------------ 1 ---
 * La normalisation des réglages. C'est la première ligne de défense : tout ce
 * qui sort d'un formulaire est une chaîne, et un (int) naïf transformerait un
 * champ vide ou mal saisi en « 0 minute », c'est-à-dire en suppression pure et
 * simple de l'anti-rebond — sans un mot dans le journal. */
echo "\nRéglages d'une personne\n";
$brut = array('type' => 'personne', 'source' => '12', 'valeur_presente' => '',
              'delai_arrivee' => '99999', 'delai_depart' => '');
$reglages = presenciumPersonne::normaliserReglages($brut);
verifie('source devient un entier', $reglages['source'], 12);
verifie('valeur présente vide retombe sur 1', $reglages['valeur_presente'], '1');
verifie('délai d\'arrivée borné', $reglages['delai_arrivee'], presenciumPersonne::DELAI_ARRIVEE_MAX);
verifie('délai de départ vide retombe sur le défaut', $reglages['delai_depart'], 15);
verifie('du texte ne vaut pas zéro',
        presenciumPersonne::normaliserReglages(array('delai_depart' => 'quinze'))['delai_depart'], 15);
verifie('un zéro réellement tapé vaut zéro',
        presenciumPersonne::normaliserReglages(array('delai_depart' => '0'))['delai_depart'], 0);
/* Un négatif retombe sur le DÉFAUT, jamais sur zéro. Le ramener à zéro
 * supprimerait l'anti-rebond en silence : la personne serait déclarée absente au
 * premier trou de couverture de sa balise. Seul un « 0 » réellement tapé vaut
 * zéro, et un signe moins n'est pas une demande de zéro. */
verifie('un négatif retombe sur le défaut',
        presenciumPersonne::normaliserReglages(array('delai_depart' => '-5'))['delai_depart'],
        presenciumPersonne::DELAI_DEPART_DEFAUT);
verifie('un délai d\'arrivée négatif aussi',
        presenciumPersonne::normaliserReglages(array('delai_arrivee' => -1))['delai_arrivee'],
        presenciumPersonne::DELAI_ARRIVEE_DEFAUT);
verifie('hors borne haute, on plafonne',
        presenciumPersonne::normaliserReglages(array('delai_depart' => 9999))['delai_depart'],
        presenciumPersonne::DELAI_DEPART_MAX);
verifie('valeur présente conservée telle quelle',
        presenciumPersonne::normaliserReglages(array('valeur_presente' => ' home '))['valeur_presente'], 'home');
verifie('aucune configuration : les défauts',
        presenciumPersonne::normaliserReglages(null)['delai_depart'],
        presenciumPersonne::DELAI_DEPART_DEFAUT);
/* Idempotence : evaluer() rappelle la normalisation sur des réglages déjà
 * normalisés. Si elle n'était pas neutre, le délai dériverait à chaque appel. */
verifie('normalisation idempotente',
        presenciumPersonne::normaliserReglages($reglages), $reglages);

/* ------------------------------------------------------------------ 2 ---
 * Le signal brut sous toutes ses formes. Une commande binaire rend 1, MQTT rend
 * « on », un script rend true. Le piège tient en une ligne : en PHP, la chaîne
 * '0' est fausse mais la chaîne 'off' est vraie — un simple test de vérité
 * déclarerait présent quelqu'un dont la balise dit explicitement le contraire. */
echo "\nLecture du signal brut\n";
verifieVrai('la chaîne 1', presenciumPersonne::estPresentBrut('1', '1'));
verifieVrai('l\'entier 1', presenciumPersonne::estPresentBrut(1, '1'));
verifieVrai('le booléen true', presenciumPersonne::estPresentBrut(true, '1'));
verifieVrai('la chaîne on', presenciumPersonne::estPresentBrut('on', '1'));
verifieVrai('la chaîne ON', presenciumPersonne::estPresentBrut('ON', '1'));
verifieVrai('la chaîne present', presenciumPersonne::estPresentBrut('present', '1'));
verifie('l\'entier 0', presenciumPersonne::estPresentBrut(0, '1'), false);
verifie('la chaîne 0', presenciumPersonne::estPresentBrut('0', '1'), false);
verifie('la chaîne vide', presenciumPersonne::estPresentBrut('', '1'), false);
verifie('null', presenciumPersonne::estPresentBrut(null, '1'), false);
verifie('le booléen false', presenciumPersonne::estPresentBrut(false, '1'), false);
verifie('la chaîne off, vraie pour PHP, fausse ici',
        presenciumPersonne::estPresentBrut('off', '1'), false);
/* Valeur attendue personnalisée : on ne devine plus, on compare. */
verifieVrai('valeur maison, casse ignorée', presenciumPersonne::estPresentBrut('Home', 'home'));
verifie('valeur maison, autre valeur', presenciumPersonne::estPresentBrut('not_home', 'home'), false);
verifieVrai('valeur numérique 2 contre « 2 »', presenciumPersonne::estPresentBrut(2, '2'));
verifie('valeur attendue 2, signal 1', presenciumPersonne::estPresentBrut(1, '2'), false);

/* ------------------------------------------------------------------ 3 ---
 * Le coeur de la décision, et son asymétrie. Une arrivée manquée, c'est une
 * lampe qui ne s'allume pas ; un départ inventé, c'est l'alarme qui s'arme sur
 * quelqu'un assis dans son salon. Les deux erreurs n'ont pas le même prix, la
 * règle n'est donc pas symétrique — et c'est cette asymétrie qu'on vérifie. */
echo "\nLa décision de présence\n";
$reglages = presenciumPersonne::normaliserReglages(array('delai_depart' => 15, 'delai_arrivee' => 0));
$t = strtotime('2026-09-19 12:00:00');

$verdict = presenciumPersonne::evaluer('1', $t - 3600, $t, $reglages);
verifie('signal présent de longue date : présente', $verdict['present'], true);
verifie('et la raison le dit', $verdict['raison'], 'presente');
verifie('rien n\'est en cours', $verdict['transitoire'], false);

$verdict = presenciumPersonne::evaluer('1', $t, $t, $reglages);
verifie('arrivée sans délai : présente tout de suite', $verdict['present'], true);

$verdict = presenciumPersonne::evaluer('0', $t - 60, $t, $reglages);
verifie('signal perdu depuis 1 min : toujours présente', $verdict['present'], true);
verifie('mais un départ est en cours', $verdict['raison'], 'depart_en_cours');
verifie('il reste 14 minutes', $verdict['restant'], 14 * 60);
verifie('le signal brut, lui, dit absent', $verdict['brut'], false);

$verdict = presenciumPersonne::evaluer('0', $t - 15 * 60, $t, $reglages);
verifie('à la minute pile, absente', $verdict['present'], false);
verifie('et la raison le dit', $verdict['raison'], 'absente');
verifie('une seconde avant, encore présente',
        presenciumPersonne::evaluer('0', $t - (15 * 60 - 1), $t, $reglages)['present'], true);

/* Le délai d'arrivée, lui, retarde la présence : utile pour une balise qui
 * s'annonce en passant devant la maison. */
$lent = presenciumPersonne::normaliserReglages(array('delai_arrivee' => 120, 'delai_depart' => 15));
verifie('arrivée non confirmée : encore absente',
        presenciumPersonne::evaluer('1', $t - 60, $t, $lent)['present'], false);
verifie('et l\'arrivée est annoncée en cours',
        presenciumPersonne::evaluer('1', $t - 60, $t, $lent)['raison'], 'arrivee_en_cours');
verifie('deux minutes plus tard, présente',
        presenciumPersonne::evaluer('1', $t - 120, $t, $lent)['present'], true);

/* Date de changement inconnue ou venue de l'avenir : on repart de maintenant,
 * donc du bon côté de l'asymétrie. Une horloge remise à l'heure ne doit pas
 * déclarer tout le monde absent d'un coup. */
verifie('date inconnue, signal absent : encore présente',
        presenciumPersonne::evaluer('0', 0, $t, $reglages)['present'], true);
verifie('date dans l\'avenir : encore présente',
        presenciumPersonne::evaluer('0', $t + 7200, $t, $reglages)['present'], true);
/* Aucun état mémorisé : deux appels identiques rendent le même verdict, et le
 * verdict ne dépend que du signal. C'est ce qui rend la décision juste au
 * premier cron qui suit un redémarrage. */
verifie('le verdict ne dépend que de ses entrées',
        presenciumPersonne::evaluer('0', $t - 300, $t, $reglages),
        presenciumPersonne::evaluer('0', $t - 300, $t, $reglages));

/* ------------------------------------------------------------------ 4 ---
 * Le libellé d'état. Le compte à rebours n'est pas cosmétique : sans lui,
 * « Présent » alors que la balise est partie passe pour un bug, et
 * l'utilisateur descend le délai à zéro — exactement ce qu'il ne faut pas. */
echo "\nLibellé d'état\n";
verifie('présente', presenciumPersonne::libelleEtat(
        presenciumPersonne::evaluer('1', $t - 3600, $t, $reglages)), 'Présent');
verifie('absente', presenciumPersonne::libelleEtat(
        presenciumPersonne::evaluer('0', $t - 3600, $t, $reglages)), 'Absent');
verifie('départ en cours, arrondi au supérieur', presenciumPersonne::libelleEtat(
        presenciumPersonne::evaluer('0', $t - 8 * 60 - 10, $t, $reglages)), 'Départ en cours (7 min)');
verifie('jamais « 0 min » pour une attente qui dure', presenciumPersonne::libelleEtat(
        presenciumPersonne::evaluer('0', $t - (15 * 60 - 1), $t, $reglages)), 'Départ en cours (1 min)');
verifie('arrivée en cours', presenciumPersonne::libelleEtat(
        presenciumPersonne::evaluer('1', $t - 10, $t, $lent)), 'Arrivée en cours');
verifie('un verdict vide ne casse rien', presenciumPersonne::libelleEtat(null), 'Absent');

/* ------------------------------------------------------------------ 5 ---
 * L'ÉPISODE DE REBOND RÉELLEMENT MESURÉ.
 *
 * Le 19 septembre 2026, la seconde balise (commande 2015) a signalé cinq
 * absences en un peu plus d'une heure : 2 s, 7,0 min, 5,7 min, 4,1 min et 13,6 min,
 * séparées de présences de 11,4 min, 8,7 min, 10,5 min et 5,0 min. Personne
 * n'avait bougé. Sans anti-rebond, cela fait cinq départs, cinq arrivées, et
 * autant d'occasions d'armer l'alarme sur quelqu'un qui n'est pas sorti.
 *
 * On rejoue donc la chronologie telle qu'elle s'est produite — une suite
 * d'horodatages continus, pas cinq appels indépendants, parce que ce qui compte
 * est justement que chaque creux reparte du précédent et que la date du dernier
 * changement soit celle que le coeur aurait mémorisée. Le plugin est interrogé
 * toutes les dix secondes, comme un cron qui ne dormirait jamais. */
echo "\nÉpisode de rebond du 19 septembre 2026\n";

/* Durées mesurées, en secondes, dans l'ordre où elles se sont produites. */
$mesures = array(
    array('brut' => true,  'duree' => 1800),  /* la balise est là depuis une demi-heure */
    array('brut' => false, 'duree' => 2),     /* 2 s      */
    array('brut' => true,  'duree' => 684),   /* 11,4 min */
    array('brut' => false, 'duree' => 420),   /* 7,0 min  */
    array('brut' => true,  'duree' => 522),   /* 8,7 min  */
    array('brut' => false, 'duree' => 342),   /* 5,7 min  */
    array('brut' => true,  'duree' => 630),   /* 10,5 min */
    array('brut' => false, 'duree' => 246),   /* 4,1 min  */
    array('brut' => true,  'duree' => 300),   /* 5,0 min  */
    array('brut' => false, 'duree' => 816),   /* 13,6 min */
    array('brut' => true,  'duree' => 1800),  /* et la balise revient pour de bon */
);

/* La chronologie : chaque segment porte l'horodatage de son propre début,
 * c'est-à-dire la date du dernier changement du signal brut — exactement ce que
 * le coeur conserve sur la commande info et ce que le plugin relit. */
function chronologie($_debut, $_mesures) {
    $suite = array();
    $horodatage = $_debut;
    foreach ($_mesures as $mesure) {
        $suite[] = array('brut' => $mesure['brut'], 'debut' => $horodatage,
                         'fin' => $horodatage + $mesure['duree']);
        $horodatage += $mesure['duree'];
    }
    return $suite;
}

/* Rejoue la chronologie seconde par seconde (par pas de dix) et compte ce que
 * le plugin aurait déclaré. */
function rejouer($_chronologie, $_reglages, $_pas = 10) {
    $bilan = array('instants' => 0, 'instantsAbsents' => 0, 'creux' => 0,
                   'creuxAbsorbes' => 0, 'creuxConvertis' => 0, 'premiereAbsence' => null);
    foreach ($_chronologie as $segment) {
        $converti = false;
        for ($maintenant = $segment['debut']; $maintenant < $segment['fin']; $maintenant += $_pas) {
            $verdict = presenciumPersonne::evaluer($segment['brut'] ? '1' : '0',
                                                   $segment['debut'], $maintenant, $_reglages);
            $bilan['instants']++;
            if ($verdict['present'] === false) {
                $bilan['instantsAbsents']++;
                $converti = true;
                if ($bilan['premiereAbsence'] === null) {
                    $bilan['premiereAbsence'] = $maintenant;
                }
            }
        }
        if (!$segment['brut']) {
            $bilan['creux']++;
            $bilan[$converti ? 'creuxConvertis' : 'creuxAbsorbes']++;
        }
    }
    return $bilan;
}

$debut = strtotime('2026-09-19 07:00:00');
$chrono = chronologie($debut, $mesures);
$fin = $chrono[count($chrono) - 1]['fin'];
verifie('la chronologie compte cinq creux',
        count(array_filter($mesures, function ($_m) { return !$_m['brut']; })), 5);
verifie('elle couvre 2 h 06 en tout', (int) round(($fin - $debut) / 60), 126);
/* Du premier creux à la fin du dernier : une heure et six minutes pendant
 * lesquelles la balise a dit cinq fois « parti » sans que personne ne bouge. */
verifie('les cinq creux tiennent en 66 minutes',
        (int) round(($chrono[9]['fin'] - $chrono[1]['debut']) / 60), 66);

/* Le réglage par défaut du plugin : quinze minutes. */
$defaut = presenciumPersonne::normaliserReglages(array('delai_depart' => 15, 'delai_arrivee' => 0));
$bilan = rejouer($chrono, $defaut);
verifie('aucune absence déclarée de tout l\'épisode', $bilan['instantsAbsents'], 0);
verifie('les cinq creux sont absorbés', $bilan['creuxAbsorbes'], 5);
verifie('aucun creux converti en départ', $bilan['creuxConvertis'], 0);
$plusLongCreux = 0;
foreach ($mesures as $mesure) {
    if (!$mesure['brut']) {
        $plusLongCreux = max($plusLongCreux, $mesure['duree']);
    }
}
verifie('le plus long creux fait 13,6 min', round($plusLongCreux / 60, 1), 13.6);
verifieVrai('il reste sous le délai de départ', $plusLongCreux < $defaut['delai_depart'] * 60);

/* Le contre-essai, sans lequel le contrôle précédent ne prouverait rien : avec
 * un délai de trois minutes, la même chronologie produit quatre fausses
 * absences. Le jeu d'essai mesure donc bien quelque chose, et le réglage est
 * bien ce qui fait la différence. */
$court = presenciumPersonne::normaliserReglages(array('delai_depart' => 3, 'delai_arrivee' => 0));
$bilanCourt = rejouer($chrono, $court);
verifie('à 3 minutes, quatre creux deviennent des départs', $bilanCourt['creuxConvertis'], 4);
verifie('seul le creux de 2 s est encore absorbé', $bilanCourt['creuxAbsorbes'], 1);
/* Et elle tombe exactement trois minutes après le début du creux de 7 min,
 * le premier qui dépasse ce délai : le deuxième de la chronologie. */
verifie('la première fausse absence, trois minutes après le creux de 7 min',
        $bilanCourt['premiereAbsence'], $chrono[3]['debut'] + 180);
verifie('soit 07 h 44 ce matin-là',
        date('H:i:s', $bilanCourt['premiereAbsence']), '07:44:26');

/* Sans anti-rebond du tout — ce que fait un plugin qui recopie le signal — les
 * cinq creux passent, et avec eux cinq armements d'alarme possibles. */
$aucun = presenciumPersonne::normaliserReglages(array('delai_depart' => 0, 'delai_arrivee' => 0));
verifie('sans délai, les cinq creux deviennent des départs',
        rejouer($chrono, $aucun)['creuxConvertis'], 5);

/* ------------------------------------------------------------------ 6 ---
 * Les vraies absences du même historique : 92, 271 et 548 minutes. Un
 * anti-rebond qui absorbe tout ne servirait à rien — il rendrait simplement la
 * maison éternellement occupée. Elles doivent passer, et passer vite : le
 * quart d'heure de confirmation, pas une minute de plus. */
echo "\nLes vraies absences\n";
foreach (array(92, 271, 548) as $minutes) {
    $depart = strtotime('2026-09-19 08:00:00');
    $titre = 'absence de ' . $minutes . ' min';
    verifie($titre . ' : présente à 14 min',
            presenciumPersonne::evaluer('0', $depart, $depart + 14 * 60, $defaut)['present'], true);
    verifie($titre . ' : absente à 15 min',
            presenciumPersonne::evaluer('0', $depart, $depart + 15 * 60, $defaut)['raison'], 'absente');
    verifie($titre . ' : absente jusqu\'au bout',
            presenciumPersonne::evaluer('0', $depart, $depart + $minutes * 60, $defaut)['present'], false);
    /* Le retard payé pour ne pas inventer de départ : toujours le même quart
     * d'heure, quelle que soit la durée de l'absence. On le compte minute par
     * minute plutôt que de le supposer. */
    $declarees = 0;
    for ($minute = 0; $minute < $minutes; $minute++) {
        if (presenciumPersonne::evaluer('0', $depart, $depart + $minute * 60, $defaut)['present'] === false) {
            $declarees++;
        }
    }
    verifie($titre . ' : déclarée absente ' . ($minutes - 15) . ' min sur ' . $minutes,
            $declarees, $minutes - 15);
}
/* Toute la démonstration tient dans cet encadrement : le délai par défaut est
 * au-dessus du plus long rebond mesuré et très au-dessous de la plus courte des
 * vraies absences. Entre 13,6 min et 92 min, il y a de la place — c'est ce qui
 * rend le réglage tenable, et ce qui cesserait d'être vrai si la balise se
 * mettait à rebondir pendant vingt minutes. */
verifieVrai('le délai sépare bien les faux des vrais',
            $plusLongCreux < $defaut['delai_depart'] * 60
            && $defaut['delai_depart'] * 60 < 92 * 60);

/* ------------------------------------------------------------------ 7 ---
 * La normalisation d'une règle. Une règle malformée qui reste en place est pire
 * qu'une règle absente : l'utilisateur la voit dans la liste et la croit armée.
 * Celles qui ne peuvent pas se décider disparaissent, les autres reçoivent des
 * valeurs sur lesquelles on peut raisonner. */
echo "\nNormalisation d'une règle\n";
verifie('une règle sans déclencheur est jetée',
        presenciumRegles::normaliserRegle(array('nom' => 'Sans rien')), null);
verifie('un déclencheur inventé est jeté',
        presenciumRegles::normaliserRegle(array('declencheur' => 'quand_je_veux')), null);
verifie('ce qui n\'est pas un tableau est jeté', presenciumRegles::normaliserRegle('règle'), null);

$regle = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'nom' => '  J\'arme en partant  ',
    'actif' => '1',
    'personne' => '42',
    'minutes' => '-3',
    'attente' => '9999',
    'repos' => 'dix',
    'conditions' => array(
        'heures' => array('actif' => '1', 'de' => '22h00', 'a' => ''),
        'jours' => array('1', 1, 9, '5'),
        'lignes' => array(
            array('cmd' => '1987', 'operateur' => '=>', 'valeur' => '1'),
            array('cmd' => 0, 'operateur' => '==', 'valeur' => 'ligne vide de la modale'),
            'pas un tableau',
        ),
    ),
    'actions' => array(
        array('cmd' => '#[Automatisme][Foyer][Armer]#', 'cmd_id' => '4242'),
        array('cmd' => ''),
    ),
));
verifie('un identifiant est attribué', substr($regle['id'], 0, 2), 'r-');
verifie('le nom est débarrassé de ses espaces', $regle['nom'], 'J\'arme en partant');
verifie('la case cochée devient 1', $regle['actif'], 1);
verifie('la personne est oubliée hors arrivee/depart', $regle['personne'], 0);
verifie('les minutes négatives deviennent zéro', $regle['minutes'], 0);
verifie('l\'attente est plafonnée', $regle['attente'], presenciumRegles::ATTENTE_MAX);
verifie('un repos en toutes lettres vaut zéro', $regle['repos'], 0);
verifie('la simulation absente vaut 0', $regle['simulation'], 0);
verifie('l\'heure de début est normalisée', $regle['conditions']['heures']['de'], '22:00');
verifie('une borne vide reprend sa valeur par défaut', $regle['conditions']['heures']['a'], '23:59');
verifie('les jours sont dédoublonnés et bornés',
        implode(',', $regle['conditions']['jours']), '1,5');
verifie('une seule ligne de condition retenue', count($regle['conditions']['lignes']), 1);
verifie('l\'opérateur inconnu retombe sur ==',
        $regle['conditions']['lignes'][0]['operateur'], '==');
verifie('la commande de condition est un entier', $regle['conditions']['lignes'][0]['cmd'], 1987);
verifie('une seule action retenue', count($regle['actions']), 1);
verifie('les options manquantes deviennent un tableau', $regle['actions'][0]['options'], array());
verifie('cmd_id résolu en entier', $regle['actions'][0]['cmd_id'], 4242);

/* La case décochée n'arrive pas du tout dans le formulaire : l'absence vaut
 * « non ». Une règle qu'on croit désactivée et qui agit quand même, c'est une
 * alarme qui s'arme ; l'inverse se voit dans la liste. */
verifie('actif absent vaut 0',
        presenciumRegles::normaliserRegle(array('declencheur' => 'arrivee'))['actif'], 0);
/* Les jours, eux, ne sont qu'un filtre : un filtre vide ne filtre rien. */
verifie('jours absents : tous les jours',
        count(presenciumRegles::normaliserRegle(array('declencheur' => 'arrivee'))['conditions']['jours']), 7);
verifie('la personne est gardée pour une arrivée',
        presenciumRegles::normaliserRegle(array('declencheur' => 'arrivee', 'personne' => '7'))['personne'], 7);

/* La liste complète : les rebuts disparaissent, les identifiants en double sont
 * refaits — deux règles homonymes partageraient leur repos et leur attente, la
 * seconde se croirait au repos parce que la première vient d'agir. */
$liste = presenciumRegles::normaliser(array(
    array('declencheur' => 'arrivee', 'id' => 'r-aaaaaa'),
    array('declencheur' => 'depart', 'id' => 'r-aaaaaa'),
    array('nom' => 'orpheline'),
    'n\'importe quoi',
));
verifie('deux règles sur quatre survivent', count($liste), 2);
verifieVrai('leurs identifiants diffèrent', $liste[0]['id'] !== $liste[1]['id']);
verifie('une liste absente rend un tableau vide', presenciumRegles::normaliser(null), array());
verifieVrai('deux identifiants tirés de suite diffèrent',
            presenciumRegles::nouvelIdentifiant() !== presenciumRegles::nouvelIdentifiant());

/* L'identifiant de repli est DÉTERMINISTE. normaliser() est rejouée à chaque
 * lecture de la configuration : une règle importée, restaurée ou écrite par un
 * script, qui n'a jamais eu d'identifiant, en recevrait sinon un nouveau à
 * chaque passage du cron. Les clés d'attente et de repos en dérivant, une
 * attente posée à une minute ne serait jamais retrouvée à la suivante — « arme
 * cinq minutes après le départ » se réarmerait sans fin et n'armerait jamais. */
$sansId = array(array('declencheur' => 'depart_dernier'), array('declencheur' => 'arrivee_premier'));
$lecture1 = presenciumRegles::normaliser($sansId);
$lecture2 = presenciumRegles::normaliser($sansId);
verifie('deux lectures rendent le même identifiant', $lecture1[0]['id'], $lecture2[0]['id']);
verifie('et le même pour la seconde règle', $lecture1[1]['id'], $lecture2[1]['id']);
verifieVrai('deux règles sans identifiant n\'en partagent pas un',
            $lecture1[0]['id'] !== $lecture1[1]['id']);
verifie('il garde la forme d\'un identifiant de règle', substr($lecture1[0]['id'], 0, 2), 'r-');
verifie('un identifiant existant n\'est jamais remplacé',
        presenciumRegles::normaliser(array(array('declencheur' => 'depart', 'id' => 'r-5f3a')))[0]['id'],
        'r-5f3a');
/* Hors liste, on ne connaît pas le rang : la création d'une règle neuve garde
 * un identifiant tiré au sort, sans quoi deux règles créées dans deux foyers
 * naîtraient jumelles. */
verifieVrai('une règle créée seule reçoit un identifiant tiré au sort',
            presenciumRegles::normaliserRegle(array('declencheur' => 'arrivee'))['id']
            !== presenciumRegles::normaliserRegle(array('declencheur' => 'arrivee'))['id']);

/* ------------------------------------------------------------------ 8 ---
 * LES TRANSITIONS, cas par cas. C'est là que se décide ce qui part. Chaque cas
 * limite listé ici a une conséquence concrète : déclencher à tort, c'est armer
 * une alarme sur une maison habitée ou mettre le chauffage en veille un
 * dimanche après-midi. */
echo "\nTransitions d'un foyer\n";

/* Premier démarrage : aucun instantané mémorisé. Prendre le cache vide pour une
 * maison vide déclencherait tout d'un coup au premier cron suivant
 * l'installation. Un plugin qui s'installe observe, il n'arme pas. */
verifie('sans instantané précédent, rien',
        resume(presenciumRegles::transitions(null, instantane(array(1, 2), 2))), '');
verifie('un cache illisible ne déclenche rien',
        resume(presenciumRegles::transitions(array('total' => 2), instantane(array(1), 2))), '');

/* Maison vide, une personne rentre : la maison s'ouvre ET quelqu'un arrive. */
verifie('maison vide, une personne arrive',
        resume(presenciumRegles::transitions(instantane(array(), 2), instantane(array(1), 2))),
        'arrivee_premier:0 arrivee:1');

/* Deux personnes dans la même minute : les deux arrivées sortent, dans l'ordre
 * des identifiants pour que le journal soit relisible, et le foyer est complet. */
verifie('deux personnes arrivent ensemble',
        resume(presenciumRegles::transitions(instantane(array(), 2), instantane(array(2, 1), 2))),
        'arrivee_premier:0 arrivee:1 arrivee:2 arrivee_tous:0');

/* Le dernier manquant rentre alors que quelqu'un était déjà là : pas de
 * premier arrivé, mais bien « tout le monde est là ». */
verifie('le dernier manquant rentre',
        resume(presenciumRegles::transitions(instantane(array(1), 2), instantane(array(1, 2), 2))),
        'arrivee:2 arrivee_tous:0');

/* Une personne part, il en reste : surtout pas depart_dernier. */
verifie('une personne part, il en reste',
        resume(presenciumRegles::transitions(instantane(array(1, 2), 2), instantane(array(1), 2))),
        'depart:2');

/* La dernière part : la maison devient vide, et le départ individuel vient
 * d'abord — elle sort, puis la maison est vide. */
verifie('la dernière part',
        resume(presenciumRegles::transitions(instantane(array(1), 2), instantane(array(), 2))),
        'depart:1 depart_dernier:0');

verifie('rien ne change : rien ne part',
        resume(presenciumRegles::transitions(instantane(array(1, 2), 2), instantane(array(2, 1), 2))), '');

/* L'un sort à la minute où l'autre rentre : les arrivées passent avant les
 * départs, sans quoi une règle « la maison se vide » partirait sur un instantané
 * qui n'a jamais existé. */
verifie('croisement : l\'arrivée d\'abord',
        resume(presenciumRegles::transitions(instantane(array(1), 2), instantane(array(2), 2))),
        'arrivee:2 depart:1');

/* Une personne retirée du foyer disparaît de l'instantané exactement comme si
 * elle venait de partir. Décocher une case dans la configuration ne doit pas
 * armer l'alarme — et c'est le pire moment pour le faire, puisque
 * l'utilisateur a les mains dans le plugin. */
verifie('personne retirée du foyer : aucun départ',
        resume(presenciumRegles::transitions(instantane(array(1, 2), 2), instantane(array(1), 1))), '');
verifie('dernière personne retirée : la maison ne « se vide » pas',
        resume(presenciumRegles::transitions(instantane(array(1), 1), instantane(array(), 0))), '');
/* Retirer la seule personne absente rendrait le foyer complet sans que personne
 * n'arrive : arrivee_tous exige une arrivée. */
verifie('retirer l\'absent n\'annonce pas « tout le monde est là »',
        resume(presenciumRegles::transitions(instantane(array(1), 2), instantane(array(1), 1))), '');
/* La garde sur le changement de composition est SYMÉTRIQUE. Cocher une personne
 * déjà chez elle et enregistrer la fait apparaître dans l'instantané exactement
 * comme si elle venait d'arriver : sans cette garde, la séance de réglage
 * produit `arrivee` — et `arrivee_premier` ou `arrivee_tous` avec — donc une
 * règle « la maison n'est plus vide → désarmer » qui part toute seule, sur une
 * maison dont l'occupation n'a pas bougé d'un cheveu. Le total qui augmente est
 * la seule trace disponible de l'ajout, comme le total qui diminue l'est du
 * retrait. */
verifie('ajouter une personne présente : aucune arrivée',
        resume(presenciumRegles::transitions(instantane(array(1), 1), instantane(array(1, 2), 2))), '');
verifie('ajouter une personne présente à une maison vide n\'ouvre pas la maison',
        resume(presenciumRegles::transitions(instantane(array(), 1), instantane(array(2), 2))), '');
verifie('ajouter une personne présente n\'annonce pas « tout le monde est là »',
        resume(presenciumRegles::transitions(instantane(array(1), 2), instantane(array(1, 2, 3), 3))), '');
/* Un foyer sans personne ne peut pas être « complet ». */
verifie('foyer sans personne déclarée',
        resume(presenciumRegles::transitions(instantane(array(), 0), instantane(array(3), 0))),
        'arrivee_premier:0 arrivee:3');
/* Les identifiants relus d'un cache JSON reviennent en chaînes : sans
 * conversion, array_diff inventerait un départ et une arrivée à chaque passage. */
verifie('identifiants en chaînes : aucune fausse transition',
        resume(presenciumRegles::transitions(
            array('presents' => array('1' => 'Balise A', '2' => 'Balise B'), 'total' => 2),
            array('presents' => array(1 => 'Balise A', 2 => 'Balise B'), 'total' => '2'))), '');

/* ------------------------------------------------------------------ 9 ---
 * L'appariement règle / transition. Une règle inactive doit quand même
 * correspondre : c'est l'appelant qui teste la case et journalise
 * « desactivee ». Sans cette trace, l'utilisateur qui cherche pourquoi rien ne
 * se passe n'a rien à lire. */
echo "\nRègle et transition\n";
$surBaliseB = array('declencheur' => 'depart', 'personne' => 7, 'actif' => 1);
$surQuiconque = array('declencheur' => 'depart', 'personne' => 0, 'actif' => 1);
$depart7 = array('type' => 'depart', 'personne' => 7);
$depart9 = array('type' => 'depart', 'personne' => 9);
verifieVrai('la bonne personne', presenciumRegles::correspond($surBaliseB, $depart7));
verifie('une autre personne', presenciumRegles::correspond($surBaliseB, $depart9), false);
verifieVrai('n\'importe qui', presenciumRegles::correspond($surQuiconque, $depart9));
verifie('un autre déclencheur',
        presenciumRegles::correspond($surQuiconque, array('type' => 'arrivee', 'personne' => 7)), false);
verifieVrai('une règle inactive correspond quand même',
        presenciumRegles::correspond(array('declencheur' => 'depart', 'personne' => 0, 'actif' => 0), $depart7));
verifieVrai('déclencheur sans personne',
        presenciumRegles::correspond(array('declencheur' => 'depart_dernier'),
                                     array('type' => 'depart_dernier', 'personne' => 0)));
verifie('n\'importe quoi ne correspond à rien', presenciumRegles::correspond(null, $depart7), false);

/* ----------------------------------------------------------------- 10 ---
 * Les comparaisons de conditions. Le piège est le seuil numérique : en PHP 8,
 * deux chaînes non numériques se comparent caractère par caractère, et '10' >
 * '9' serait faux. Un seuil de luminosité ou de température se tromperait une
 * fois sur dix sans jamais lever d'erreur. */
echo "\nComparaison d'une condition\n";
verifieVrai('10 > 9 est numérique', presenciumRegles::comparer('10', '>', '9'));
verifie('9 > 10 est faux', presenciumRegles::comparer('9', '>', '10'), false);
verifieVrai('10 >= 10', presenciumRegles::comparer(10, '>=', '10'));
verifieVrai('5 < 20 est numérique', presenciumRegles::comparer('5', '<', '20'));
verifieVrai('5 <= 5', presenciumRegles::comparer('5', '<=', 5));
verifieVrai('on == on', presenciumRegles::comparer('on', '==', 'on'));
verifieVrai('ON == on, la casse ne compte pas', presenciumRegles::comparer('ON', '==', 'on'));
verifieVrai('on != off', presenciumRegles::comparer('on', '!=', 'off'));
verifie('on == off est faux', presenciumRegles::comparer('on', '==', 'off'), false);
verifieVrai('1 == 1 sous deux écritures', presenciumRegles::comparer(1, '==', '1'));
verifieVrai('21.3 == 21.3 malgré les flottants',
            presenciumRegles::comparer(0.1 + 0.2, '==', 0.3));
verifieVrai('true vaut 1', presenciumRegles::comparer(true, '==', '1'));
verifieVrai('« Salon » > « Cuisine » alphabétiquement',
            presenciumRegles::comparer('Salon', '>', 'Cuisine'));
/* Ne jamais lever, et ne jamais se croire satisfaite sur une entrée absurde :
 * une condition corrompue doit empêcher d'agir, pas autoriser. */
verifie('un opérateur inconnu rend false', presenciumRegles::comparer('1', '=>', '1'), false);
verifie('un tableau ne casse rien', presenciumRegles::comparer(array(1), '==', '1'), false);
verifie('null n\'est pas zéro', presenciumRegles::comparer(null, '==', '0'), false);
verifieVrai('null vaut la chaîne vide', presenciumRegles::comparer(null, '==', ''));
verifie('un objet ne casse rien', presenciumRegles::comparer(new stdClass(), '==', ''), false);

/* ----------------------------------------------------------------- 11 ---
 * L'horaire et les jours. La plage qui franchit minuit est le cas classique :
 * testée naïvement, « 22:00 -> 06:00 » n'est jamais vraie et la règle ne part
 * jamais la nuit, c'est-à-dire exactement quand on l'a écrite. */
echo "\nHoraire d'une règle\n";
$jour = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'conditions' => array('heures' => array('actif' => 1, 'de' => '08:00', 'a' => '18:00')),
));
verifieVrai('en pleine plage', presenciumRegles::horaireOk($jour, strtotime('2026-09-21 12:00')));
verifieVrai('à la borne de début', presenciumRegles::horaireOk($jour, strtotime('2026-09-21 08:00')));
verifieVrai('à la borne de fin', presenciumRegles::horaireOk($jour, strtotime('2026-09-21 18:00')));
verifie('avant la plage', presenciumRegles::horaireOk($jour, strtotime('2026-09-21 07:59')), false);
verifie('après la plage', presenciumRegles::horaireOk($jour, strtotime('2026-09-21 18:01')), false);

$nuit = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'conditions' => array('heures' => array('actif' => 1, 'de' => '22:00', 'a' => '06:00')),
));
verifieVrai('22:30, dans la nuit', presenciumRegles::horaireOk($nuit, strtotime('2026-09-21 22:30')));
verifieVrai('23:59, toujours dedans', presenciumRegles::horaireOk($nuit, strtotime('2026-09-21 23:59')));
verifieVrai('00:30, après minuit, encore dedans',
            presenciumRegles::horaireOk($nuit, strtotime('2026-09-22 00:30')));
verifieVrai('05:59, presque fini', presenciumRegles::horaireOk($nuit, strtotime('2026-09-22 05:59')));
verifieVrai('06:00, la borne compte', presenciumRegles::horaireOk($nuit, strtotime('2026-09-22 06:00')));
verifie('06:01, terminé', presenciumRegles::horaireOk($nuit, strtotime('2026-09-22 06:01')), false);
verifie('midi, hors de la nuit', presenciumRegles::horaireOk($nuit, strtotime('2026-09-22 12:00')), false);

/* Horaire désactivé : la plage ne filtre plus rien, même mal remplie. */
$sansHoraire = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'conditions' => array('heures' => array('actif' => 0, 'de' => '22:00', 'a' => '06:00')),
));
verifieVrai('horaire décoché : toujours d\'accord',
            presenciumRegles::horaireOk($sansHoraire, strtotime('2026-09-22 12:00')));

/* Les jours. Le 21 septembre 2026 est un lundi, le 26 un samedi. */
$semaine = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'conditions' => array('jours' => array(1, 2, 3, 4, 5)),
));
verifieVrai('lundi coché', presenciumRegles::horaireOk($semaine, strtotime('2026-09-21 12:00')));
verifie('samedi décoché', presenciumRegles::horaireOk($semaine, strtotime('2026-09-26 12:00')), false);

/* Le piège du franchissement de minuit croisé avec les jours : à 5 h du matin
 * le samedi, on est encore dans la nuit du vendredi pour qui a coché les cases.
 * Sans ce rattachement, la règle s'arrêterait au milieu de sa dernière nuit. */
$nuitSemaine = presenciumRegles::normaliserRegle(array(
    'declencheur' => 'depart_dernier',
    'conditions' => array('heures' => array('actif' => 1, 'de' => '22:00', 'a' => '06:00'),
                          'jours' => array(1, 2, 3, 4, 5)),
));
verifieVrai('vendredi 23:00, dedans',
            presenciumRegles::horaireOk($nuitSemaine, strtotime('2026-09-25 23:00')));
verifieVrai('samedi 05:00 appartient à la nuit du vendredi',
            presenciumRegles::horaireOk($nuitSemaine, strtotime('2026-09-26 05:00')));
verifie('samedi 23:00, hors des jours cochés',
        presenciumRegles::horaireOk($nuitSemaine, strtotime('2026-09-26 23:00')), false);
verifie('dimanche 05:00 appartient à la nuit du samedi, décochée',
        presenciumRegles::horaireOk($nuitSemaine, strtotime('2026-09-27 05:00')), false);
/* Lundi 05:00 appartient à la nuit du dimanche : décochée elle aussi. C'est le
 * rattachement au jour de la veille qui le dit, et c'est bien ce que
 * l'utilisateur a demandé en cochant « du lundi au vendredi ». */
verifie('lundi 05:00 appartient à la nuit du dimanche',
        presenciumRegles::horaireOk($nuitSemaine, strtotime('2026-09-21 05:00')), false);
verifie('une règle qui n\'en est pas une ne passe pas',
        presenciumRegles::horaireOk(null, strtotime('2026-09-21 12:00')), false);
verifieVrai('une règle sans conditions passe toujours',
            presenciumRegles::horaireOk(array('declencheur' => 'arrivee'), strtotime('2026-09-27 03:00')));

/* ----------------------------------------------------------------- 12 ---
 * Les libellés du journal. Un journal qu'on ne relit pas ne sert à rien, et
 * c'est pourtant lui qui révèle les faux positifs en mode simulation. */
echo "\nLibellés de déclencheur\n";
$noms = array(7 => 'Balise B', 9 => 'Balise A');
verifie('la maison devient vide',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'depart_dernier'), $noms),
        'La maison devient vide');
verifie('tout le monde est là',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'arrivee_tous'), $noms),
        'Tout le monde est là');
verifie('arrivée de quelqu\'un',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'arrivee', 'personne' => 0), $noms),
        'Quelqu\'un arrive');
verifie('départ nommé',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'depart', 'personne' => 7), $noms),
        'Départ de Balise B');
verifie('personne inconnue, libellé quand même lisible',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'depart', 'personne' => 42), $noms),
        'Départ de la personne #42');
verifie('vide depuis',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'vide_depuis', 'minutes' => 30), $noms),
        'Vide depuis 30 min');
verifie('occupée depuis',
        presenciumRegles::libelleDeclencheur(array('declencheur' => 'occupee_depuis', 'minutes' => 5), $noms),
        'Occupée depuis 5 min');
verifie('déclencheur inconnu', presenciumRegles::libelleDeclencheur(array(), null), 'Déclencheur inconnu');

/* ---------------------------------------------------------------- BILAN --- */
echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
