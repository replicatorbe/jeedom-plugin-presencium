<?php
/* Jeu d'essai hors ligne du moteur de règles : attentes et relances.
 *
 *   php tests/moteur.php
 *
 * tests/run.php éprouve ce qui ignore Jeedom par construction — la décision de
 * présence, la lecture des règles. Le moteur, lui, vit dans des traits de la
 * classe eqLogic : il lit des commandes, écrit un état et un journal. Il se
 * laisse pourtant éprouver hors ligne, pourvu qu'on lui tende une classe
 * d'essai qui porte les MÊMES traits et remplace seulement ce qui touche au
 * monde extérieur : l'état tenu en mémoire au lieu d'un fichier, le journal
 * dans un tableau, les commandes de condition dans un registre qu'on règle à
 * la main.
 *
 * Le code essayé est donc le vrai — executerRegle(), traiterAttentes(),
 * jouerRegles(), transitionTemporelle() —, pas une copie qu'il faudrait tenir à
 * jour. Ce qui est remplacé est listé dans la classe foyerEssai, et rien
 * d'autre ne l'est.
 *
 * Ce qu'on y vérifie tient en une phrase : une règle qui réessaie doit
 * réessayer quand on le lui demande, s'arrêter quand on le lui dit, et ne
 * jamais agir deux fois. Et une règle « À heure fixe » doit tomber à son
 * heure, une fois, sans rattraper la veille ni ce qui précède sa création. Chacun de ces trois manquements se paie d'une porte
 * restée ouverte ou verrouillée à contretemps, sans une erreur nulle part.
 */

date_default_timezone_set('Europe/Brussels');

/* ------------------------------------------------------------ DOUBLURES ---
 * Le strict nécessaire du coeur de Jeedom, et rien qui décide : la traduction
 * rend le texte tel quel, le log se tait, cmd::byId() lit un registre. */
function __($_texte, $_fichier = '') {
    return $_texte;
}

class log {
    public static function add($_plugin, $_niveau, $_message) {
    }
}

/* Une commande d'information dont on règle la valeur à la main : c'est la
 * caméra qui voit ou ne voit pas de mouvement. */
class commandeEssai {
    public $valeur;
    private $_nom;
    public function __construct($_nom, $_valeur) {
        $this->_nom = $_nom;
        $this->valeur = $_valeur;
    }
    public function getType() {
        return 'info';
    }
    public function execCmd() {
        return $this->valeur;
    }
    public function getHumanName() {
        return $this->_nom;
    }
}

class cmd {
    public static $registre = array();
    public static function byId($_id) {
        return isset(self::$registre[(int) $_id]) ? self::$registre[(int) $_id] : null;
    }
}

require_once __DIR__ . '/../core/class/presenciumRegles.class.php';
require_once __DIR__ . '/../core/class/presenciumExecution.class.php';
require_once __DIR__ . '/../core/class/presenciumFoyerMoteur.class.php';

/*
 * Un foyer d'essai : les deux traits du moteur, et à la place de ce qui touche
 * au monde extérieur, des doublures en mémoire. Une méthode de la classe
 * l'emporte sur celle d'un trait : c'est ce qui permet de garder
 * transitionTemporelle() et jouerRegles() réels tout en remplaçant
 * instantane() et detailPresence(), qui liraient le cache de Jeedom.
 */
class foyerEssai {
    use presenciumExecution, presenciumFoyerMoteur;

    const TYPE_FOYER = 'foyer';
    const TYPE_PERSONNE = 'personne';
    const CACHE_SUITE = 'presencium::suite::';
    const MOTS_CLES_ACTION = array('wait', 'sleep', 'ask', 'stop', 'scenario', 'variable');
    const ACTIONS_BLOQUANTES = array('wait', 'sleep', 'ask');
    const ACTIONS_HORS_ESSAI = array('wait', 'sleep', 'ask', 'jeedom_poweroff', 'jeedom_reboot');
    public static $_profondeurFoyer = 0;

    public $etat = array('attentes' => array(), 'repos' => array(), 'temporel' => array(), 'foyer' => array());
    public $journal = array();
    public $configuration = array();

    public function type() {
        return self::TYPE_FOYER;
    }
    public function getConfiguration($_cle, $_defaut = null) {
        return isset($this->configuration[$_cle]) ? $this->configuration[$_cle] : $_defaut;
    }
    public function getHumanName() {
        return '[Essai][Foyer]';
    }
    /* La simulation est celle de la règle, toujours cochée ici : aucune action
     * ne doit partir d'un jeu d'essai, et c'est justement ce que la simulation
     * garantit dans executerActions(). */
    public function enSimulation($_regle = null) {
        return true;
    }
    public function instantane($_maintenant = null) {
        return instantane(array(1), 2);
    }
    public function detailPresence($_maintenant, $_instantane = null) {
        return 'présences';
    }
    public function nomsPersonnes() {
        return array();
    }
    public function journalAjouter($_entree) {
        $this->journal[] = $_entree;
    }
    public function etatValeur($_section, $_cle = null, $_defaut = null) {
        $valeur = isset($this->etat[$_section]) ? $this->etat[$_section] : null;
        if ($_cle === null) {
            return ($valeur === null) ? $_defaut : $valeur;
        }
        return (is_array($valeur) && isset($valeur[$_cle])) ? $valeur[$_cle] : $_defaut;
    }
    public function etatPoser($_section, $_cle, $_valeur) {
        if ($_cle === null) {
            $this->etat[$_section] = $_valeur;
        } elseif ($_valeur === null) {
            unset($this->etat[$_section][$_cle]);
        } else {
            $this->etat[$_section][$_cle] = $_valeur;
        }
        return true;
    }
    /* L'attente en cours d'une règle, ou null. */
    public function attente($_id) {
        return $this->etatValeur('attentes', $_id);
    }
}

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

/* Un instantané de foyer, tel que presencium::instantane() le rend. */
function instantane($_presents, $_total) {
    $presents = array();
    foreach ($_presents as $id) {
        $presents[$id] = 'Personne ' . $id;
    }
    return array('presents' => $presents, 'total' => $_total);
}

/* La règle de la porte : quelqu'un arrive, pas de mouvement devant la caméra,
 * verrouiller. La commande 1 est la caméra. */
function reglePorte($_reglages = array()) {
    return presenciumRegles::normaliserRegle(array_merge(array(
        'id'          => 'r-porte',
        'nom'         => 'Verrouiller la porte',
        'actif'       => 1,
        'declencheur' => 'arrivee_premier',
        'attente'     => 0,
        'relance'     => 5,
        'relance_max' => 60,
        'simulation'  => 1,
        'conditions'  => array('lignes' => array(array('cmd' => 1, 'operateur' => '==', 'valeur' => '0'))),
        'actions'     => array(array('cmd' => '#[Entrée][Porte][Verrouiller]#')),
    ), $_reglages));
}

/* Un foyer d'essai qui porte cette seule règle. */
function foyerAvec($_regle) {
    $foyer = new foyerEssai();
    $foyer->configuration['regles'] = array($_regle);
    return $foyer;
}

function nombreVerdicts($_journal, $_verdict) {
    $n = 0;
    foreach ($_journal as $entree) {
        if ($entree['verdict'] === $_verdict) {
            $n++;
        }
    }
    return $n;
}

$camera = new commandeEssai('[Jardin][Caméra Sud][Mouvement]', 1);
cmd::$registre[1] = $camera;
$t0 = strtotime('2026-09-29 18:00:00');
$present = instantane(array(1), 2);
$vide = instantane(array(), 2);
$arrivee = array('type' => 'arrivee_premier', 'personne' => 0);

/* ------------------------------------------------------------------ 1 ---
 * Le premier refus pose une relance, par le mécanisme de l'attente. */
echo "\nRelance posée\n";
$regle = reglePorte();
$foyer = foyerAvec($regle);
$camera->valeur = 1;
$rendu = $foyer->executerRegle($regle, $t0, array('transition' => $arrivee, 'instantane' => $present));
verifie('verdict « relance »', $rendu['verdict'], 'relance');
verifieVrai('le détail annonce le prochain essai',
            strpos($rendu['detail'], 'nouvel essai dans 5 min') !== false);
verifie('le détail des conditions reste dans l\'entrée', count($rendu['conditions']), 1);
verifie('… et dit quelle ligne a dit non', $rendu['conditions'][0]['resultat'], false);
verifie('aucune action', count($rendu['actions']), 0);
$attente = $foyer->attente('r-porte');
verifie('attente posée à cinq minutes', $attente['echeance'], $t0 + 300);
verifie('premier essai raté mémorisé', $attente['relance_depuis'], $t0);
verifie('transition d\'origine mémorisée', $attente['transition'], $arrivee);
verifie('pas de repos posé : la règle n\'a pas agi', $foyer->etatValeur('repos', 'r-porte'), null);

/* ------------------------------------------------------------------ 2 ---
 * Les essais suivants, jusqu'à épuisement. Avec 5 minutes sur 60, les essais
 * tombent à 0, 5, … 60 : treize essais, douze relances et un abandon, et le
 * dernier essai a lieu À 60 minutes, pas à 65. */
echo "\nRelance épuisée\n";
for ($t = $t0 + 60; $t <= $t0 + 7200; $t += 60) {
    $foyer->traiterAttentes($t, $present);
}
verifie('douze relances', nombreVerdicts($foyer->journal, 'relance'), 12);
verifie('un seul abandon', nombreVerdicts($foyer->journal, 'conditions_non_remplies'), 1);
$dernier = end($foyer->journal);
verifie('l\'abandon est le dernier mot', $dernier['verdict'], 'conditions_non_remplies');
verifieVrai('l\'abandon dit « relances épuisées après 60 min »',
            strpos($dernier['detail'], 'relances épuisées après 60 min') !== false);
verifie('plus d\'attente en cours', $foyer->attente('r-porte'), null);
verifie('toujours aucune action', nombreVerdicts($foyer->journal, 'declenchee'), 0);

/* La durée se compte depuis le premier essai : relance_depuis ne bouge pas
 * d'un essai à l'autre, sans quoi la règle réessaierait sans fin. */
$foyer = foyerAvec($regle);
$foyer->executerRegle($regle, $t0, array('transition' => $arrivee, 'instantane' => $present));
$foyer->traiterAttentes($t0 + 300, $present);
$attente = $foyer->attente('r-porte');
verifie('deuxième essai : premier essai raté inchangé', $attente['relance_depuis'], $t0);
verifie('deuxième essai : échéance suivante', $attente['echeance'], $t0 + 600);

/* ------------------------------------------------------------------ 3 ---
 * Le calme revient : la règle agit, une seule fois, et la relance s'efface. */
echo "\nRelance réussie\n";
$camera->valeur = 0;
$foyer->traiterAttentes($t0 + 600, $present);
$dernier = end($foyer->journal);
verifie('la règle agit au troisième essai', $dernier['verdict'], 'declenchee');
verifie('l\'action est simulée, jamais envoyée', $dernier['actions'][0]['resultat'], 'simulée');
verifie('la relance est effacée', $foyer->attente('r-porte'), null);
verifie('le repos est posé à l\'instant d\'agir', $foyer->etatValeur('repos', 'r-porte'), $t0 + 600);
$nombre = count($foyer->journal);
for ($t = $t0 + 660; $t <= $t0 + 3600; $t += 60) {
    $foyer->traiterAttentes($t, $present);
}
verifie('plus rien ensuite', count($foyer->journal), $nombre);

/* ------------------------------------------------------------------ 4 ---
 * La personne repart : on n'essaie plus. */
echo "\nRelance annulée par inversion\n";
$camera->valeur = 1;
$foyer = foyerAvec($regle);
$foyer->executerRegle($regle, $t0, array('transition' => $arrivee, 'instantane' => $present));
$foyer->traiterAttentes($t0 + 120, $vide);
$dernier = end($foyer->journal);
verifie('verdict « attente annulée »', $dernier['verdict'], 'attente_annulee');
verifieVrai('le détail parle des relances',
            strpos($dernier['detail'], 'pendant les relances') !== false);
verifie('la relance est effacée', $foyer->attente('r-porte'), null);
$nombre = count($foyer->journal);
$foyer->traiterAttentes($t0 + 300, $present);
verifie('rien à l\'échéance d\'origine', count($foyer->journal), $nombre);

/* ------------------------------------------------------------------ 5 ---
 * L'essai manuel ne relance jamais : il exécute, c'est son rôle. */
echo "\nEssai manuel\n";
$foyer = foyerAvec($regle);
$rendu = $foyer->executerRegle($regle, $t0, array('instantane' => $present), true);
verifie('verdict « essai »', $rendu['verdict'], 'essai');
verifie('aucune relance posée', $foyer->attente('r-porte'), null);
verifie('ni repos', $foyer->etatValeur('repos', 'r-porte'), null);
/* Et il ne touche pas non plus à une relance en cours. */
$foyer->executerRegle($regle, $t0, array('transition' => $arrivee, 'instantane' => $present));
$foyer->executerRegle($regle, $t0 + 60, array('instantane' => $present), true);
verifieVrai('une relance en cours survit à un essai', is_array($foyer->attente('r-porte')));

/* ------------------------------------------------------------------ 6 ---
 * Les réglages qui ne relancent pas. */
echo "\nSans relance\n";
$sans = reglePorte(array('relance' => 0));
$foyer = foyerAvec($sans);
$rendu = $foyer->executerRegle($sans, $t0, array('transition' => $arrivee, 'instantane' => $present));
verifie('relance à zéro : le refus de toujours', $rendu['verdict'], 'conditions_non_remplies');
verifie('relance à zéro : aucune attente', $foyer->attente('r-porte'), null);
verifieVrai('relance à zéro : pas de mention d\'abandon', strpos($rendu['detail'], 'abandon') === false);

$trop = reglePorte(array('relance' => 20, 'relance_max' => 10));
$foyer = foyerAvec($trop);
$rendu = $foyer->executerRegle($trop, $t0, array('transition' => $arrivee, 'instantane' => $present));
verifie('intervalle plus long que la durée : abandon', $rendu['verdict'], 'conditions_non_remplies');
verifieVrai('… qui dit pourquoi', strpos($rendu['detail'], 'dépasse sa durée maximale') !== false);
verifie('… sans attente', $foyer->attente('r-porte'), null);

/* L'horaire ne se relance pas : hors de sa plage, la règle n'attend pas
 * qu'elle s'ouvre. */
$nuit = reglePorte(array('conditions' => array(
    'heures' => array('actif' => 1, 'de' => '22:00', 'a' => '06:00'),
    'lignes' => array(array('cmd' => 1, 'operateur' => '==', 'valeur' => '0')))));
$foyer = foyerAvec($nuit);
$rendu = $foyer->executerRegle($nuit, $t0, array('transition' => $arrivee, 'instantane' => $present));
verifie('hors horaire : pas de relance', $rendu['verdict'], 'hors_horaire');
verifie('hors horaire : aucune attente', $foyer->attente('r-porte'), null);
/* Et une relance qui sort de la plage s'arrête là. */
$foyer->etatPoser('attentes', 'r-porte', array('echeance' => $t0, 'pose' => $t0 - 300,
                                              'transition' => $arrivee, 'relance_depuis' => $t0 - 300));
$foyer->traiterAttentes($t0, $present);
$dernier = end($foyer->journal);
verifie('relance sortie de la plage : hors horaire', $dernier['verdict'], 'hors_horaire');
verifie('… et plus d\'attente', $foyer->attente('r-porte'), null);

/* ------------------------------------------------------------------ 7 ---
 * Attente puis relance : la durée se compte depuis la fin de l'attente, qui
 * est le premier essai raté — pas depuis le déclenchement. */
echo "\nAttente suivie d'une relance\n";
$patiente = reglePorte(array('attente' => 10));
$foyer = foyerAvec($patiente);
$rendu = $foyer->executerRegle($patiente, $t0, array('transition' => $arrivee, 'instantane' => $present));
verifie('d\'abord l\'attente', $rendu['verdict'], 'en_attente');
verifieVrai('l\'attente n\'est pas une relance', !isset($foyer->attente('r-porte')['relance_depuis']));
$foyer->traiterAttentes($t0 + 600, $present);
$attente = $foyer->attente('r-porte');
verifie('au bout de l\'attente : relance', end($foyer->journal)['verdict'], 'relance');
verifie('premier essai raté : la fin de l\'attente', $attente['relance_depuis'], $t0 + 600);
verifie('prochain essai cinq minutes plus tard', $attente['echeance'], $t0 + 900);

/* ------------------------------------------------------------------ 8 ---
 * Un nouveau déclenchement qui agit tout de suite efface la relance en cours :
 * sans quoi, à son échéance, elle contournerait le repos et verrouillerait une
 * deuxième fois. */
echo "\nNouveau déclenchement pendant une relance\n";
$foyer = foyerAvec($regle);
$camera->valeur = 1;
$foyer->executerRegle($regle, $t0, array('transition' => $arrivee, 'instantane' => $present));
$camera->valeur = 0;
$rendu = $foyer->executerRegle($regle, $t0 + 120, array('transition' => $arrivee, 'instantane' => $present));
verifie('le nouveau déclenchement agit', $rendu['verdict'], 'declenchee');
verifie('la relance en cours est effacée', $foyer->attente('r-porte'), null);
$nombre = count($foyer->journal);
$foyer->traiterAttentes($t0 + 300, $present);
verifie('rien à l\'ancienne échéance', count($foyer->journal), $nombre);

/* Le repos, lui, ne bloque pas une relance : il n'est posé qu'à l'instant
 * d'agir, et une relance qui arrive à échéance est une attente terminée. */
$reposante = reglePorte(array('repos' => 30));
$foyer = foyerAvec($reposante);
$camera->valeur = 1;
$foyer->executerRegle($reposante, $t0, array('transition' => $arrivee, 'instantane' => $present));
$camera->valeur = 0;
$foyer->traiterAttentes($t0 + 300, $present);
verifie('repos de 30 min : la relance agit à 5 min', end($foyer->journal)['verdict'], 'declenchee');

/* ------------------------------------------------------------------ 9 ---
 * Les déclencheurs temporels : l'épisode est consommé au premier essai, les
 * suivants passent par les attentes seules. Aucun passage ne doit présenter la
 * règle deux fois. */
echo "\nDéclencheur temporel\n";
$videDepuis = presenciumRegles::normaliserRegle(array(
    'id' => 'r-vide', 'nom' => 'Fermer les volets', 'actif' => 1, 'declencheur' => 'vide_depuis',
    'minutes' => 30, 'relance' => 5, 'relance_max' => 30, 'simulation' => 1,
    'conditions' => array('lignes' => array(array('cmd' => 1, 'operateur' => '==', 'valeur' => '0'))),
    'actions' => array(array('cmd' => '#[Salon][Volets][Fermer]#')),
));
$foyer = foyerAvec($videDepuis);
$foyer->etat['foyer'] = array('vide_depuis' => $t0 - 1800, 'occupee_depuis' => 0);
$camera->valeur = 1;
/* Le même ordre que rafraichirFoyerSousVerrou() : les attentes, puis les
 * règles. */
$foyer->traiterAttentes($t0, $vide);
$foyer->jouerRegles($t0, array(), $vide);
verifie('premier essai : relance', end($foyer->journal)['verdict'], 'relance');
verifie('l\'épisode est consommé', $foyer->etatValeur('temporel', 'r-vide'), $t0 - 1800);
$parPassage = array();
for ($t = $t0 + 60; $t <= $t0 + 3600; $t += 60) {
    $avant = count($foyer->journal);
    $foyer->traiterAttentes($t, $vide);
    $foyer->jouerRegles($t, array(), $vide);
    $parPassage[] = count($foyer->journal) - $avant;
}
verifie('jamais plus d\'une entrée par passage', max($parPassage), 1);
verifie('six relances en tout', nombreVerdicts($foyer->journal, 'relance'), 6);
verifie('puis un abandon', nombreVerdicts($foyer->journal, 'conditions_non_remplies'), 1);
verifie('aucune action', nombreVerdicts($foyer->journal, 'declenchee'), 0);

/* Et la relance réussie ne se double pas d'un passage de jouerRegles(). */
$foyer = foyerAvec($videDepuis);
$foyer->etat['foyer'] = array('vide_depuis' => $t0 - 1800, 'occupee_depuis' => 0);
$camera->valeur = 1;
$foyer->jouerRegles($t0, array(), $vide);
$camera->valeur = 0;
for ($t = $t0 + 60; $t <= $t0 + 1800; $t += 60) {
    $foyer->traiterAttentes($t, $vide);
    $foyer->jouerRegles($t, array(), $vide);
}
verifie('une seule exécution pour l\'épisode', nombreVerdicts($foyer->journal, 'declenchee'), 1);

/* Quelqu'un rentre : la relance d'une règle « maison vide » s'annule. */
$foyer = foyerAvec($videDepuis);
$foyer->etat['foyer'] = array('vide_depuis' => $t0 - 1800, 'occupee_depuis' => 0);
$camera->valeur = 1;
$foyer->jouerRegles($t0, array(), $vide);
$foyer->traiterAttentes($t0 + 60, $present);
verifie('retour pendant la relance : annulée', end($foyer->journal)['verdict'], 'attente_annulee');

/* ----------------------------------------------------------------- 10 ---
 * Le déclencheur « À heure fixe », par le vrai moteur : jouerRegles() et
 * traiterAttentes(), dans l'ordre de rafraichirFoyerSousVerrou(). Le cas
 * d'usage est celui de la documentation — le mode nuit : à 21:30, 22:00…
 * 00:30, si la maison est occupée, l'alarme désarmée, la télé et les lampes
 * éteintes, passer l'alarme en mode nuit. Les commandes 2 à 5 sont la
 * présence du foyer, l'alarme armée, la télé et les lampes. */
echo "\nÀ heure fixe\n";
$presence = new commandeEssai('[Maison][Foyer][Présence]', 1);
$armee = new commandeEssai('[Maison][Alarme][Armée]', 0);
$tele = new commandeEssai('[Salon][TV][Allumée]', 0);
$lampes = new commandeEssai('[Salon][Lampes][Etat]', 0);
cmd::$registre[2] = $presence;
cmd::$registre[3] = $armee;
cmd::$registre[4] = $tele;
cmd::$registre[5] = $lampes;

function regleNuit($_reglages = array()) {
    return presenciumRegles::normaliserRegle(array_merge(array(
        'id'           => 'r-nuit',
        'nom'          => 'Mode nuit automatique',
        'actif'        => 1,
        'declencheur'  => 'heure',
        'heures_fixes' => array('21:30', '22:00', '22:30', '23:00', '00:00', '00:30'),
        'attente'      => 0,
        'relance'      => 5,
        'relance_max'  => 25,
        'repos'        => 0,
        'simulation'   => 1,
        'conditions'   => array('lignes' => array(
            array('cmd' => 2, 'operateur' => '==', 'valeur' => '1'),
            array('cmd' => 3, 'operateur' => '==', 'valeur' => '0'),
            array('cmd' => 4, 'operateur' => '==', 'valeur' => '0'),
            array('cmd' => 5, 'operateur' => '==', 'valeur' => '0'),
        )),
        'actions'      => array(array('cmd' => '#[Maison][Alarme][Mode nuit]#')),
    ), $_reglages));
}

/* Un passage du cron : les attentes, puis les règles — l'ordre de
 * rafraichirFoyerSousVerrou(). Rend le nombre d'entrées écrites. */
function passage($_foyer, $_quand, $_instantane) {
    $avant = count($_foyer->journal);
    $t = is_int($_quand) ? $_quand : strtotime($_quand);
    $_foyer->traiterAttentes($t, $_instantane);
    $_foyer->jouerRegles($t, array(), $_instantane);
    return count($_foyer->journal) - $avant;
}

/* Rejoue une plage minute par minute ; rend le plus grand nombre d'entrées
 * écrites par un même passage. */
function nuitDe($_foyer, $_de, $_a, $_instantane, $_pendant = null) {
    $max = 0;
    for ($t = strtotime($_de); $t <= strtotime($_a); $t += 60) {
        if ($_pendant !== null) {
            $_pendant($t);
        }
        $max = max($max, passage($_foyer, $t, $_instantane));
    }
    return $max;
}

$nuit = regleNuit();

/* La soirée idéale : tout est prêt à 21:30. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:00:02', $present);
verifie('premier passage : rien, la liste est seulement mémorisée', count($foyer->journal), 0);
$memoire = $foyer->etatValeur('temporel', 'r-nuit');
verifie('mémoire posée dans `temporel`, à la minute', $memoire['vue'], strtotime('2026-09-29 21:00:00'));
passage($foyer, '2026-09-29 21:30:03', $present);
$entree = end($foyer->journal);
verifie('21:30 : déclenchée', $entree['verdict'], 'declenchee');
verifie('libellé « À 21:30 »', $entree['declencheur'], 'À 21:30');
verifie('l\'heure est dans l\'entrée', $entree['heure_fixe'], '21:30');
verifie('simulation : l\'action est simulée', $entree['actions'][0]['resultat'], 'simulée');
verifieVrai('… et l\'entrée le dit', $entree['simulation'] === true);
verifie('quatre conditions relues', count($entree['conditions']), 4);
$nombre = count($foyer->journal);
passage($foyer, '2026-09-29 21:30:40', $present);
passage($foyer, '2026-09-29 21:31:02', $present);
verifie('21:30 ne tombe qu\'une fois', count($foyer->journal), $nombre);

/* Une nuit entière, tout prêt à chaque heure (l'alarme « désarmée » reste à
 * zéro puisque rien n'est exécuté) : six déclenchements, et le lendemain six
 * encore. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 20:59:00', $present);
$max = nuitDe($foyer, '2026-09-29 21:00:05', '2026-09-30 02:00:05', $present);
verifie('une nuit : six déclenchements', nombreVerdicts($foyer->journal, 'declenchee'), 6);
verifie('jamais plus d\'une entrée par passage', $max, 1);
$libelles = array();
foreach ($foyer->journal as $entree) {
    $libelles[] = $entree['declencheur'];
}
verifie('dans l\'ordre de la soirée, minuit compris', implode(' | ', $libelles),
        'À 21:30 | À 22:00 | À 22:30 | À 23:00 | À 00:00 | À 00:30');
nuitDe($foyer, '2026-09-30 21:00:05', '2026-10-01 01:00:05', $present);
verifie('la nuit suivante : six de plus', nombreVerdicts($foyer->journal, 'declenchee'), 12);

/* Pas de rétroactivité : la règle vue pour la première fois à 22:02. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 22:02:10', $present);
passage($foyer, '2026-09-29 22:03:10', $present);
verifie('règle créée à 22:02 : 22:00 n\'est pas jouée', count($foyer->journal), 0);
passage($foyer, '2026-09-29 22:30:01', $present);
verifie('… 22:30 l\'est', end($foyer->journal)['declencheur'], 'À 22:30');

/* Le cron qui a manqué la minute : rattrapé dans les cinq minutes. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:20:00', $present);
passage($foyer, '2026-09-29 21:33:30', $present);
verifie('cron repris à 21:33 : 21:30 rattrapée', end($foyer->journal)['declencheur'], 'À 21:30');
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:20:00', $present);
passage($foyer, '2026-09-29 21:41:00', $present);
verifie('cron repris à 21:41 : 21:30 perdue', count($foyer->journal), 0);

/* La relance, cas d'usage de la documentation : une lampe encore allumée à
 * 21:30, éteinte à 21:44. Essais à 21:30, 21:35, 21:40 ratés, 21:45 réussi. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:00:00', $present);
$lampes->valeur = 1;
$max = nuitDe($foyer, '2026-09-29 21:30:00', '2026-09-29 21:59:00', $present, function ($_t) use ($lampes) {
    if ($_t >= strtotime('2026-09-29 21:44:00')) {
        $lampes->valeur = 0;
    }
});
verifie('lampe éteinte à 21:44 : trois essais ratés', nombreVerdicts($foyer->journal, 'relance'), 3);
$reussie = null;
foreach ($foyer->journal as $entree) {
    if ($entree['verdict'] === 'declenchee') {
        $reussie = $entree;
    }
}
verifie('… puis le mode nuit, une fois', nombreVerdicts($foyer->journal, 'declenchee'), 1);
verifie('… au bout de la relance, le libellé dit encore « À 21:30 »', $reussie['declencheur'], 'À 21:30');
verifie('… et la relance est effacée', $foyer->attente('r-nuit'), null);
verifie('jamais plus d\'une entrée par passage', $max, 1);

/* La lampe reste allumée : cinq essais sur vingt-cinq minutes, un abandon à
 * 21:55, et 22:00 repart d'une série neuve. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:00:00', $present);
$lampes->valeur = 1;
$max = nuitDe($foyer, '2026-09-29 21:30:00', '2026-09-29 21:59:00', $present);
verifie('lampe allumée : cinq relances', nombreVerdicts($foyer->journal, 'relance'), 5);
verifie('… un abandon', nombreVerdicts($foyer->journal, 'conditions_non_remplies'), 1);
verifieVrai('… « relances épuisées après 25 min »',
            strpos(end($foyer->journal)['detail'], 'relances épuisées après 25 min') !== false);
passage($foyer, '2026-09-29 22:00:00', $present);
verifie('22:00 : nouvelle série', end($foyer->journal)['verdict'], 'relance');
verifie('… comptée depuis 22:00', $foyer->attente('r-nuit')['relance_depuis'], strtotime('2026-09-29 22:00:00'));
$lampes->valeur = 0;

/* Une relance plus longue que l'écart entre deux heures : l'heure suivante
 * annule la série en cours et la remplace — jamais deux exécutions. */
$longue = regleNuit(array('relance' => 5, 'relance_max' => 60));
$foyer = foyerAvec($longue);
passage($foyer, '2026-09-29 21:00:00', $present);
$lampes->valeur = 1;
nuitDe($foyer, '2026-09-29 21:30:00', '2026-09-29 21:59:00', $present);
$lampes->valeur = 0;
$ecrites = passage($foyer, '2026-09-29 22:00:00', $present);
verifie('22:00 pendant la série de 21:30 : deux lignes', $ecrites, 2);
$lignes = array_slice($foyer->journal, -2);
verifie('… la série annulée', $lignes[0]['verdict'], 'attente_annulee');
verifieVrai('… « l\'heure suivante (22:00) prend le relais »',
            strpos($lignes[0]['detail'], 'l\'heure suivante (22:00) prend le relais') !== false);
verifie('… puis 22:00 agit', $lignes[1]['verdict'], 'declenchee');
verifie('… sous son propre libellé', $lignes[1]['declencheur'], 'À 22:00');
verifie('une seule exécution en tout', nombreVerdicts($foyer->journal, 'declenchee'), 1);

/* Le repos : une heure de repos après 21:30, 22:00 est écartée et le dit,
 * 22:30 repart. */
$reposante = regleNuit(array('repos' => 60));
$foyer = foyerAvec($reposante);
passage($foyer, '2026-09-29 21:00:00', $present);
nuitDe($foyer, '2026-09-29 21:30:00', '2026-09-29 22:40:00', $present);
$verdicts = array();
foreach ($foyer->journal as $entree) {
    $verdicts[] = $entree['declencheur'] . '=' . $entree['verdict'];
}
verifie('repos de 60 min', implode(' ', $verdicts), 'À 21:30=declenchee À 22:00=repos À 22:30=declenchee');

/* Décochée : l'heure est consommée, le journal le dit, et recocher la règle
 * deux minutes plus tard ne la fait pas jouer en retard. */
$decochee = regleNuit(array('actif' => 0));
$foyer = foyerAvec($decochee);
passage($foyer, '2026-09-29 21:00:00', $present);
passage($foyer, '2026-09-29 21:30:00', $present);
verifie('décochée à 21:30 : « désactivée »', end($foyer->journal)['verdict'], 'desactivee');
$foyer->configuration['regles'] = array(regleNuit());
passage($foyer, '2026-09-29 21:32:00', $present);
verifie('recochée à 21:32 : 21:30 n\'est pas rejouée', count($foyer->journal), 1);

/* L'attente ne s'annule pas : rien ne s'inverse. C'est la ligne « présence
 * du foyer » qui dit non, au moment d'agir. */
$patiente = regleNuit(array('attente' => 10, 'relance' => 0));
$foyer = foyerAvec($patiente);
passage($foyer, '2026-09-29 21:00:00', $present);
passage($foyer, '2026-09-29 21:30:00', $present);
verifie('attente de 10 min posée', end($foyer->journal)['verdict'], 'en_attente');
$presence->valeur = 0;
passage($foyer, '2026-09-29 21:35:00', $vide);
verifie('la maison se vide : l\'attente n\'est pas annulée', end($foyer->journal)['verdict'], 'en_attente');
passage($foyer, '2026-09-29 21:40:00', $vide);
$entree = end($foyer->journal);
verifie('à 21:40, la condition de présence dit non', $entree['verdict'], 'conditions_non_remplies');
verifie('… première ligne fausse', $entree['conditions'][0]['resultat'], false);
verifie('… sous le libellé « À 21:30 »', $entree['declencheur'], 'À 21:30');
$presence->valeur = 1;

/* Le bouton Tester : joue, sans toucher à la mémoire des heures. */
$foyer = foyerAvec($nuit);
passage($foyer, '2026-09-29 21:00:00', $present);
$avant = $foyer->etatValeur('temporel', 'r-nuit');
$rendu = $foyer->executerRegle($nuit, strtotime('2026-09-29 21:15:00'), array('instantane' => $present), true);
verifie('Tester : verdict « essai »', $rendu['verdict'], 'essai');
verifie('… libellé : la liste', $rendu['declencheur'], 'À 00:00, 00:30, 21:30, 22:00, 22:30, 23:00');
verifie('… mémoire des heures intacte', $foyer->etatValeur('temporel', 'r-nuit'), $avant);
passage($foyer, '2026-09-29 21:30:00', $present);
verifie('… et 21:30 tombe quand même', end($foyer->journal)['declencheur'], 'À 21:30');

/* Une règle sans heure ne fait rien, jamais. */
$muette = regleNuit(array('heures_fixes' => array()));
$foyer = foyerAvec($muette);
nuitDe($foyer, '2026-09-29 21:00:00', '2026-09-30 01:00:00', $present);
verifie('sans heure : jamais rien', count($foyer->journal), 0);

/* ---------------------------------------------------------------- BILAN --- */
echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
