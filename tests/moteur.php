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
 *
 * L'alarme liée à une vraie centrale (section 11) passe par le même
 * procédé : le trait presenciumAlarme réel, une centrale en doublure.
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
require_once __DIR__ . '/../core/class/presenciumAlarme.class.php';

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
    /* Lu par executerActions() : voir presencium::$_regleEnCours. */
    public static $_regleEnCours = '';

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

/* ----------------------------------------------------------------- 11 ---
 * L'alarme liée, par le vrai trait presenciumAlarme : armer(), modeNuit(),
 * alarmeSynchroniser(), mettreEnService(). Une vraie centrale ne se laisse
 * pas éprouver à la demande — on n'arme pas la maison pour un test —, et
 * c'est justement pour cela que ce qui suit doit être vrai sans elle :
 *
 *  - l'état suit la centrale, et seulement elle ;
 *  - un ordre part vers la commande liée, jamais vers la bascule locale, et
 *    l'état n'est pas écrit d'avance ;
 *  - la simulation retient l'ordre ;
 *  - une commande liée disparue échoue au journal, sans exception ;
 *  - un foyer non lié se comporte exactement comme avant. */
echo "\nAlarme liée\n";

/* Une commande action de la centrale : elle compte ses exécutions, et peut
 * lever comme le ferait une passerelle injoignable. */
class commandeActionEssai {
    public $executions = 0;
    public $leve = '';
    private $_nom;
    public function __construct($_nom) {
        $this->_nom = $_nom;
    }
    public function getType() {
        return 'action';
    }
    public function execCmd($_options = null) {
        if ($this->leve !== '') {
            throw new Exception($this->leve);
        }
        $this->executions++;
        return null;
    }
    public function getHumanName() {
        return $this->_nom;
    }
}

/* La commande info publiée par le foyer (« Alarme armée ») : relit ce que
 * checkAndUpdateCmd() y a posé, comme le cache du cœur. */
class infoPublieeEssai {
    private $_foyer;
    private $_logicalId;
    public function __construct($_foyer, $_logicalId) {
        $this->_foyer = $_foyer;
        $this->_logicalId = $_logicalId;
    }
    public function execCmd() {
        return isset($this->_foyer->publie[$this->_logicalId]) ? $this->_foyer->publie[$this->_logicalId] : null;
    }
}

/* Un foyer d'essai pour l'alarme : le trait réel, et à la place de la base
 * et du cache, des tableaux. getId() rend 0 : objetFrais() rend alors l'objet
 * lui-même, sans relecture — il n'y a pas de base à relire. Le moteur de
 * règles est là aussi, pour éprouver le chemin « une règle exécute Armer du
 * foyer » de bout en bout. */
class alarmeEssai {
    use presenciumExecution, presenciumAlarme;

    const TYPE_FOYER = 'foyer';
    const TYPE_PERSONNE = 'personne';
    const CACHE_SUITE = 'presencium::suite::';
    const MOTS_CLES_ACTION = array('wait', 'sleep', 'ask', 'stop', 'scenario', 'variable');
    const ACTIONS_BLOQUANTES = array('wait', 'sleep', 'ask');
    const ACTIONS_HORS_ESSAI = array('wait', 'sleep', 'ask', 'jeedom_poweroff', 'jeedom_reboot');
    public static $_profondeurFoyer = 0;
    public static $_regleEnCours = '';
    public static $reglages = array('simulation' => 0);

    public $configuration = array('type' => 'foyer', 'etat_en_service' => 1, 'etat_armee' => 0, 'simulation' => 0);
    public $journal = array();
    public $publie = array();
    public $sauvegardes = 0;
    public $etat = array('attentes' => array(), 'repos' => array(), 'temporel' => array(), 'foyer' => array());

    public function type() {
        return self::TYPE_FOYER;
    }
    public function getId() {
        return 0;
    }
    public function getIsEnable() {
        return 1;
    }
    public function getHumanName() {
        return '[Maison][Foyer]';
    }
    public function getConfiguration($_cle = null, $_defaut = null) {
        if ($_cle === null) {
            return $this->configuration;
        }
        return isset($this->configuration[$_cle]) ? $this->configuration[$_cle] : $_defaut;
    }
    public function setConfiguration($_cle, $_valeur) {
        $this->configuration[$_cle] = $_valeur;
    }
    public function save($_direct = false) {
        $this->sauvegardes++;
    }
    public static function reglageGlobal($_cle, $_defaut) {
        return isset(self::$reglages[$_cle]) ? self::$reglages[$_cle] : $_defaut;
    }
    public function checkAndUpdateCmd($_logicalId, $_valeur) {
        $this->publie[$_logicalId] = $_valeur;
    }
    public function getCmd($_type, $_logicalId) {
        return new infoPublieeEssai($this, $_logicalId);
    }
    public function journalAjouter($_entree) {
        $this->journal[] = $_entree;
    }
    public function detailPresence($_maintenant, $_instantane = null) {
        return 'présences';
    }
    public function instantane($_maintenant = null) {
        return instantane(array(1), 2);
    }
    public function nomsPersonnes() {
        return array();
    }
    public function etatValeur($_section, $_cle = null, $_defaut = null) {
        return $_defaut;
    }
    public function etatPoser($_section, $_cle, $_valeur) {
        return true;
    }
}

/* La centrale de cette installation, telle que le plugin ajaxsiabe l'expose :
 * « Armée » (binaire, 1 en total, nuit ou partiel), « Mode » (texte), et les
 * trois ordres. Les numéros sont ceux de la documentation. */
$centraleArmee = new commandeEssai('[Maison][Hub Ajax][Armée]', 0);
$centraleMode = new commandeEssai('[Maison][Hub Ajax][Mode]', 'Désarmé');
$ordreArmer = new commandeActionEssai('[Maison][Hub Ajax][Armer]');
$ordreNuit = new commandeActionEssai('[Maison][Hub Ajax][Mode nuit]');
$ordreDesarmer = new commandeActionEssai('[Maison][Hub Ajax][Désarmer]');
cmd::$registre[6908] = $centraleArmee;
cmd::$registre[6907] = $centraleMode;
cmd::$registre[7002] = $ordreArmer;
cmd::$registre[7003] = $ordreNuit;
cmd::$registre[7004] = $ordreDesarmer;

function foyerLie($_reglages = array()) {
    $foyer = new alarmeEssai();
    foreach (presenciumRegles::normaliserAlarme(array_merge(array(
        'alarme_liee' => 1, 'alarme_etat' => 6908,
        'alarme_cmd_armer' => 7002, 'alarme_cmd_desarmer' => 7004, 'alarme_cmd_nuit' => 7003,
    ), $_reglages)) as $cle => $valeur) {
        $foyer->configuration[$cle] = $valeur;
    }
    return $foyer;
}
function executionsCentrale() {
    global $ordreArmer, $ordreNuit, $ordreDesarmer;
    return $ordreArmer->executions . '/' . $ordreNuit->executions . '/' . $ordreDesarmer->executions;
}
function remettreCentrale() {
    global $ordreArmer, $ordreNuit, $ordreDesarmer;
    foreach (array($ordreArmer, $ordreNuit, $ordreDesarmer) as $ordre) {
        $ordre->executions = 0;
        $ordre->leve = '';
    }
}

/* ---- non liée : exactement comme avant */
$local = new alarmeEssai();
verifie('non lié : alarmeLiee() est faux', $local->alarmeLiee(), false);
verifie('non lié : armer rend vrai', $local->armer(true, 'admin depuis l\'interface'), true);
verifie('non lié : la clé locale bascule', $local->configuration['etat_armee'], 1);
verifie('non lié : « armée » publiée à 1', $local->publie['armee'], 1);
verifie('non lié : écrit en base', $local->sauvegardes, 1);
verifie('non lié : aucune commande de la centrale touchée', executionsCentrale(), '0/0/0');
verifie('non lié : la synchronisation ne fait rien', $local->alarmeSynchroniser(), null);
verifie('non lié : le détail du journal est celui d\'avant', end($local->journal)['detail'], 'Armement — présences');
$local->armer(false);
verifie('non lié : désarmer rebascule', $local->publie['armee'], 0);
$local->configuration['etat_en_service'] = 0;
verifie('non lié : hors service, armer est refusé', $local->armer(true), false);
verifie('… et reste désarmée', $local->configuration['etat_armee'], 0);
$avant = count($local->journal);
verifie('non lié : Mode nuit refusé', $local->modeNuit('test'), false);
verifie('… au journal, en échec', end($local->journal)['verdict'], 'echec');
verifieVrai('… qui dit pourquoi', strpos(end($local->journal)['detail'], 'relié à aucune alarme') !== false);
verifie('non lié : pas de commande Mode nuit', $local->alarmeNuitDisponible(), false);

/* ---- l'état suit la centrale */
$foyer = foyerLie();
$centraleArmee->valeur = 0;
verifie('lié : première lecture publiée', $foyer->alarmeSynchroniser(), 0);
verifie('… sans ligne au journal (découverte, pas changement)', count($foyer->journal), 0);
$centraleArmee->valeur = 1;
verifie('armée depuis l\'application : suivie', $foyer->alarmeSynchroniser(), 1);
verifie('… publiée sur « Alarme armée »', $foyer->publie['armee'], 1);
verifieVrai('… et journalisée avec la valeur lue',
            strpos(end($foyer->journal)['detail'], 'maintenant armée ([Maison][Hub Ajax][Armée] = 1)') !== false);
$nombre = count($foyer->journal);
$foyer->alarmeSynchroniser();
verifie('relue sans changement : rien de plus au journal', count($foyer->journal), $nombre);
verifie('la clé locale n\'est jamais écrite par la synchronisation', $foyer->configuration['etat_armee'], 0);
$centraleArmee->valeur = null;
verifie('valeur inconnue : pas de publication', $foyer->alarmeSynchroniser(), null);
verifie('… « armée » garde sa dernière valeur, jamais 0 par défaut', $foyer->publie['armee'], 1);
$centraleArmee->valeur = 0;
$foyer->alarmeSynchroniser();
verifie('désarmée à la centrale : suivie', $foyer->publie['armee'], 0);

/* Un état texte, lu comme une ligne de condition. */
$texte = foyerLie(array('alarme_etat' => '#6907#', 'alarme_operateur' => '!=', 'alarme_valeur' => 'Désarmé'));
verifie('état texte : identifiant sans dièses', $texte->configuration['alarme_etat'], 6907);
foreach (array('Désarmé' => 0, 'Armé' => 1, 'Mode nuit' => 1, 'Armé partiel' => 1, 'désarmé' => 0) as $mode => $attendu) {
    $centraleMode->valeur = $mode;
    verifie('« Mode » = ' . $mode . ' → armée ' . $attendu, $texte->alarmeSynchroniser(), $attendu);
}

/* Une commande d'état disparue : rien ne lève, rien ne bouge. */
$perdu = foyerLie(array('alarme_etat' => 99999));
$perdu->publie['armee'] = 1;
verifie('état introuvable : null', $perdu->alarmeSynchroniser(), null);
verifie('… « armée » inchangée', $perdu->publie['armee'], 1);
verifieVrai('… et la page Santé le dit',
            strpos(implode(' ; ', $perdu->alarmeProblemes()), 'introuvable') !== false);
/* Une commande action choisie comme état n'est jamais exécutée. */
$inverse = foyerLie(array('alarme_etat' => 7002));
remettreCentrale();
verifie('action choisie comme état : illisible', $inverse->alarmeSynchroniser(), null);
verifie('… et jamais appuyée', executionsCentrale(), '0/0/0');

/* ---- un ordre part vers la centrale, et l'état attend */
remettreCentrale();
$foyer = foyerLie();
$centraleArmee->valeur = 0;
$foyer->alarmeSynchroniser();
verifie('Armer lié : l\'ordre part', $foyer->armer(true, 'la règle « J\'arme en partant »'), true);
verifie('… vers la commande Armer de la centrale, une fois', executionsCentrale(), '1/0/0');
verifie('… l\'état n\'est pas écrit d\'avance', $foyer->publie['armee'], 0);
verifie('… ni la clé locale', $foyer->configuration['etat_armee'], 0);
verifie('… rien d\'écrit en base', $foyer->sauvegardes, 0);
$entree = end($foyer->journal);
verifie('… journal : déclenchée', $entree['verdict'], 'declenchee');
verifieVrai('… qui nomme la commande exécutée', strpos($entree['detail'], '[Maison][Hub Ajax][Armer] exécutée') !== false);
verifieVrai('… et qui l\'a demandé', strpos($entree['detail'], 'demandé par la règle « J\'arme en partant »') !== false);
$centraleArmee->valeur = 1;
$foyer->alarmeSynchroniser();
verifie('la centrale confirme : l\'état suit', $foyer->publie['armee'], 1);
$foyer->armer(false, 'admin depuis l\'interface');
verifie('Désarmer lié : la commande Désarmer', executionsCentrale(), '1/0/1');
$foyer->modeNuit('le scénario [Maison][Nuit]');
verifie('Mode nuit lié : la commande Mode nuit', executionsCentrale(), '1/1/1');
verifieVrai('… journalisé avec son origine', strpos(end($foyer->journal)['detail'], 'demandé par le scénario [Maison][Nuit]') !== false);
verifie('Mode nuit disponible quand la commande est liée', $foyer->alarmeNuitDisponible(), true);

/* Hors service : ni armement ni mode nuit, le désarmement passe. */
remettreCentrale();
$foyer->configuration['etat_en_service'] = 0;
verifie('hors service : armer refusé', $foyer->armer(true), false);
verifie('hors service : mode nuit refusé', $foyer->modeNuit(), false);
verifie('… rien n\'est parti', executionsCentrale(), '0/0/0');
verifieVrai('… le journal dit « hors service »', strpos(end($foyer->journal)['detail'], 'hors service') !== false);
$foyer->armer(false);
verifie('hors service : désarmer passe toujours', executionsCentrale(), '0/0/1');
$foyer->configuration['etat_en_service'] = 1;

/* Mise hors service : la centrale n'est pas désarmée. */
remettreCentrale();
$centraleArmee->valeur = 1;
$foyer->alarmeSynchroniser();
$foyer->mettreEnService(false);
verifie('mise hors service liée : aucun ordre à la centrale', executionsCentrale(), '0/0/0');
verifie('… « armée » reste celle de la centrale', $foyer->publie['armee'], 1);
verifie('… « en service » publiée à 0', $foyer->publie['en_service'], 0);
verifieVrai('… le journal le précise', strpos(end($foyer->journal)['detail'], 'pas désarmée pour autant') !== false);
$foyer->mettreEnService(true);

/* ---- la simulation retient l'ordre */
remettreCentrale();
$foyer->configuration['simulation'] = 1;
verifie('simulation du foyer : armer', $foyer->armer(true, 'admin depuis l\'interface'), true);
verifie('… rien n\'est exécuté', executionsCentrale(), '0/0/0');
$entree = end($foyer->journal);
verifie('… journal marqué simulation', $entree['simulation'], true);
verifieVrai('… qui dit quelle commande aurait été exécutée',
            strpos($entree['detail'], '[Maison][Hub Ajax][Armer] n\'a pas été exécutée') !== false);
$foyer->modeNuit();
$foyer->armer(false);
verifie('… ni mode nuit ni désarmement', executionsCentrale(), '0/0/0');
$foyer->configuration['simulation'] = 0;
alarmeEssai::$reglages['simulation'] = 1;
$foyer->armer(true);
verifie('simulation globale : rien non plus', executionsCentrale(), '0/0/0');
alarmeEssai::$reglages['simulation'] = 0;

/* ---- une commande liée introuvable, ou qui lève */
remettreCentrale();
$casse = foyerLie(array('alarme_cmd_armer' => 88888, 'alarme_cmd_nuit' => 0));
$resultat = null;
try {
    $resultat = $casse->armer(true, 'test');
    $leve = false;
} catch (Throwable $e) {
    $leve = true;
}
verifie('commande Armer introuvable : aucune exception', $leve, false);
verifie('… rend faux', $resultat, false);
$entree = end($casse->journal);
verifie('… journal en échec', $entree['verdict'], 'echec');
verifieVrai('… qui nomme la commande manquante', strpos($entree['detail'], '#88888# est introuvable') !== false);
verifie('… et ne retombe pas sur la bascule locale', $casse->configuration['etat_armee'], 0);
verifie('sans commande nuit liée : Mode nuit refusé', $casse->modeNuit(), false);
verifieVrai('… au journal', strpos(end($casse->journal)['detail'], 'aucune commande') !== false);
verifie('… et pas proposé', $casse->alarmeNuitDisponible(), false);
$ordreArmer->leve = 'Hub injoignable';
$foyer = foyerLie();
verifie('commande qui lève : rend faux', $foyer->armer(true), false);
verifieVrai('… le message de la passerelle au journal', strpos(end($foyer->journal)['detail'], 'Hub injoignable') !== false);
$ordreArmer->leve = '';
$mauvais = foyerLie(array('alarme_cmd_armer' => 6908));
verifie('une info choisie comme ordre : refusée', $mauvais->armer(true), false);
verifieVrai('… « n\'est pas une commande action »', strpos(end($mauvais->journal)['detail'], 'pas une commande action') !== false);
verifie('problèmes d\'une liaison complète : aucun', foyerLie()->alarmeProblemes(), array());

/* ---- l'origine d'un ordre */
alarmeEssai::$_regleEnCours = '';
verifie('origine : l\'interface', alarmeEssai::origineOrdre(array('user_login' => 'admin')), 'admin depuis l\'interface');
verifie('origine : inconnue, et dit comme telle', alarmeEssai::origineOrdre(array()),
        'une commande Jeedom (scénario, plugin ou API)');

/* De bout en bout : une règle dont l'action est la commande Armer du foyer.
 * La doublure fait ce que fait presenciumCmd::execute(). */
class commandeArmerFoyerEssai {
    public $foyer;
    public function getType() {
        return 'action';
    }
    public function getHumanName() {
        return '[Maison][Foyer][Armer]';
    }
    public function getSubType() {
        return 'other';
    }
    public function execCmd($_options = null) {
        return $this->foyer->armer(true, alarmeEssai::origineOrdre(is_array($_options) ? $_options : array()));
    }
}
remettreCentrale();
$foyer = foyerLie();
$armerFoyer = new commandeArmerFoyerEssai();
$armerFoyer->foyer = $foyer;
cmd::$registre[5441] = $armerFoyer;
$regleArmer = presenciumRegles::normaliserRegle(array(
    'id' => 'r-armer', 'nom' => 'J\'arme en partant', 'actif' => 1, 'declencheur' => 'depart_dernier',
    'simulation' => 0,
    'actions' => array(array('cmd' => '#[Maison][Foyer][Armer]#', 'cmd_id' => 5441)),
));
$rendu = $foyer->executerRegle($regleArmer, $t0, array('instantane' => $vide), true);
verifie('règle → Armer du foyer → centrale', executionsCentrale(), '1/0/0');
verifie('… l\'action de la règle est exécutée', $rendu['actions'][0]['resultat'], 'exécutée');
$ordre = null;
foreach ($foyer->journal as $entree) {
    if ($entree['genre'] === 'alarme') {
        $ordre = $entree;
    }
}
verifieVrai('… et le journal de l\'alarme nomme la règle',
            strpos($ordre['detail'], 'demandé par la règle « J\'arme en partant »') !== false);
verifie('… l\'origine ne reste pas collée après la règle', alarmeEssai::$_regleEnCours, '');

/* ---------------------------------------------------------------- BILAN --- */
echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
