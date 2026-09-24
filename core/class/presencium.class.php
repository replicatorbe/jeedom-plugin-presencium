<?php
/* This file is part of Presencium, a plugin for Jeedom.
 * Copyright (C) sMug (Jérôme Fafchamps)
 *
 * Presencium is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Presencium is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Presencium. If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autoload du cœur ne connaît que la classe homonyme du plugin (et
 * presenciumCmd, qu'il cherche dans ce même fichier) : sans ces lignes, les
 * classes auxiliaires et les traits seraient introuvables partout où la classe
 * est chargée autrement que par la page (cron, listener, jeeListener.php),
 * c'est-à-dire précisément là où le plugin travaille. Les traits doivent en
 * plus exister AVANT la déclaration de la classe qui les utilise. */
require_once __DIR__ . '/presenciumPersonne.class.php';
require_once __DIR__ . '/presenciumRegles.class.php';
require_once __DIR__ . '/presenciumSuivi.class.php';
require_once __DIR__ . '/presenciumFoyerMoteur.class.php';
require_once __DIR__ . '/presenciumExecution.class.php';
require_once __DIR__ . '/presenciumAlarme.class.php';
require_once __DIR__ . '/presenciumJournal.class.php';
require_once __DIR__ . '/presenciumAnalyse.class.php';
require_once __DIR__ . '/presenciumSante.class.php';

/*
 * Deux types d'équipement, une seule classe.
 *
 * Une PERSONNE transforme un signal brut qui rebondit — une balise Bluetooth,
 * un ping Wi-Fi — en une présence sur laquelle on peut décider. Un FOYER
 * rassemble des personnes, en tire une occupation, et joue des règles quand
 * quelqu'un arrive ou s'en va.
 *
 * Deux types plutôt que deux plugins, parce que les deux moitiés n'ont de sens
 * qu'ensemble : une personne sans foyer ne déclenche rien, un foyer sans
 * personne ne sait rien.
 *
 * Le plugin ne mémorise aucun état de présence. La présence stabilisée se
 * recalcule à chaque passage à partir du seul signal brut et de la date de son
 * dernier changement : c'est ce qui la rend juste après un redémarrage, une
 * mise à jour ou un vidage de cache, moments où un état mémorisé mentirait
 * silencieusement. Ce qui est gardé en cache ne sert qu'à détecter les FRONTS
 * (ce qui a changé depuis le passage précédent) ; ce qui doit vraiment survivre
 * — l'alarme en service, l'alarme armée, le mode forcé — est écrit dans la
 * configuration de l'équipement. La mémoire de décision d'un foyer (dernier
 * instantané, attentes, repos, épisodes) vit dans data/etat-<id>.json : voir
 * etatCharger().
 *
 * La classe est répartie en traits, un par fichier, pour rester lisible ;
 * pour le cœur comme pour les appelants, c'est toujours une seule classe
 * (`presencium::xxx()`, `$this->xxx()` et `self::` valent partout). Ce
 * fichier garde les constantes et les propriétés — un trait ne peut pas
 * déclarer de constante avant PHP 8.2 —, le cycle de vie, les crons,
 * l'écouteur, les commandes et presenciumCmd :
 *
 *  - presenciumSuivi        le verdict et la publication d'une personne ;
 *  - presenciumFoyerMoteur  l'instantané et le rafraîchissement d'un foyer ;
 *  - presenciumExecution    les règles, leurs attentes et leurs actions ;
 *  - presenciumAlarme       armement, mise en service, simulation ;
 *  - presenciumJournal      le journal, l'état du foyer, les verrous ;
 *  - presenciumAnalyse      l'analyse de l'historique d'une source ;
 *  - presenciumSante        la page Santé et les messages de panne.
 *
 * Deux conséquences à garder en tête en écrivant dans un trait : __CLASS__ y
 * vaut « presencium », et toute propriété, même statique, y suivrait la règle
 * du souligné initial (voir $_profondeurFoyer) — d'où le choix de les laisser
 * toutes ici.
 */
class presencium extends eqLogic {

    use presenciumSuivi, presenciumFoyerMoteur, presenciumExecution, presenciumAlarme,
        presenciumJournal, presenciumAnalyse, presenciumSante;

    const TYPE_PERSONNE = 'personne';
    const TYPE_FOYER = 'foyer';

    /* Préfixes des entrées de cache. Regroupés ici parce qu'ils sont aussi
     * effacés par preRemove() et par presencium_remove() : une clé écrite d'un
     * côté et nettoyée de l'autre finit toujours par diverger. */
    const CACHE_PRESENCE = 'presencium::presence::';
    const CACHE_INSTANTANE = 'presencium::instantane::';
    const CACHE_FOYER = 'presencium::foyer::';
    const CACHE_ATTENTE = 'presencium::attente::';
    const CACHE_REPOS = 'presencium::repos::';
    const CACHE_TEMPOREL = 'presencium::temporel::';
    const CACHE_JOURNAL_ECHEC = 'presencium::journalEchec::';
    const CACHE_PREMIERE_VUE = 'presencium::premiereVue::';
    /* Secours de data/etat-<id>.json, posé seulement quand le fichier ne
     * s'écrit pas : voir etatModifier(). */
    const CACHE_ETAT = 'presencium::etat::';
    /* Sans identifiant d'équipement : c'est le battement du plugin entier, la
     * preuve que le cron du coeur passe encore. */
    const CACHE_CRON = 'presencium::cron';
    /* Au-delà, une balise qui se dit présente est tenue pour suspecte. Les
     * Tile de cette installation battent toutes les cinq minutes tant
     * qu'elles sont vues : deux heures de silence ne sont pas un hasard. */
    const SILENCE_MAX_DEFAUT = 120;

    /* Un mois. Assez long pour qu'un foyer vide pendant les vacances garde la
     * date de son dernier départ, assez court pour que rien ne s'accumule
     * indéfiniment dans la table cache. */
    const CACHE_DUREE = 2592000;

    /*
     * Au-delà, une absence est tenue pour une vraie sortie ; en deçà, pour un
     * décrochage de la balise. C'est le seuil que l'analyse emploie déjà pour
     * séparer les deux familles, et celui qui permet au journal de dire qu'un
     * départ confirmé était probablement faux. Une heure : on ne rentre pas
     * d'une vraie sortie en moins de temps que ça, et aucun décrochage mesuré
     * ici n'a duré aussi longtemps — entre les deux, il y a de la place.
     */
    const SEUIL_VRAI_DEFAUT = 60;

    const JOURNAL_TAILLE_DEFAUT = 1000;
    const JOURNAL_TAILLE_MIN = 10;
    const JOURNAL_TAILLE_MAX = 5000;

    /*
     * Profondeur d'appel de rafraichirFoyer() dans ce processus : la garde de
     * réentrance, dont rafraichirFoyer() explique le scénario.
     *
     * Le souligné initial n'est pas une convention d'écriture. DB::getFields()
     * relève par réflexion TOUTES les propriétés de la classe — statiques
     * comprises, `getProperties()` ne les distingue pas — et tient pour une
     * colonne de la table eqLogic chacune dont le nom n'en commence pas par un.
     * Sans lui, le moindre enregistrement d'un équipement du plugin échouerait
     * sur « Unknown column », et le journal du plugin resterait muet.
     */
    private static $_profondeurFoyer = 0;

    /* Posé par un processus qui trouve le verrou du foyer tenu : le détenteur
     * refait alors une passe avant de relâcher (voir rafraichirFoyer()). */
    const CACHE_REFAIRE = 'presencium::refaire::';
    /* La suite d'actions d'une règle confiée à un processus séparé. */
    const CACHE_SUITE = 'presencium::suite::';

    /* Actions de scénario qui ne sont pas des commandes : relevées dans
     * scenarioExpression::execute() du cœur. */
    const MOTS_CLES_ACTION = array('icon', 'wait', 'sleep', 'stop', 'log', 'event', 'message',
        'alert', 'popup', 'setColoredIcon', 'equipment', 'equipement', 'gotodesign', 'changeTheme',
        'scenario', 'variable', 'genericType', 'delete_variable', 'ask', 'jeedom_poweroff',
        'jeedom_reboot', 'scenario_return', 'remove_inat', 'exportHistory', 'report', 'tag');
    /* Celles qui bloquent le processus : jamais dans le cron partagé. */
    const ACTIONS_BLOQUANTES = array('wait', 'sleep', 'ask');
    /* Celles qu'un essai ne joue pas : elles bloqueraient la page, ou
     * arrêteraient la machine. */
    const ACTIONS_HORS_ESSAI = array('wait', 'sleep', 'ask', 'jeedom_poweroff', 'jeedom_reboot');

    /* Verdicts déjà calculés pendant ce passage, par « id@instant » : un
     * passage de foyer en demandait 1 + F×M pour les mêmes personnes. Vidé à
     * chaque passage du cron ou de l'écouteur, et à chaque changement de
     * configuration d'une personne. */
    private static $_memoVerdicts = array();
    /* Personnes dont ce processus tient déjà le verrou : flock() ne se reprend
     * pas dans un même processus. */
    private static $_verrousPersonne = array();

    /* Vide le mémo des verdicts, pour une personne ou pour toutes. */
    public static function oublierVerdicts($_id = null) {
        if ($_id === null) {
            self::$_memoVerdicts = array();
            return;
        }
        $prefixe = (int) $_id . '@';
        foreach (array_keys(self::$_memoVerdicts) as $cle) {
            if (strpos($cle, $prefixe) === 0) {
                unset(self::$_memoVerdicts[$cle]);
            }
        }
    }

    /* Personne → foyers, mémorisé pour ce processus : voir foyersDe(). Même
     * règle du souligné que ci-dessus. */
    private static $_foyersParPersonne = null;
    private static $_foyersParPersonneDate = 0;

    /* ==================================================================== CRON */

    /*
     * Chaque minute — et ce n'est pas redondant avec le listener.
     *
     * Le listener réagit au changement du signal brut : il est rapide, mais il
     * ne sait parler que de l'instant où la balise change d'avis. Or la moitié
     * des décisions de ce plugin ne dépendent pas d'un changement, mais du
     * TEMPS QUI PASSE :
     *
     *  - le délai de confirmation de départ : la balise s'est tue il y a 15
     *    minutes et plus rien ne se produira jamais ; sans le cron, la personne
     *    resterait « départ en cours » pour toujours ;
     *  - l'attente d'une règle (« arme 5 minutes après le départ ») ;
     *  - les déclencheurs `vide_depuis` et `occupee_depuis`, qui sont des
     *    durées et non des événements ;
     *  - et le rattrapage d'un listener perdu — processus tué, base occupée,
     *    écouteur effacé par listener::clean() — dont rien d'autre ne
     *    s'apercevrait.
     *
     * Le listener décide vite, le cron décide sûrement. Le coût d'un passage à
     * vide est de quelques lectures de cache.
     *
     * Les personnes d'abord, les foyers ensuite : un foyer lit la présence
     * stabilisée de ses membres, et la lirait d'un cran en retard si l'ordre
     * était inversé — l'arrivée serait vue une minute trop tard, chaque fois.
     */
    public static function cron() {
        $maintenant = time();
        self::oublierVerdicts();
        $equipements = self::byType(__CLASS__, true);

        foreach ($equipements as $eqLogic) {
            if ($eqLogic->type() !== self::TYPE_PERSONNE) {
                continue;
            }
            try {
                $eqLogic->rafraichirPersonne($maintenant);
            } catch (Throwable $e) {
                /* Un équipement en échec ne prive pas les autres : une source
                 * supprimée sur une personne ne doit pas empêcher le foyer
                 * d'armer l'alarme. */
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }

        foreach ($equipements as $eqLogic) {
            if ($eqLogic->type() !== self::TYPE_FOYER) {
                continue;
            }
            try {
                $eqLogic->rafraichirFoyer($maintenant);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }

        /*
         * La marque d'un passage COMPLET, posée en dernier.
         *
         * C'est la seule chose que le plugin sache de lui-même : si le cron du
         * coeur s'arrête — désactivé, saturé, bloqué par un autre plugin qui ne
         * rend pas la main — plus aucune décision ne se prend. Les délais de
         * départ n'expirent plus, les attentes ne se terminent plus, les
         * déclencheurs de durée ne tombent plus. Et rien ne change à l'écran :
         * les commandes gardent leur dernière valeur, qui a l'air juste. La
         * page Santé lit cet horodatage, et c'est le seul endroit d'où cette
         * panne-là puisse se voir.
         */
        cache::set(self::CACHE_CRON, $maintenant, self::CACHE_DUREE);
    }

    /*
     * Une fois par jour : le journal.
     *
     * Le journal s'écrit par ajout en fin de fichier, sans jamais être relu :
     * c'est ici qu'il est ramené à `journal_taille` entrées (journalAjouter()
     * ne retaille qu'un fichier devenu deux fois trop long). Au passage, les
     * fichiers d'équipements supprimés à une époque où le plugin ne les
     * nettoyait pas s'en vont.
     */
    public static function cronDaily() {
        self::checkSimulation();

        $vivants = array();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $vivants[(int) $eqLogic->getId()] = true;
            try {
                /* Les deux familles tiennent un journal : une personne hors de
                 * tout foyer y garde ses rebonds absorbés, et c'est le seul
                 * endroit où ils se lisent. */
                $eqLogic->journalTronquer();
                if ($eqLogic->type() === self::TYPE_FOYER) {
                    $eqLogic->etatElaguer();
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }

        /* Les familles de fichiers que le plugin pose dans data/ :
         * `journal-<id>.jsonl` avec son verrou, `etat-<id>.json` avec le sien,
         * et `foyer-<id>.lock`, le verrou qui sérialise l'évaluation d'un foyer.
         * Le nom est confronté à un motif plutôt qu'à un préfixe : un fichier
         * étranger posé dans data/ ne doit pas être effacé sous prétexte qu'il
         * commence par les bonnes lettres. */
        foreach (ls(self::dossierDonnees(), '*', false, array('files', 'quiet')) as $fichier) {
            if (!preg_match('/^(?:journal|foyer|etat)-(\d+)\./', $fichier, $morceaux)) {
                continue;
            }
            $chemin = self::dossierDonnees() . '/' . $fichier;

            /* Un processus tué entre l'écriture et le renommage laisse un
             * fichier temporaire derrière lui. Personne ne le relira jamais :
             * il ne ferait que grossir le dossier de données, année après
             * année, sans que rien ne le signale. */
            if (substr($fichier, -4) === '.tmp') {
                if (filemtime($chemin) < (time() - 86400)) {
                    @unlink($chemin);
                }
                continue;
            }

            $id = (int) $morceaux[1];
            if ($id > 0 && !isset($vivants[$id])) {
                @unlink($chemin);
            }
        }
    }

    /*
     * Chaque heure : les deux pannes qui ne disent pas leur nom.
     *
     * La page Santé les relève déjà, mais elle ne se lit que le jour où l'on
     * soupçonne quelque chose — et ces deux-là ne donnent envie de soupçonner
     * personne : tout continue de fonctionner, les états sont publiés, les
     * règles tournent. Une balise dont la pile est morte laisse simplement la
     * maison « occupée » pour toujours, et l'alarme ne s'arme plus, sans un
     * mot. On les écrit donc là où elles seront lues sans être cherchées.
     *
     * À l'heure et non à la minute : le seuil de silence se compte en heures
     * (deux par défaut), et un message réécrit soixante fois par heure serait
     * soixante écritures en base pour la même phrase.
     *
     * Les équipements désactivés passent ici aussi, exprès : c'est ce qui
     * retire le message de quelqu'un qu'on vient justement de désactiver.
     */
    public static function cronHourly() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->type() !== self::TYPE_PERSONNE) {
                continue;
            }
            try {
                $eqLogic->signalerSilences();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Pose ou retire l'avertissement de simulation globale.
     *
     * Un plugin en simulation n'exécute rien tout en ayant l'air de fonctionner
     * parfaitement : les commandes bougent, les journaux se remplissent, et
     * l'alarme ne s'arme jamais. C'est le diagnostic le plus coûteux du plugin,
     * et le seul moyen de l'éviter est de l'écrire là où on le lira sans le
     * chercher.
     *
     * Le message est retiré dès que la simulation s'arrête : sans cela, celui
     * qui en sort garderait l'avertissement sous les yeux indéfiniment et
     * finirait par le fermer à la main — ce qui le ferait disparaître même le
     * jour où il redeviendrait vrai.
     */
    public static function checkSimulation() {
        if ((int) self::reglageGlobal('simulation', 0) !== 1) {
            message::removeAll(__CLASS__, 'presencium::simulation');
            return;
        }
        message::add(__CLASS__,
            __('Le mode simulation global est actif : les règles sont évaluées et journalisées, mais aucune action n\'est exécutée.', __FILE__),
            '', 'presencium::simulation');
    }

    /* Appelée par config::save() du cœur quand la case de simulation globale
     * change : l'avertissement suit tout de suite, sans attendre cronDaily(). */
    public static function postConfig_simulation($_value) {
        try {
            self::checkSimulation();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $e->getMessage());
        }
    }

    /* ================================================================ LISTENER */

    /*
     * Rappel de l'écouteur du cœur, exécuté dans un PROCESSUS SÉPARÉ
     * (core/php/jeeListener.php) avec
     * array('id' => eqLogic, 'event_id' => cmd, 'value' => …, 'datetime' => …,
     * 'listener_id' => …).
     *
     * Deux propriétés du cœur commandent tout ce qui suit :
     *
     * 1. listener::check() n'est appelé par cmd::event() que dans la branche
     *    `if (!$repeat)`, c'est-à-dire sur un changement RÉEL de valeur. Une
     *    balise qui répète « présent » toutes les dix secondes ne réveille donc
     *    rien, et il est inutile de filtrer les répétitions ici.
     * 2. Le processus est séparé : ce qui est levé ici ne remonte à personne.
     *    Le cœur l'attrape dans un catch (Exception) qui ne rattrape pas les
     *    Error de PHP 8, et le message part dans listener_execution, un journal
     *    que personne ne lit. D'où le try/catch (Throwable) global : le rappel
     *    ne lève jamais, il journalise.
     */
    public static function onSource($_option) {
        try {
            $id = (is_array($_option) && isset($_option['id'])) ? (int) $_option['id'] : 0;
            $eqLogic = ($id > 0) ? eqLogic::byId($id) : null;

            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) {
                /* La personne a été supprimée sans que son écouteur le soit :
                 * on le retire plutôt que d'échouer à chaque battement de la
                 * balise, indéfiniment. */
                $listener = listener::byClassAndFunction(__CLASS__, 'onSource', array('id' => $id));
                if (is_object($listener)) {
                    $listener->remove();
                }
                return;
            }
            if ($eqLogic->getIsEnable() != 1) {
                return;
            }

            $maintenant = time();
            self::oublierVerdicts();
            $eqLogic->rafraichirPersonne($maintenant);

            /* Les foyers dans la foulée : c'est ce qui rend l'arrivée immédiate.
             * Sans cela, la présence de la personne serait juste tout de suite
             * mais les règles n'apprendraient l'arrivée qu'au prochain cron. */
            self::rafraichirFoyersDe($id, $maintenant);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', __('Écouteur :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /*
     * Pose ou retire l'écouteur de cette personne. Idempotent : appelé à chaque
     * enregistrement et par presencium_update().
     *
     * L'option array('id' => …) sert de clé d'identité : byClassAndFunction la
     * compare au JSON stocké en base, ce qui suppose qu'elle soit écrite
     * exactement de la même façon des deux côtés — d'où le cast en entier ici
     * comme là-bas.
     */
    public function syncListener() {
        $option = array('id' => (int) $this->getId());
        $listener = listener::byClassAndFunction(__CLASS__, 'onSource', $option);
        $source = (int) $this->getConfiguration('source', 0);

        $utile = ($this->type() === self::TYPE_PERSONNE)
              && ($this->getIsEnable() == 1)
              && ($source > 0)
              && is_object(cmd::byId($source));

        if (!$utile) {
            /* Un écouteur qui pointe une commande disparue survit aux passages
             * de listener::clean() tant qu'il lui reste un événement valide :
             * on le retire explicitement plutôt que d'attendre. */
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }

        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('onSource');
            $listener->setOption($option);
        }
        /* emptyEvent() avant addEvent() : sans cela, changer la source d'une
         * personne empilerait l'ancienne et la nouvelle, et la personne
         * réagirait encore à la balise de quelqu'un d'autre. */
        $listener->emptyEvent();
        $listener->addEvent($source);
        $listener->save();
    }

    public function removeListener() {
        $listener = listener::byClassAndFunction(__CLASS__, 'onSource', array('id' => (int) $this->getId()));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    /* Rafraîchit chaque foyer de cette personne ; un foyer en échec ne prive
     * pas les suivants. */
    public static function rafraichirFoyersDe($_idPersonne, $_maintenant) {
        foreach (self::foyersDe((int) $_idPersonne) as $foyer) {
            try {
                $foyer->rafraichirFoyer($_maintenant);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $foyer->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    /*
     * Normalise, borne, ne refuse rien.
     *
     * Aucune exception ne sort d'ici, jamais — et le try/catch qui enveloppe
     * tout le corps est là pour le garantir même si une classe auxiliaire lève.
     * Le cœur crée l'équipement avec son seul nom, avant que l'utilisateur ait
     * pu choisir quoi que ce soit : une validation stricte rendrait le bouton
     * « Ajouter » définitivement inopérant, sans message exploitable. Un
     * équipement incomplet se signale dans l'interface et dans la page Santé,
     * pas en refusant d'exister.
     */
    public function preSave() {
        try {
            $this->normaliserType();

            if ($this->getId() == '') {
                $this->setIsEnable(1);
                $this->setIsVisible(1);
            }
            if ($this->getDisplay('width') == '') {
                $this->setDisplay('width', '300px');
            }

            if ($this->type() === self::TYPE_PERSONNE) {
                $this->normaliserPersonne();
            } else {
                $this->normaliserFoyer();
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                   . __('normalisation impossible,', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /*
     * Le type est la seule donnée dont dépend TOUT le reste : les commandes
     * créées, les onglets affichés, le travail du cron. Un type perdu
     * transformerait un foyer en personne, effacerait ses règles à
     * l'enregistrement suivant et laisserait l'utilisateur devant un formulaire
     * qui ne parle plus de son équipement.
     *
     * D'où le repli sur les commandes : `occupation` n'existe que sur un foyer.
     * Si le champ caché du formulaire arrive vide sur un équipement qui en a
     * une, c'est un foyer, quoi qu'en dise le formulaire.
     */
    private function normaliserType() {
        $type = $this->getConfiguration('type');
        if ($type === self::TYPE_PERSONNE || $type === self::TYPE_FOYER) {
            return;
        }
        if ($this->getId() != '' && is_object($this->getCmd(null, 'occupation'))) {
            $this->setConfiguration('type', self::TYPE_FOYER);
            return;
        }
        $this->setConfiguration('type', self::TYPE_PERSONNE);
    }

    private function normaliserPersonne() {
        /* Les valeurs proposées viennent de la configuration du plugin, et
         * seulement à la création : les réécrire à chaque enregistrement
         * remplacerait le réglage que l'utilisateur vient de faire par le
         * défaut global, sous ses yeux. */
        if ($this->getConfiguration('delai_depart', '') === '') {
            $this->setConfiguration('delai_depart', self::reglageGlobal('delai_depart', presenciumPersonne::DELAI_DEPART_DEFAUT));
        }
        if ($this->getConfiguration('delai_arrivee', '') === '') {
            $this->setConfiguration('delai_arrivee', self::reglageGlobal('delai_arrivee', presenciumPersonne::DELAI_ARRIVEE_DEFAUT));
        }

        /*
         * La normalisation est déléguée à presenciumPersonne, qui ne connaît
         * pas Jeedom : le même code borne les délais ici et dans le jeu
         * d'essai hors ligne, donc ce qui est démontré là est vrai ici.
         *
         * Seules les clés effectivement rendues sont réécrites : une classe
         * auxiliaire qui en omettrait une ne doit pas effacer le réglage de
         * l'utilisateur au passage.
         */
        $reglages = presenciumPersonne::normaliserReglages($this->getConfiguration());
        if (is_array($reglages)) {
            foreach (array('source', 'valeur_presente', 'delai_arrivee', 'delai_depart') as $cle) {
                if (array_key_exists($cle, $reglages)) {
                    $this->setConfiguration($cle, $reglages[$cle]);
                }
            }
        }

        /* Le mode forcé n'est pas un champ du formulaire : il vient des boutons
         * « Forcer présent » / « Forcer absent ». On le borne quand même, parce
         * qu'une copie d'équipement recopie la configuration telle quelle. */
        $this->setConfiguration('mode', self::modeValide($this->getConfiguration('mode', 'auto')));
    }

    private function normaliserFoyer() {
        /* Une liste d'identifiants, sans doublon et sans zéro : la même
         * personne deux fois dans un foyer fausserait l'occupation, et
         * « tout le monde est là » ne serait jamais vrai. */
        $personnes = $this->getConfiguration('personnes');
        $propres = array();
        foreach (is_array($personnes) ? $personnes : array() as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $propres)) {
                $propres[] = $id;
            }
        }
        $this->setConfiguration('personnes', $propres);
        $this->setConfiguration('simulation', ($this->getConfiguration('simulation', 0) == 1) ? 1 : 0);

        $regles = presenciumRegles::normaliser($this->getConfiguration('regles'));
        if (!is_array($regles)) {
            $regles = array();
        }
        /*
         * L'identifiant de commande de chaque action est résolu ICI, à
         * l'enregistrement, et jamais à l'exécution : le nom lisible
         * « #[Objet][Équipement][Commande]# » est ce qu'attendent
         * jeedom.cmd.displayActionsOption et scenarioExpression, mais il devient
         * faux dès qu'on renomme l'objet — et une action qui ne part plus ne se
         * voit nulle part. L'identifiant gardé à côté permet à health() de dire
         * « cette règle pointe une commande qui n'existe plus ».
         */
        foreach ($regles as $index => $regle) {
            if (!isset($regle['actions']) || !is_array($regle['actions'])) {
                continue;
            }
            foreach ($regle['actions'] as $rang => $action) {
                $regles[$index]['actions'][$rang] = self::resoudreAction($action);
            }
        }
        $this->setConfiguration('regles', $regles);
    }

    public function postSave() {
        /* La composition d'un foyer vient peut-être de changer. */
        self::oublierFoyers();
        /* Une commande qui refuse de s'enregistrer ne doit pas priver la
         * personne de son écouteur. */
        try {
            $this->createCommands();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }

        try {
            $this->syncListener();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }

        /* Rafraîchir tout de suite : sinon l'utilisateur enregistre et voit des
         * commandes vides jusqu'à la minute suivante, ce qui ressemble
         * exactement à un plugin qui ne marche pas. */
        try {
            if ($this->type() === self::TYPE_PERSONNE) {
                self::oublierVerdicts((int) $this->getId());
                $maintenant = time();
                $this->rafraichirPersonne($maintenant);
                self::rafraichirFoyersDe((int) $this->getId(), $maintenant);
            } else {
                $this->rafraichirFoyer(time());
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* DB::remove() met l'identifiant à null avant postRemove : le nettoyage se
     * fait ici, tant qu'il est encore lisible. */
    public function preRemove() {
        self::oublierFoyers();
        try {
            $this->removeListener();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Retrait de l\'écouteur impossible :', __FILE__) . ' ' . $e->getMessage());
        }
        try {
            /* Un message survit à l'équipement qui l'a fait naître : il resterait
             * dans le centre de messages à réclamer la réparation d'une balise
             * qui n'est plus suivie par personne, sans qu'aucun écran ne permette
             * d'en retrouver l'origine. */
            foreach ($this->clesMessage() as $cle) {
                message::removeAll(__CLASS__, $cle);
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Messages non retirés :', __FILE__) . ' ' . $e->getMessage());
        }
        try {
            $this->purgerCache();
            $this->journalVider();
            $this->etatVider();
            /* Les verrous sont supprimés ici et nulle part ailleurs : effacer
             * le fichier d'un verrou qu'un autre processus tient encore lui
             * laisserait sa poignée tout en permettant à un troisième d'en
             * créer une autre — deux verrous distincts sur le même foyer,
             * c'est-à-dire plus de verrou du tout. Un équipement qu'on
             * supprime, lui, n'a plus personne pour l'évaluer. */
            foreach (array($this->cheminVerrouFoyer(), $this->cheminJournal() . '.lock',
                           $this->cheminEtat() . '.lock') as $verrou) {
                if (file_exists($verrou)) {
                    @unlink($verrou);
                }
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Nettoyage impossible :', __FILE__) . ' ' . $e->getMessage());
        }
        return true;
    }

    /* Efface tout ce que cet équipement a laissé en cache. Les attentes et les
     * repos portent l'identifiant de la règle : on repart de la configuration
     * pour les retrouver, faute de pouvoir interroger le cache par préfixe.
     * Les clés d'instantané, d'attente, de repos et d'épisode ne sont plus
     * écrites (voir etatModifier()) : on les efface pour les installations
     * qui n'ont pas encore migré. */
    public function purgerCache() {
        $id = (int) $this->getId();
        foreach (array(self::CACHE_PRESENCE, self::CACHE_INSTANTANE, self::CACHE_FOYER, self::CACHE_ETAT,
                       self::CACHE_PREMIERE_VUE, self::CACHE_JOURNAL_ECHEC, self::CACHE_REFAIRE) as $prefixe) {
            cache::delete($prefixe . $id);
        }
        foreach ($this->regles() as $regle) {
            cache::delete(self::CACHE_ATTENTE . $id . '::' . $regle['id']);
            cache::delete(self::CACHE_REPOS . $id . '::' . $regle['id']);
            cache::delete(self::CACHE_TEMPOREL . $id . '::' . $regle['id']);
        }
    }

    /* ============================================================== COMMANDES */

    /*
     * Publique : appelée par postSave() et par presencium_update(), qui la
     * rejoue sur les équipements existants pour qu'une commande ajoutée par une
     * mise à jour n'apparaisse pas seulement sur les équipements créés après.
     *
     * Tout est posé à la CRÉATION seulement : ordre, types, nom, visibilité.
     * Une commande existante appartient à l'utilisateur, qui a pu la
     * réordonner ou lui donner un autre type générique ; la réécrire à chaque
     * enregistrement défaisait ces choix, et coûtait un save() par commande.
     */
    public function createCommands() {
        $definitions = ($this->type() === self::TYPE_FOYER)
                     ? $this->definitionsFoyer()
                     : $this->definitionsPersonne();

        $order = 0;
        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            if (!is_object($cmd)) {
                $cmd = new presenciumCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if (isset($definition['icon']) && $definition['icon'] != '') {
                    $cmd->setDisplay('icon', '<i class="' . $definition['icon'] . '"></i>');
                }
                if (isset($definition['unite'])) {
                    $cmd->setUnite($definition['unite']);
                }
                $cmd->setType($definition['type']);
                $cmd->setSubType($definition['subType']);
                $cmd->setGeneric_type($definition['generic']);
                $cmd->setOrder($order);
                $cmd->save();
            } elseif ($cmd->getType() != $definition['type'] || $cmd->getSubType() != $definition['subType']) {
                /* Le type et le sous-type ne sont pas des préférences : le
                 * moteur écrit et lit ces commandes en supposant les siens. */
                $cmd->setType($definition['type']);
                $cmd->setSubType($definition['subType']);
                $cmd->save();
            }
            $order++;
        }

        /*
         * Une commande d'action liée à l'information qu'elle change : c'est ce
         * lien que le cœur suit pour rafraîchir la tuile immédiatement après un
         * clic. Sans lui, « Armer » laisse le widget afficher « désarmée »
         * jusqu'au passage suivant du cron, et l'utilisateur appuie deux fois.
         */
        $liens = ($this->type() === self::TYPE_FOYER)
               ? array('armer' => 'armee', 'desarmer' => 'armee',
                       'activer' => 'en_service', 'desactiver' => 'en_service',
                       'simulation_on' => 'simulation', 'simulation_off' => 'simulation')
               : array('forcer_present' => 'presence', 'forcer_absent' => 'presence');

        foreach ($liens as $action => $info) {
            $cmdAction = $this->getCmd('action', $action);
            $cmdInfo = $this->getCmd('info', $info);
            if (is_object($cmdAction) && is_object($cmdInfo) && $cmdAction->getValue() != $cmdInfo->getId()) {
                $cmdAction->setValue($cmdInfo->getId());
                $cmdAction->save();
            }
        }
    }

    private function definitionsPersonne() {
        return array(
            array('logicalId' => 'presence', 'name' => __('Présence', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'PRESENCE',
                  'visible' => 1, 'historized' => 1, 'icon' => 'fas fa-user'),
            /* Le signal brut est historisé et masqué : c'est lui qu'on relit
             * pour compter les rebonds d'une balise, et c'est exactement ce
             * qu'on ne veut pas voir sur le tableau de bord. */
            array('logicalId' => 'brut', 'name' => __('Signal brut', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'PRESENCE',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'etat', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'depuis', 'name' => __('Depuis (min)', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => '', 'unite' => __('min', __FILE__)),
            /* La fraîcheur du signal, et non la présence : une balise dont la
             * pile meurt pendant que la personne est chez elle fige le signal
             * sur « présent ». La présence ne bouge alors plus jamais, le foyer
             * ne devient plus jamais vide, et l'alarme ne peut plus s'armer.
             * Rien d'autre dans le plugin ne regarde la date de COLLECTE. */
            array('logicalId' => 'vu_depuis', 'name' => __('Vu il y a (min)', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => '', 'unite' => __('min', __FILE__)),
            array('logicalId' => 'mode', 'name' => __('Mode', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'forcer_present', 'name' => __('Forcer présent', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'forcer_absent', 'name' => __('Forcer absent', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'auto', 'name' => __('Suivi automatique', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
        );
    }

    private function definitionsFoyer() {
        return array(
            array('logicalId' => 'presence', 'name' => __('Présence', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'PRESENCE',
                  'visible' => 1, 'historized' => 1, 'icon' => 'fas fa-home'),
            array('logicalId' => 'tous', 'name' => __('Tout le monde est là', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'occupation', 'name' => __('Occupation', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 1, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'qui', 'name' => __('Qui est là', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'etat', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'vide_depuis', 'name' => __('Vide depuis (min)', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => '', 'unite' => __('min', __FILE__)),
            array('logicalId' => 'occupee_depuis', 'name' => __('Occupée depuis (min)', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => '', 'unite' => __('min', __FILE__)),
            array('logicalId' => 'premier', 'name' => __('Premier arrivé', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'dernier', 'name' => __('Dernier parti', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'historized' => 0, 'icon' => ''),
            /*
             * L'alarme est portée par le plugin lui-même : le cœur n'en a plus
             * depuis la v4, et il n'existe donc plus rien à qui déléguer
             * « en service » et « armée ». Les types génériques restent ceux du
             * cœur pour que les widgets d'alarme, les scénarios et les
             * assistants vocaux les reconnaissent.
             */
            array('logicalId' => 'en_service', 'name' => __('Alarme en service', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'ALARM_ENABLE_STATE',
                  'visible' => 1, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'armee', 'name' => __('Alarme armée', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'ALARM_STATE',
                  'visible' => 1, 'historized' => 1, 'icon' => ''),
            /* Visible, et ce n'est pas un détail : un foyer en simulation
             * n'exécute rien. Si l'information n'était pas sous les yeux, la
             * seule explication disponible d'une alarme qui ne s'arme plus
             * serait « le plugin est cassé ». */
            array('logicalId' => 'simulation', 'name' => __('Mode simulation', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '',
                  'visible' => 1, 'historized' => 0, 'icon' => ''),
            array('logicalId' => 'activer', 'name' => __('Mettre en service', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'desactiver', 'name' => __('Mettre hors service', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'armer', 'name' => __('Armer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'ALARM_ARMED',
                  'visible' => 1, 'icon' => 'fas fa-lock'),
            array('logicalId' => 'desarmer', 'name' => __('Désarmer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'ALARM_RELEASED',
                  'visible' => 1, 'icon' => 'fas fa-unlock'),
            array('logicalId' => 'simulation_on', 'name' => __('Activer la simulation', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'simulation_off', 'name' => __('Arrêter la simulation', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'evaluer', 'name' => __('Réévaluer maintenant', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
        );
    }

    /* ================================================================= OUTILS */

    /* Le type de cet équipement, toujours l'une des deux constantes : un
     * getConfiguration('type') nu rendrait '' sur un équipement créé avant que
     * le champ existe, et chaque appelant devrait s'en méfier. */
    public function type() {
        return ($this->getConfiguration('type') === self::TYPE_FOYER) ? self::TYPE_FOYER : self::TYPE_PERSONNE;
    }

    /* Un réglage de la configuration du plugin, avec son défaut. Le fichier
     * core/config/presencium.config.ini les pose à l'installation ; ce défaut-ci
     * est le filet pour une installation mise à jour depuis une version qui ne
     * connaissait pas la clé. */
    public static function reglageGlobal($_cle, $_defaut) {
        $valeur = config::byKey($_cle, __CLASS__, $_defaut);
        return ($valeur === '' || $valeur === null) ? $_defaut : $valeur;
    }

    public static function modeValide($_mode) {
        return in_array($_mode, array('present', 'absent'), true) ? $_mode : 'auto';
    }

    public static function libelleMode($_mode) {
        switch ($_mode) {
            case 'present': return __('Forcé présent', __FILE__);
            case 'absent':  return __('Forcé absent', __FILE__);
        }
        return __('Automatique', __FILE__);
    }

    /* « 2 s », « 7 min », « 1 h 20 » : ce qu'on lit dans un journal, et non un
     * nombre de secondes qu'il faudrait diviser de tête. */
    public static function duree($_secondes) {
        $secondes = max(0, (int) $_secondes);
        if ($secondes < 60) {
            return $secondes . ' ' . __('s', __FILE__);
        }
        if ($secondes < 3600) {
            return ((int) round($secondes / 60)) . ' ' . __('min', __FILE__);
        }
        $heures = (int) floor($secondes / 3600);
        $minutes = (int) floor(($secondes % 3600) / 60);
        return $heures . ' ' . __('h', __FILE__) . ' ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le cœur refuse d'enregistrer un équipement du plugin.
 */
class presenciumCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        switch ($this->getLogicalId()) {
            case 'forcer_present':
                $eqLogic->forcerPersonne('present');
                return;
            case 'forcer_absent':
                $eqLogic->forcerPersonne('absent');
                return;
            case 'auto':
                $eqLogic->forcerPersonne('auto');
                return;
            case 'activer':
                $eqLogic->mettreEnService(true);
                return;
            case 'desactiver':
                $eqLogic->mettreEnService(false);
                return;
            case 'armer':
                $eqLogic->armer(true);
                return;
            case 'desarmer':
                $eqLogic->armer(false);
                return;
            case 'simulation_on':
                $eqLogic->basculerSimulation(true);
                return;
            case 'simulation_off':
                /* Jamais soumise à la simulation, sans quoi on ne pourrait plus
                 * en sortir depuis un scénario ni depuis le tableau de bord. */
                $eqLogic->basculerSimulation(false);
                return;
            case 'evaluer':
                $eqLogic->rafraichirFoyer(time());
                return;
        }
    }
}
