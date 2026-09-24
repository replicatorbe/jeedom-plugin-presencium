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
/* L'autoload du cœur ne connaît que la classe homonyme du plugin : sans ces
 * deux lignes, presenciumPersonne et presenciumRegles seraient introuvables
 * partout où la classe est chargée autrement que par la page (cron, listener,
 * jeeListener.php), c'est-à-dire précisément là où le plugin travaille. */
require_once __DIR__ . '/presenciumPersonne.class.php';
require_once __DIR__ . '/presenciumRegles.class.php';

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
 * configuration de l'équipement.
 */
class presencium extends eqLogic {

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
     * Le ring buffer est tronqué à l'écriture, mais si l'utilisateur baisse
     * `journal_taille` de 500 à 50, les fichiers déjà écrits garderaient 500
     * entrées jusqu'à la prochaine écriture — et un foyer sans règle n'écrit
     * jamais. On les retaille ici. Au passage, les journaux d'équipements
     * supprimés à une époque où le plugin ne les nettoyait pas s'en vont.
     */
    public static function cronDaily() {
        self::checkSimulation();

        $vivants = array();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $vivants[(int) $eqLogic->getId()] = true;
            try {
                /* Les deux familles tiennent un journal : une personne hors de
                 * tout foyer y garde ses rebonds absorbés, et c'est le seul
                 * endroit où ils se lisent. Ne retailler que les foyers
                 * laissait ces journaux-là à leur ancienne taille pour
                 * toujours après une baisse de `journal_taille`. */
                $eqLogic->journalTronquer();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }

        /* Les deux familles de fichiers que le plugin pose dans data/ :
         * `journal-<id>.json` avec son verrou, et `foyer-<id>.lock`, le verrou
         * qui sérialise l'évaluation d'un foyer. Le nom est confronté à un motif
         * plutôt qu'à un préfixe : un fichier étranger posé dans data/ ne doit
         * pas être effacé sous prétexte qu'il commence par les bonnes lettres. */
        foreach (ls(self::dossierDonnees(), '*', false, array('files', 'quiet')) as $fichier) {
            if (!preg_match('/^(?:journal|foyer)-(\d+)\./', $fichier, $morceaux)) {
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
            /* Les verrous sont supprimés ici et nulle part ailleurs : effacer
             * le fichier d'un verrou qu'un autre processus tient encore lui
             * laisserait sa poignée tout en permettant à un troisième d'en
             * créer une autre — deux verrous distincts sur le même foyer,
             * c'est-à-dire plus de verrou du tout. Un équipement qu'on
             * supprime, lui, n'a plus personne pour l'évaluer. */
            foreach (array($this->cheminVerrouFoyer(), $this->cheminJournal() . '.lock') as $verrou) {
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
     * pour les retrouver, faute de pouvoir interroger le cache par préfixe. */
    public function purgerCache() {
        $id = (int) $this->getId();
        foreach (array(self::CACHE_PRESENCE, self::CACHE_INSTANTANE, self::CACHE_FOYER,
                       self::CACHE_PREMIERE_VUE, self::CACHE_JOURNAL_ECHEC, self::CACHE_REFAIRE) as $prefixe) {
            cache::delete($prefixe . $id);
        }
        foreach ($this->regles() as $regle) {
            cache::delete(self::CACHE_ATTENTE . $id . '::' . $regle['id']);
            cache::delete(self::CACHE_REPOS . $id . '::' . $regle['id']);
            cache::delete(self::CACHE_TEMPOREL . $id . '::' . $regle['id']);
        }
    }

    /*
     * Les deux pannes muettes d'une personne, en un seul endroit.
     *
     * La page Santé et le centre de messages posent la même question : elle
     * n'a donc qu'une réponse, calculée ici. Écrite deux fois, elle finirait
     * par diverger, et le jour où la page Santé et le centre de messages ne
     * diront pas la même chose, c'est le plugin entier qu'on cessera de croire.
     *
     * Rend array('muette'  => minutes de silence ou 0,
     *            'bloquee' => secondes d'attente ou 0,
     *            'seuil_silence' => minutes, 'delai_depart' => minutes) —
     * les deux seuils partent avec le verdict parce que c'est eux, et non la
     * durée écoulée, que le centre de messages peut nommer sans mentir avec le
     * temps (voir signalerSilences()).
     *
     * Un équipement désactivé ne diagnostique rien : il n'est plus suivi, et le
     * signaler reviendrait à réclamer une réparation pour quelque chose qu'on
     * vient d'éteindre volontairement.
     */
    public function diagnostic($_maintenant = null, $_verdict = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $silenceMax = (int) self::reglageGlobal('silence_max', self::SILENCE_MAX_DEFAUT);
        $reglages = presenciumPersonne::normaliserReglages($this->getConfiguration());
        $rendu = array('muette' => 0, 'bloquee' => 0,
                       'seuil_silence' => $silenceMax,
                       'delai_depart'  => (int) $reglages['delai_depart']);
        if ($this->type() !== self::TYPE_PERSONNE || $this->getIsEnable() != 1) {
            return $rendu;
        }

        $verdict = is_array($_verdict) ? $_verdict : $this->verdictPersonne($maintenant);

        /*
         * La balise muette : elle se dit présente et n'émet plus. La pile est
         * morte pendant que la personne était chez elle, et le signal est resté
         * figé sur « présent ». La présence ne bouge alors plus jamais, le foyer
         * ne devient plus jamais vide, l'alarme ne peut plus s'armer — et rien
         * n'échoue nulle part. Seule la date de COLLECTE, qui cesse d'avancer,
         * le trahit.
         */
        $vu = isset($verdict['vu_depuis']) ? (int) $verdict['vu_depuis'] : 0;
        if ($silenceMax > 0 && $vu > $silenceMax && !empty($verdict['brut'])) {
            $rendu['muette'] = $vu;
        }

        /*
         * Le départ qui n'aboutit pas : une confirmation qui dure plus
         * longtemps que le délai qui la borne. C'est le symptôme d'une source
         * sans date de changement exploitable — le décompte repart à chaque
         * passage et n'expire jamais. Deux minutes de marge pour ne pas
         * confondre avec une confirmation qui court normalement.
         */
        $signal = isset($verdict['signal_depuis']) ? (int) $verdict['signal_depuis'] : 0;
        if (isset($verdict['raison']) && $verdict['raison'] === 'depart_en_cours' && $signal > 0
            && ($maintenant - $signal) > ((int) $reglages['delai_depart'] * 60 + 120)) {
            $rendu['bloquee'] = $maintenant - $signal;
        }
        return $rendu;
    }

    /* Les deux clés de message de cette personne. Regroupées ici parce qu'elles
     * sont posées d'un côté et retirées de trois autres — cronHourly(),
     * preRemove() et le retour à la normale : une clé écrite d'un côté et
     * nettoyée de l'autre finit toujours par laisser un message immortel. */
    public function clesMessage() {
        return array('muette'  => 'presencium::muette::' . (int) $this->getId(),
                     'bloquee' => 'presencium::bloquee::' . (int) $this->getId());
    }

    /*
     * Porte le diagnostic au centre de messages, ou l'en retire.
     *
     * Le message est posé sous une clé propre à l'équipement ET au motif :
     * message::add() remplace alors le précédent au lieu d'en empiler un par
     * heure, et le retrait ne touche que celui qui vient de cesser d'être vrai.
     *
     * Retiré dès que la balise reparle : un avertissement qui survit à sa cause
     * finit par être fermé à la main, et il disparaîtra alors aussi le jour où
     * il redeviendra vrai.
     *
     * LE TEXTE NOMME LE SEUIL, JAMAIS LA DURÉE ÉCOULÉE. Vérifié dans le coeur
     * (message::save, branche « logicalId » non vide) : quand la clé existe
     * déjà, seules la date et le nombre d'occurrences sont mises à jour, et le
     * TEXTE reste celui du premier passage. Un « depuis 2 h 05 » écrit cette
     * nuit-là annoncerait donc encore deux heures trois jours plus tard, dans
     * un message qui, lui, n'aurait pas cessé d'être vrai : le chiffre serait
     * faux et la conclusion qu'on en tire — « c'est récent » — exactement
     * l'inverse de la réalité. Le seuil, lui, ne bouge pas. Le chiffre exact
     * vit sur la commande « Vu il y a » et sur la page Santé, qui se
     * recalculent à chaque lecture.
     */
    public function signalerSilences($_maintenant = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $diagnostic = $this->diagnostic($maintenant);
        $cles = $this->clesMessage();

        if ($diagnostic['muette'] > 0) {
            message::add(__CLASS__, sprintf(
                __('%1$s : sa balise se dit présente mais n\'a plus rien émis depuis plus de %2$s. Tant que cela dure, la personne reste présente pour toujours, la maison ne devient jamais vide et l\'alarme ne peut plus s\'armer. Pile morte, balise hors de portée ou passerelle arrêtée — le plugin ne bascule jamais la présence de lui-même sur ce motif, à vous de trancher. La commande « Vu il y a » et la page Santé donnent le chiffre exact.', __FILE__),
                $this->getHumanName(), self::duree((int) $diagnostic['seuil_silence'] * 60)),
                '', $cles['muette']);
        } else {
            message::removeAll(__CLASS__, $cles['muette']);
        }

        if ($diagnostic['bloquee'] > 0) {
            message::add(__CLASS__, sprintf(
                __('%1$s attend sa confirmation de départ depuis plus longtemps que son délai (%2$s). Sa commande source ne porte sans doute pas de date de changement exploitable : le décompte repart à chaque passage du cron et n\'expire jamais. Tant que cela dure, la maison ne devient jamais vide et l\'alarme ne peut pas s\'armer. La page Santé donne la durée réelle.', __FILE__),
                $this->getHumanName(), self::duree((int) $diagnostic['delai_depart'] * 60)),
                '', $cles['bloquee']);
        } else {
            message::removeAll(__CLASS__, $cles['bloquee']);
        }

        return $diagnostic;
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

    /* ================================================================ PERSONNE */

    /*
     * Le verdict, sans publier.
     *
     * Rien n'est publié ici — ni commande, ni historique, ni journal : la
     * page, l'AJAX et health() l'appellent pour AFFICHER. rafraichirPersonne()
     * est la seule à publier. Seule écriture : la « première vue » en cache
     * (premiereVue()), quand la source ne porte aucune date.
     *
     * Mémorisé par passage : voir $_memoVerdicts.
     */
    public function verdictPersonne($_maintenant = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $cleMemo = (int) $this->getId() . '@' . $maintenant;
        if ((int) $this->getId() > 0 && isset(self::$_memoVerdicts[$cleMemo])) {
            return self::$_memoVerdicts[$cleMemo];
        }
        $verdict = $this->calculerVerdict($maintenant);
        if ((int) $this->getId() > 0) {
            self::$_memoVerdicts[$cleMemo] = $verdict;
        }
        return $verdict;
    }

    private function calculerVerdict($_maintenant) {
        $maintenant = (int) $_maintenant;
        $mode = self::modeValide($this->getConfiguration('mode', 'auto'));
        $reglages = presenciumPersonne::normaliserReglages($this->getConfiguration());
        if (!is_array($reglages)) {
            $reglages = array();
        }

        $verdict = array(
            'present' => false, 'brut' => false, 'transitoire' => false,
            'restant' => 0, 'raison' => 'absente',
        );
        $verdict['mode'] = $mode;
        $verdict['nom'] = $this->getName();

        /* Sans source configurée, la personne est absente et le dit : inventer
         * une présence serait pire, c'est ce qui empêche une alarme d'armer. */
        $idSource = (int) $this->getConfiguration('source', 0);
        if ($idSource <= 0) {
            $verdict['source'] = false;
            $verdict['valeur'] = null;
            $verdict['signal_depuis'] = 0;
            $verdict['vu_depuis'] = 0;
            $verdict['libelle'] = __('Sans source', __FILE__);
            $verdict['depuis'] = 0;
            return $this->appliquerMode($verdict, $mode);
        }

        try {
            $source = cmd::byId($idSource);
            if (!is_object($source)) {
                return $this->verdictSourcePerdue($verdict, $mode,
                    __('la commande source n\'existe plus', __FILE__));
            }
            /* Une commande d'action n'est jamais lue : cmd::execCmd()
             * l'EXÉCUTERAIT, à chaque minute, même en simulation. */
            if ($source->getType() !== 'info') {
                return $this->verdictSourcePerdue($verdict, $mode,
                    __('la commande source n\'est pas une commande d\'information', __FILE__));
            }

            $valeur = $source->execCmd();
            /*
             * Les dates sont lues dans le CACHE de la commande, et non par
             * getValueDate()/getCollectDate() : quand le cache n'en porte pas
             * (vidage, réparation), cmd::execCmd() les remplace par
             * « maintenant ». Le décompte repartirait alors de zéro à chaque
             * passage et un départ ne serait jamais confirmé. Une date absente
             * mène à premiereVue(), qui, elle, ne recule pas.
             *
             * valueDate ne bouge que sur un changement réel de valeur : c'est
             * l'instant où la balise a changé d'avis pour la dernière fois.
             */
            $dates = $source->getCache(array('valueDate', 'collectDate'), '');
            $date = (is_array($dates) && isset($dates['valueDate'])) ? (string) $dates['valueDate'] : '';
            $depuis = ($date === '') ? 0 : (int) strtotime($date);
            if ($depuis <= 0 || $depuis > $maintenant) {
                $depuis = $this->premiereVue($valeur, $maintenant);
            } else {
                $this->oublierPremiereVue();
            }

            /* La dernière présence stabilisée : un retour pendant un départ en
             * cours n'est pas une nouvelle arrivée (voir evaluer()). */
            $memoire = cache::byKey(self::CACHE_PRESENCE . $this->getId())->getValue(null);
            $precedent = (is_array($memoire) && isset($memoire['present']))
                       ? ((int) $memoire['present'] === 1) : null;

            $calcul = presenciumPersonne::evaluer($valeur, $depuis, $maintenant, $reglages, $precedent);
            if (is_array($calcul)) {
                $verdict = array_merge($verdict, $calcul);
            }
            $verdict['source'] = true;
            $verdict['valeur'] = $valeur;
            $verdict['signal_depuis'] = $depuis;
            /* La date de COLLECTE dit quand on a entendu la balise pour la
             * dernière fois : c'est elle, et non valueDate, qui trahit une pile
             * morte. Absente du cache : inconnue, donc 0. */
            $collecte = (is_array($dates) && isset($dates['collectDate'])) ? (string) $dates['collectDate'] : '';
            $collecte = ($collecte === '') ? 0 : (int) strtotime($collecte);
            $verdict['vu_depuis'] = ($collecte > 0 && $collecte <= $maintenant)
                                  ? (int) floor(($maintenant - $collecte) / 60) : 0;
        } catch (Throwable $e) {
            return $this->verdictSourcePerdue($verdict, $mode,
                __('lecture de la source impossible :', __FILE__) . ' ' . $e->getMessage());
        }

        return $this->appliquerMode($verdict, $mode);
    }

    /*
     * Source configurée mais illisible : disparue, devenue une action, ou en
     * erreur. La personne GARDE son dernier état stabilisé connu — la déclarer
     * absente armerait l'alarme sur une panne de configuration. Sans état
     * connu, elle est absente, comme sans source.
     */
    private function verdictSourcePerdue($_verdict, $_mode, $_raison) {
        $verdict = $_verdict;
        $memoire = cache::byKey(self::CACHE_PRESENCE . $this->getId())->getValue(null);
        $connu = (is_array($memoire) && isset($memoire['present']));
        $verdict['present'] = $connu ? ((int) $memoire['present'] === 1) : false;
        $verdict['brut'] = (is_array($memoire) && !empty($memoire['brut']));
        $verdict['transitoire'] = false;
        $verdict['restant'] = 0;
        $verdict['raison'] = 'source_perdue';
        $verdict['source'] = false;
        $verdict['source_perdue'] = true;
        $verdict['source_perdue_raison'] = (string) $_raison;
        $verdict['valeur'] = null;
        $verdict['signal_depuis'] = 0;
        $verdict['vu_depuis'] = 0;
        $verdict['depuis'] = 0;
        $verdict['libelle'] = sprintf($connu
                ? __('Source perdue — dernier état gardé : %s', __FILE__)
                : __('Source perdue — %s', __FILE__),
            $verdict['present'] ? __('présent', __FILE__) : __('absent', __FILE__));
        return $this->appliquerMode($verdict, $_mode);
    }

    /*
     * Le forçage passe APRÈS le calcul, et n'efface pas le signal brut : la
     * personne forcée absente pendant qu'elle est chez elle doit continuer à
     * montrer que sa balise, elle, répond — sinon le forçage ressemble à une
     * panne de balise, et on cherche du côté du matériel.
     *
     * Il est écrit en configuration et non en cache : un forçage effacé par un
     * vidage de cache réveillerait une alarme sur quelqu'un qui a justement
     * demandé qu'on l'oublie.
     */
    private function appliquerMode($_verdict, $_mode) {
        $verdict = $_verdict;
        if ($_mode === 'present' || $_mode === 'absent') {
            $verdict['present'] = ($_mode === 'present');
            $verdict['transitoire'] = false;
            $verdict['restant'] = 0;
            $verdict['raison'] = ($_mode === 'present') ? 'presente' : 'absente';
            /* Le libellé est recalculé : une personne sans source mais forcée
             * présente doit afficher « Présent », pas « Sans source » — c'est
             * justement le cas d'usage du forçage. */
            $verdict['libelle'] = '';
        }
        if (!isset($verdict['libelle']) || $verdict['libelle'] === '') {
            $verdict['libelle'] = presenciumPersonne::libelleEtat($verdict);
        }
        return $verdict;
    }

    /*
     * Publie l'état de la personne et journalise ses FRONTS.
     *
     * La mémoire en cache ne sert qu'à ça : savoir ce qui a changé depuis le
     * passage précédent. Elle n'entre jamais dans la décision — celle-ci se
     * refait entièrement à partir du signal brut — et un cache vide ne fait
     * donc perdre que la ligne de journal, pas la justesse.
     */
    public function rafraichirPersonne($_maintenant = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $id = (int) $this->getId();
        /*
         * Verrou par personne : le cron et l'écouteur publient la même
         * personne dans deux processus. Sans lui, les deux lisent la même
         * mémoire, voient le même front et l'écrivent deux fois au journal.
         * Ce passage est court : on attend son tour. Le fichier est celui de
         * cheminVerrouFoyer(), propre à l'identifiant de l'équipement, que
         * cronDaily() et preRemove() savent déjà nettoyer.
         */
        if ($id <= 0 || isset(self::$_verrousPersonne[$id])) {
            return $this->rafraichirPersonneSousVerrou($maintenant);
        }
        $verrou = self::verrouFichier($this->cheminVerrouFoyer(), true);
        self::$_verrousPersonne[$id] = true;
        try {
            return $this->rafraichirPersonneSousVerrou($maintenant);
        } finally {
            unset(self::$_verrousPersonne[$id]);
            self::libererVerrou($verrou);
        }
    }

    private function rafraichirPersonneSousVerrou($_maintenant) {
        $maintenant = (int) $_maintenant;
        /* Recalculé sous verrou, sans le mémo : la mémoire lue par le verdict
         * vient peut-être d'être réécrite par l'autre processus. */
        self::oublierVerdicts((int) $this->getId());
        $verdict = $this->verdictPersonne($maintenant);
        /* La même normalisation que celle qui a rendu le verdict, et jamais un
         * (int) sur la configuration brute : un champ vide vaut 15 minutes
         * pour la décision, et vaudrait zéro ici. */
        $reglages = presenciumPersonne::normaliserReglages($this->getConfiguration());

        $cle = self::CACHE_PRESENCE . $this->getId();
        $memoire = cache::byKey($cle)->getValue(null);
        if (!is_array($memoire)) {
            $memoire = array('present' => null, 'depuis' => $maintenant,
                             'brut' => null, 'brut_depuis' => $maintenant);
        }

        $present = $verdict['present'] ? 1 : 0;
        $brut = (!empty($verdict['brut'])) ? 1 : 0;

        /* Le front du signal brut, d'abord : c'est lui qui raconte les rebonds
         * de la balise, et c'est la moitié de l'intérêt du mode simulation. */
        if ($memoire['brut'] !== null && (int) $memoire['brut'] !== $brut) {
            if ($brut === 1 && $present === 1 && (int) $memoire['present'] === 1) {
                /*
                 * Le signal est revenu avant la fin du délai de départ : c'est
                 * un rebond, et il vient d'être absorbé. Sans cette ligne, un
                 * réglage trop court ne se verrait qu'à l'alarme qui s'arme sur
                 * quelqu'un assis dans son salon — c'est-à-dire trop tard.
                 */
                /*
                 * Le creux se mesure jusqu'à l'instant où le signal est
                 * REVENU — signal_depuis, la date que le coeur porte sur la
                 * commande — et non jusqu'à maintenant, qui n'est que l'instant
                 * où le plugin s'en aperçoit. L'écart vaut le retard du cron ou
                 * de l'écouteur, jusqu'à une minute pleine sur un creux qui en
                 * fait parfois deux. C'est le chiffre qu'on relit pour choisir
                 * le délai de départ : surestimé, il fait monter le délai plus
                 * haut que nécessaire, et chaque vrai départ est alors annoncé
                 * avec ce retard en plus.
                 *
                 * Le délai affiché à côté passe par la même normalisation que
                 * la décision : un champ vide vaut 15 et non 0, sinon la phrase
                 * annoncerait « absorbé par le délai de 0 min ».
                 */
                $signalDepuis = isset($verdict['signal_depuis']) ? (int) $verdict['signal_depuis'] : $maintenant;
                $creux = max(0, min($maintenant, $signalDepuis) - (int) $memoire['brut_depuis']);
                $this->journaliserPresence('rebond_absorbe', sprintf(
                    __('%1$s : signal perdu %2$s puis revenu — rebond absorbé par le délai de départ de %3$s min', __FILE__),
                    $this->getName(), self::duree($creux), (int) $reglages['delai_depart']));
            }
            $memoire['brut'] = $brut;
            $memoire['brut_depuis'] = $maintenant;
        } elseif ($memoire['brut'] === null) {
            $memoire['brut'] = $brut;
            $memoire['brut_depuis'] = isset($verdict['signal_depuis']) ? (int) $verdict['signal_depuis'] : $maintenant;
        }

        /* Le front de la présence stabilisée. L'instant du changement est
         * calculé, pas relevé : le délai a expiré à `signal_depuis + délai`,
         * pas au passage du cron qui s'en aperçoit — sinon « Depuis » serait
         * faux de toute la latence du cron après un redémarrage. */
        if ($memoire['present'] === null) {
            $memoire['present'] = $present;
            $memoire['depuis'] = $this->instantStabilisation($verdict, $maintenant);
        } elseif ((int) $memoire['present'] !== $present) {
            /* L'instant du changement PRÉCÉDENT, avant qu'il ne soit écrasé :
             * c'est lui qui donne la durée de l'état qui vient de s'achever. */
            $avantDepuis = (int) $memoire['depuis'];
            $memoire['present'] = $present;
            $memoire['depuis'] = $this->instantStabilisation($verdict, $maintenant);

            $detail = sprintf(
                ($present === 1) ? __('%s est arrivé(e)', __FILE__) : __('%s est parti(e)', __FILE__),
                $this->getName());
            /* Un départ obtenu par le bouton « Forcer absent » s'écrivait
             * exactement comme un vrai : relu des semaines plus tard, rien ne
             * permettait de distinguer une personne sortie d'une personne
             * déclarée sortie à la main — alors que le second peut avoir armé
             * l'alarme. */
            if (isset($verdict['mode']) && $verdict['mode'] !== 'auto') {
                $detail .= ' — ' . self::libelleMode($verdict['mode']);
            }
            $this->journaliserPresence(($present === 1) ? 'arrivee' : 'depart', $detail);

            if ($present === 1) {
                $this->signalerFauxDepart($verdict, $avantDepuis, $maintenant, $reglages);
            }
        }

        /* Source perdue : dit une fois à la perte, une fois au retour. */
        $perdue = !empty($verdict['source_perdue']) ? 1 : 0;
        if ($perdue !== (empty($memoire['source_perdue']) ? 0 : 1)) {
            if ($perdue === 1) {
                $detail = sprintf(__('%1$s : source perdue (%2$s) — dernier état connu gardé', __FILE__),
                    $this->getName(), isset($verdict['source_perdue_raison']) ? $verdict['source_perdue_raison'] : '');
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $detail);
                $this->journaliserPresence('source_perdue', $detail);
            } else {
                $this->journaliserPresence('source_retrouvee',
                    sprintf(__('%s : source retrouvée', __FILE__), $this->getName()));
            }
            $memoire['source_perdue'] = $perdue;
        }

        cache::set($cle, $memoire, self::CACHE_DUREE);

        $minutes = (int) floor(max(0, $maintenant - (int) $memoire['depuis']) / 60);

        $this->checkAndUpdateCmd('brut', $brut);
        $this->checkAndUpdateCmd('presence', $present);
        $this->checkAndUpdateCmd('etat', $verdict['libelle']);
        $this->checkAndUpdateCmd('depuis', $minutes);
        $this->checkAndUpdateCmd('mode', self::libelleMode($verdict['mode']));
        $this->checkAndUpdateCmd('vu_depuis', isset($verdict['vu_depuis']) ? (int) $verdict['vu_depuis'] : 0);

        $verdict['depuis'] = $minutes;
        $verdict['depuis_ts'] = (int) $memoire['depuis'];
        return $verdict;
    }

    /*
     * Le départ qui vient de s'achever était-il faux ?
     *
     * Le « rebond absorbé » ne se dit que lorsque le signal revient AVANT la fin
     * du délai : c'est le cas heureux, celui où le plugin a fait son travail.
     * Quand le signal revient APRÈS, le départ a été confirmé, les règles de
     * départ ont été jouées — l'alarme a pu s'armer — et le journal n'écrivait
     * qu'un départ puis une arrivée, dix minutes plus loin, sans rien dire du
     * lien entre les deux. C'est pourtant le seul cas qui coûte cher, et le
     * seul qui dise que le délai est trop court.
     *
     * Le creux mesuré est celui du SIGNAL, d'un bout à l'autre : de l'instant
     * où la balise s'est tue — la date du départ confirmé, moins le délai qui
     * l'a produite — à celui où elle a reparlé. C'est le chiffre à comparer au
     * délai, et c'est celui que l'analyse manipule.
     *
     * Rien n'est dit si la personne était forcée : le retour n'a alors aucun
     * rapport avec sa balise, et l'arithmétique ne voudrait rien dire.
     */
    private function signalerFauxDepart($_verdict, $_avantDepuis, $_maintenant, $_reglages) {
        if (isset($_verdict['mode']) && $_verdict['mode'] !== 'auto') {
            return false;
        }
        $seuil = (int) self::reglageGlobal('seuil_vrai', self::SEUIL_VRAI_DEFAUT) * 60;
        if ($seuil <= 0 || (int) $_avantDepuis <= 0) {
            return false;
        }
        $delai = (int) $_reglages['delai_depart'] * 60;
        $perdu = (int) $_avantDepuis - $delai;
        $revenu = isset($_verdict['signal_depuis']) ? (int) $_verdict['signal_depuis'] : (int) $_maintenant;
        $creux = $revenu - $perdu;
        /* Un creux qui dépasse le seuil est une vraie sortie, et un creux
         * négatif ou nul vient d'un cache reconstruit : dans les deux cas, on
         * se tait plutôt que d'accuser le réglage à tort. */
        if ($creux <= 0 || $creux >= $seuil) {
            return false;
        }
        $this->journaliserPresence('faux_depart', sprintf(
            __('%1$s : absent %2$s puis revenu — faux départ probable, le délai de départ de %3$s min n\'a pas suffi', __FILE__),
            $this->getName(), self::duree($creux), (int) $_reglages['delai_depart']));
        return true;
    }

    /* L'instant où la présence stabilisée a basculé : la fin du délai qui
     * courait, et non l'instant où on le constate. Pendant une confirmation en
     * cours, l'état stabilisé n'a justement pas changé — on rend « maintenant »,
     * valeur qui ne servira qu'au tout premier passage. */
    private function instantStabilisation($_verdict, $_maintenant) {
        if (!empty($_verdict['transitoire']) || empty($_verdict['source'])) {
            return $_maintenant;
        }
        $depuis = isset($_verdict['signal_depuis']) ? (int) $_verdict['signal_depuis'] : $_maintenant;
        /*
         * Les délais passent par presenciumPersonne::normaliserReglages(), la
         * même normalisation que celle qui a rendu le verdict, et jamais par un
         * (int) sur la configuration brute.
         *
         * Un champ laissé vide vaut 15 minutes pour la décision et vaudrait 0
         * ici : « Depuis » annoncerait un départ survenu un quart d'heure plus
         * tôt que celui qui a réellement eu lieu. Or c'est précisément ce
         * chiffre qu'on relit pour comprendre une décision et pour régler le
         * délai — deux valeurs différentes pour le même réglage, et le réglage
         * se fait à l'aveugle.
         */
        $reglages = presenciumPersonne::normaliserReglages($this->getConfiguration());
        $delai = (!empty($_verdict['present']))
               ? (int) $reglages['delai_arrivee']
               : (int) $reglages['delai_depart'] * 60;
        return min($_maintenant, $depuis + max(0, $delai));
    }

    /*
     * Force la personne, ou lui rend son autonomie.
     *
     * L'enregistrement complet est assumé : c'est un geste d'utilisateur, il
     * arrive quelques fois par jour au plus, et il doit survivre à tout — d'où
     * l'écriture en base plutôt qu'en cache.
     */
    public function forcerPersonne($_mode) {
        $mode = self::modeValide($_mode);

        /*
         * save(true) : écriture directe, sans repasser par preSave/postSave.
         * Ce n'est pas une optimisation. Ces bascules sont appelées depuis le
         * cron, depuis un scénario et depuis les actions d'une règle — or
         * postSave() rafraîchit l'équipement, qui rejoue les règles, qui
         * peuvent rappeler cette même bascule. Le chemin se referme (les
         * transitions et les attentes sont consommées avant de rappeler), mais
         * il n'a aucune raison d'exister ; et recréer toutes les commandes à
         * chaque appui sur un bouton n'en a pas davantage.
         *
         * Écriture sur l'objet RELU en base : voir objetFrais(). Les quatre
         * bascules d'état du plugin — forçage, armement, mise en service,
         * simulation — écrivent toutes de cette façon.
         */
        $frais = $this->objetFrais();
        $frais->setConfiguration('mode', $mode);
        $frais->save(true);
        $this->reporterConfiguration($frais, array('mode'));

        /* Le geste au journal, et pas seulement dans le log du plugin : c'est
         * la CAUSE de la bascule qui va suivre, et le journal est l'endroit où
         * l'on cherche pourquoi une alarme s'est armée. journaliserPresence()
         * écrit aussi la ligne de log, l'ancienne devenait redondante. */
        $this->journaliserPresence('forcage', sprintf(
            ($mode === 'auto')
                ? __('%1$s : %2$s — la balise reprend la main', __FILE__)
                : __('%1$s : %2$s — la balise n\'a plus voix au chapitre', __FILE__),
            $this->getName(), self::libelleMode($mode)));

        $maintenant = time();
        $verdict = $this->rafraichirPersonne($maintenant);
        self::rafraichirFoyersDe((int) $this->getId(), $maintenant);
        return $verdict;
    }

    /* Journalise une transition de présence dans le journal de CHAQUE foyer qui
     * contient cette personne : une personne n'a pas de journal à elle, parce
     * que ce qu'on relit c'est l'histoire d'une maison, pas celle d'une balise
     * prise isolément. */
    private function journaliserPresence($_verdict, $_detail) {
        /* Chez elle d'abord. Une personne qui n'appartient encore à aucun foyer
         * — le cas de toute installation qui démarre — voyait sinon ses rebonds
         * absorbés disparaître sans laisser de trace, alors que c'est
         * exactement ce que le mode simulation doit montrer. */
        $this->journalAjouter(array(
            'genre' => 'presence',
            'simulation' => $this->enSimulation(),
            'personne' => (int) $this->getId(),
            'nom' => $this->getName(),
            'verdict' => $_verdict,
            'detail' => $_detail,
        ));
        foreach (self::foyersDe((int) $this->getId()) as $foyer) {
            $foyer->journalAjouter(array(
                'genre' => 'presence',
                'simulation' => $foyer->enSimulation(),
                'personne' => (int) $this->getId(),
                'nom' => $this->getName(),
                'verdict' => $_verdict,
                'detail' => $_detail,
            ));
        }
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $_detail);
    }

    /* L'instant où le plugin a vu ce signal pour la première fois, employé
     * quand la commande source ne porte aucune date exploitable. La valeur
     * observée est mémorisée avec : dès qu'elle change, le décompte repart de
     * zéro, exactement comme l'aurait fait une vraie date de changement. */
    private function premiereVue($_valeur, $_maintenant) {
        $cle = self::CACHE_PREMIERE_VUE . $this->getId();
        $memoire = cache::byKey($cle)->getValue('');
        $signature = sha1((string) $_valeur);

        if (is_string($memoire) && strpos($memoire, '|') !== false) {
            list($signatureConnue, $instant) = explode('|', $memoire, 2);
            if ($signatureConnue === $signature && (int) $instant > 0 && (int) $instant <= $_maintenant) {
                return (int) $instant;
            }
        }
        cache::set($cle, $signature . '|' . $_maintenant, self::CACHE_DUREE);
        return $_maintenant;
    }

    private function oublierPremiereVue() {
        $cle = self::CACHE_PREMIERE_VUE . $this->getId();
        if (cache::byKey($cle)->getValue('') !== '') {
            cache::delete($cle);
        }
    }

    /* =================================================================== FOYER */

    /* Les personnes de ce foyer, dans l'ordre choisi par l'utilisateur.
     *
     * Les identifiants morts sont écartés à la LECTURE et non nettoyés en base :
     * supprimer une personne modifierait alors la configuration de tous les
     * foyers pendant une suppression, et une restauration de sauvegarde partielle
     * perdrait la composition du foyer sans prévenir. Un identifiant mort ne
     * compte donc nulle part, sans rien écrire. */
    public function personnes() {
        $personnes = array();
        $ids = $this->getConfiguration('personnes');
        foreach (is_array($ids) ? $ids : array() as $id) {
            $eqLogic = eqLogic::byId((int) $id);
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) {
                continue;
            }
            /*
             * Le type est lu par type() et non par getConfiguration('type'), et
             * c'est la seule lecture juste : type() tient un type absent pour
             * une personne, un getConfiguration() nu l'écarte.
             *
             * L'écart n'était pas théorique. Le cron rafraîchissait bien une
             * personne dont le champ `type` avait été perdu — copie
             * d'équipement, restauration partielle, configuration éditée à la
             * main — mais elle disparaissait de tous les foyers : le total
             * baissait, et le total qui baisse fait justement renoncer aux
             * départs de ce passage. La personne s'évaporait du foyer et son
             * départ était supprimé en silence, sans une ligne nulle part.
             */
            if ($eqLogic->type() !== self::TYPE_PERSONNE) {
                continue;
            }
            /* Une personne désactivée sort du foyer : sinon « tout le monde est
             * là » ne serait plus jamais vrai, sans que rien ne le dise. */
            if ($eqLogic->getIsEnable() != 1) {
                continue;
            }
            $personnes[] = $eqLogic;
        }
        return $personnes;
    }

    /* Les foyers qui contiennent cette personne. */
    public static function foyersDe($_idPersonne) {
        $foyers = array();
        $id = (int) $_idPersonne;
        if ($id <= 0) {
            return $foyers;
        }
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->type() !== self::TYPE_FOYER) {
                continue;
            }
            $ids = $eqLogic->getConfiguration('personnes');
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $candidat) {
                if ((int) $candidat === $id) {
                    $foyers[] = $eqLogic;
                    break;
                }
            }
        }
        return $foyers;
    }

    /*
     * L'instantané du foyer : qui est là, et sur combien.
     *
     * Les présences sont RECALCULÉES et non lues dans les commandes. C'est un
     * calcul pur — une lecture de cache et deux comparaisons par personne — et
     * la commande, elle, peut dater d'un cron en retard. Or c'est précisément
     * quand le cron prend du retard qu'une décision d'alarme doit rester juste.
     */
    public function instantane($_maintenant = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $presents = array();
        $membres = array();
        $personnes = $this->personnes();
        foreach ($personnes as $personne) {
            $membres[] = (int) $personne->getId();
            try {
                $present = !empty($personne->verdictPersonne($maintenant)['present']);
            } catch (Throwable $e) {
                /* Une erreur ne fait pas partir quelqu'un : on reprend son
                 * dernier état stabilisé, absent s'il n'y en a pas. */
                log::add(__CLASS__, 'error', $personne->getHumanName() . ' : ' . $e->getMessage());
                $memoire = cache::byKey(self::CACHE_PRESENCE . (int) $personne->getId())->getValue(null);
                $present = (is_array($memoire) && isset($memoire['present']) && (int) $memoire['present'] === 1);
            }
            if ($present) {
                $presents[(int) $personne->getId()] = $personne->getName();
            }
        }
        /* `membres` : toute la composition, présents ou non — ce qui permet à
         * transitions() de reconnaître un changement de composition. */
        return array('presents' => $presents, 'total' => count($personnes), 'membres' => $membres);
    }

    /*
     * Le travail d'un foyer : publier son état, puis décider.
     *
     * L'ordre compte. Les attentes sont traitées AVANT les nouvelles
     * transitions : une règle armée à la minute précédente et dont le
     * déclencheur vient de s'inverser doit être annulée par cette inversion, pas
     * réarmée par elle.
     */
    public function rafraichirFoyer($_maintenant = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;

        /*
         * GARDE DE RÉENTRANCE, posée avant toute autre chose.
         *
         * Les actions d'une règle sont de vraies actions Jeedom, et rien
         * n'empêche qu'elles visent le plugin lui-même : « Forcer absent »,
         * « Réévaluer maintenant ». Or forcerPersonne() rafraîchit les foyers de
         * la personne, ce qui produit un nouvel instantané, donc une transition,
         * donc de nouvelles règles jouées. Deux règles opposées suffisent —
         * « à l'arrivée, forcer absent » et « au départ, forcer présent » — et
         * l'appel se rappelle lui-même jusqu'au temps maximal d'exécution de
         * PHP. Ce n'est pas le plugin qui tombe alors, c'est le processus de
         * cron que TOUS les plugins partagent : plus rien ne tourne sur
         * l'installation, et le journal ne montre qu'un cron qui n'a pas fini.
         *
         * Un appel imbriqué renonce donc, sans erreur : l'état est déjà en cours
         * de calcul un cran plus haut, et le passage suivant du cron — une
         * minute plus tard — verra de toute façon le résultat du forçage.
         *
         * Le compteur est statique et vaut pour tous les foyers du processus :
         * la boucle peut passer par un autre foyer que celui d'où elle part.
         * Il est aussi ce qui garantit qu'on ne demandera jamais deux fois le
         * verrou ci-dessous dans le même processus.
         */
        if (self::$_profondeurFoyer > 0) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : '
                   . __('réévaluation imbriquée ignorée (une action de règle rappelle le foyer).', __FILE__));
            return array('instantane' => array('presents' => array(), 'total' => 0, 'membres' => array()),
                         'transitions' => array(), 'attentes' => array(), 'regles' => array(),
                         'etat' => array(), 'simulation' => $this->enSimulation(),
                         'imbrique' => true);
        }

        /*
         * VERROU PAR FOYER sur toute la section « lire l'instantané → calculer
         * les transitions → jouer les règles → écrire l'instantané ».
         *
         * Le cron et l'écouteur tournent dans deux processus distincts et
         * peuvent viser le même foyer à la même seconde. Sans verrou, les deux
         * lisent le même instantané mémorisé, en tirent les mêmes transitions,
         * et jouent les mêmes règles : l'alarme s'arme deux fois, la
         * notification part deux fois. L'anti-répétition posée avant les actions
         * (voir executerRegle) réduit la fenêtre à quelques instructions, elle
         * ne la ferme pas — cache::byKey() puis cache::set() ne sont pas une
         * opération atomique.
         *
         * Même technique que le verrou du journal, mais sur un fichier
         * DISTINCT : flock() ne se reprend pas dans un même processus (deux
         * fopen() donnent deux verrous), et journalAjouter() est appelé depuis
         * cette section — un seul fichier pour les deux, et le foyer
         * s'attendrait lui-même indéfiniment.
         *
         * Et le verrou N'ATTEND PAS : un foyer peut le tenir plusieurs
         * secondes (actions lentes), et un cron qui attendrait son tour
         * bloquerait tous les plugins. Mais renoncer PERD quelque chose : le
         * détenteur a pris son instantané AVANT le changement qui réveille ce
         * processus-ci. On pose donc un drapeau « à refaire », et le détenteur
         * refait une passe, une seule, avant de relâcher.
         */
        $cleRefaire = self::CACHE_REFAIRE . $this->getId();
        $verrou = self::verrouFichier($this->cheminVerrouFoyer(), false);
        if ($verrou === false) {
            cache::set($cleRefaire, 1, 600);
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : '
                   . __('évaluation déjà en cours dans un autre processus, elle sera refaite par lui.', __FILE__));
            return array('instantane' => $this->instantane($maintenant),
                         'transitions' => array(), 'attentes' => array(), 'regles' => array(),
                         'etat' => array(), 'simulation' => $this->enSimulation(),
                         'occupe' => true);
        }

        self::$_profondeurFoyer++;
        try {
            cache::delete($cleRefaire);
            $rendu = $this->rafraichirFoyerSousVerrou($maintenant);
            if ((int) cache::byKey($cleRefaire)->getValue(0) === 1) {
                cache::delete($cleRefaire);
                self::oublierVerdicts();
                $rendu = $this->rafraichirFoyerSousVerrou(max($maintenant, time()));
            }
            return $rendu;
        } finally {
            self::$_profondeurFoyer--;
            self::libererVerrou($verrou);
        }
    }

    /*
     * Le corps du rafraîchissement, une fois le verrou tenu.
     *
     * L'ordre des trois dernières lignes est ce qui empêche de perdre un front.
     * L'instantané mémorisé n'est réécrit qu'une fois les transitions
     * CONSOMMÉES : tant qu'elles ne le sont pas, le passage suivant les
     * retrouvera. Écrit plus tôt — comme ce l'était — une exception échappée de
     * publierFoyer() ou de traiterAttentes() privait le foyer de jouerRegles()
     * alors que l'instantané avait déjà avancé : les arrivées et les départs de
     * cette minute-là étaient perdus pour toujours, et l'alarme ne s'armait
     * jamais sur ce départ-là.
     *
     * Chaque étape porte en plus son propre try/catch, pour que la suivante ait
     * lieu quoi qu'il arrive. Entre l'instant où les transitions sont calculées
     * et celui où elles sont jouées, plus rien ne peut échapper.
     */
    private function rafraichirFoyerSousVerrou($_maintenant) {
        $instantane = $this->instantane($_maintenant);

        $cleInstantane = self::CACHE_INSTANTANE . $this->getId();
        $avant = cache::byKey($cleInstantane)->getValue(null);
        if (!is_array($avant) || !isset($avant['presents']) || !is_array($avant['presents'])) {
            /* Premier passage, ou cache vidé : on part de l'instantané courant.
             * Autrement, l'installation du plugin sur une maison occupée
             * fabriquerait une arrivée générale, et toutes les règles
             * d'arrivée partiraient d'un coup. */
            $avant = $instantane;
        }

        $transitions = presenciumRegles::transitions($avant, $instantane);
        if (!is_array($transitions)) {
            $transitions = array();
        }

        $etats = array();
        try {
            $arrives = array_diff_key($instantane['presents'], $avant['presents']);
            $partis = array_diff_key($avant['presents'], $instantane['presents']);
            $etats = $this->publierFoyer($_maintenant, $instantane, $arrives, $partis);
        } catch (Throwable $e) {
            /* Publier des commandes est de l'affichage : ça ne doit jamais
             * empêcher de décider. */
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }

        $annulees = array();
        try {
            $annulees = $this->traiterAttentes($_maintenant, $instantane);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }

        $rendus = $this->jouerRegles($_maintenant, $transitions, $instantane);

        /* Les transitions sont consommées : l'instantané peut avancer. */
        cache::set($cleInstantane, $instantane, self::CACHE_DUREE);

        return array(
            'instantane'  => $instantane,
            'transitions' => $transitions,
            'attentes'    => $annulees,
            'regles'      => $rendus,
            'etat'        => $etats,
            'simulation'  => $this->enSimulation(),
        );
    }

    /*
     * Repose les commandes d'information du foyer.
     *
     * `en_service` et `armee` sont republiés depuis la CONFIGURATION à chaque
     * passage, et c'est le point : la commande vit dans le cache du cœur, la
     * configuration vit en base. Un vidage de cache remettrait sinon une alarme
     * armée à « désarmée » sans que personne ne l'ait demandé, et la maison
     * serait ouverte jusqu'au prochain geste humain.
     */
    private function publierFoyer($_maintenant, $_instantane, $_arrives, $_partis) {
        $cle = self::CACHE_FOYER . $this->getId();
        $etats = cache::byKey($cle)->getValue(null);
        if (!is_array($etats)) {
            $etats = array('presence' => null, 'vide_depuis' => 0, 'occupee_depuis' => 0,
                           'premier' => '', 'dernier' => '');
        }

        $nombre = count($_instantane['presents']);
        $occupe = ($nombre > 0) ? 1 : 0;

        if ($etats['presence'] === null || (int) $etats['presence'] !== $occupe) {
            if ($occupe === 1) {
                $etats['occupee_depuis'] = $_maintenant;
                $etats['vide_depuis'] = 0;
                if (count($_arrives) > 0) {
                    $etats['premier'] = implode(', ', $_arrives);
                } elseif ($etats['premier'] === '') {
                    $etats['premier'] = implode(', ', $_instantane['presents']);
                }
            } else {
                $etats['vide_depuis'] = $_maintenant;
                $etats['occupee_depuis'] = 0;
                if (count($_partis) > 0) {
                    $etats['dernier'] = implode(', ', $_partis);
                }
            }
            $etats['presence'] = $occupe;
        }
        cache::set($cle, $etats, self::CACHE_DUREE);

        $this->checkAndUpdateCmd('presence', $occupe);
        $this->checkAndUpdateCmd('tous', ($_instantane['total'] > 0 && $nombre === (int) $_instantane['total']) ? 1 : 0);
        $this->checkAndUpdateCmd('occupation', $nombre);
        $this->checkAndUpdateCmd('qui', ($nombre > 0) ? implode(', ', $_instantane['presents']) : __('Personne', __FILE__));
        $this->checkAndUpdateCmd('etat', self::libelleEtatFoyer($nombre, (int) $_instantane['total']));
        $this->checkAndUpdateCmd('vide_depuis', ($occupe === 1 || (int) $etats['vide_depuis'] <= 0)
            ? 0 : (int) floor(($_maintenant - (int) $etats['vide_depuis']) / 60));
        /* Le compteur existait déjà en cache pour le déclencheur « occupée
         * depuis » ; il n'était simplement jamais publié. */
        $this->checkAndUpdateCmd('occupee_depuis', ($occupe === 0 || (int) $etats['occupee_depuis'] <= 0)
            ? 0 : (int) floor(($_maintenant - (int) $etats['occupee_depuis']) / 60));
        $this->checkAndUpdateCmd('premier', $etats['premier']);
        $this->checkAndUpdateCmd('dernier', $etats['dernier']);
        $this->checkAndUpdateCmd('en_service', ($this->getConfiguration('etat_en_service', 0) == 1) ? 1 : 0);
        $this->checkAndUpdateCmd('armee', ($this->getConfiguration('etat_armee', 0) == 1) ? 1 : 0);
        $this->checkAndUpdateCmd('simulation', $this->enSimulation() ? 1 : 0);

        return $etats;
    }

    public static function libelleEtatFoyer($_presents, $_total) {
        if ($_presents <= 0) {
            return __('Vide', __FILE__);
        }
        if ($_total > 0 && $_presents >= $_total) {
            return __('Complet', __FILE__);
        }
        return __('Partiel', __FILE__);
    }

    /* ================================================================= RÈGLES */

    /* Les règles de ce foyer, normalisées. Renormalisées à la lecture et pas
     * seulement à l'écriture : une configuration importée, restaurée ou écrite
     * par un script n'est jamais passée par preSave(). */
    public function regles() {
        if ($this->type() !== self::TYPE_FOYER) {
            return array();
        }
        $regles = presenciumRegles::normaliser($this->getConfiguration('regles'));
        if (!is_array($regles)) {
            return array();
        }
        /* Ceinture et bretelles : presenciumRegles garantit ces clés, mais elle
         * vit dans un autre fichier et une clé manquante ici ne produirait pas
         * une règle bancale — elle produirait un « Undefined index » fatal sur
         * la page de l'équipement, sans rien dans le journal du plugin. */
        $propres = array();
        foreach ($regles as $rang => $regle) {
            if (!is_array($regle)) {
                continue;
            }
            $propres[] = array_merge(array(
                /* Le même repli déterministe que presenciumRegles : deux
                 * formes d'identifiant pour une même règle selon le chemin de
                 * lecture, et l'attente posée par l'un ne serait pas retrouvée
                 * par l'autre. */
                'id'          => presenciumRegles::identifiantDeRang($rang),
                'nom'         => __('Règle', __FILE__) . ' ' . ($rang + 1),
                'actif'       => 1,
                'declencheur' => 'depart_dernier',
                'personne'    => 0,
                'minutes'     => 0,
                'attente'     => 0,
                'repos'       => 0,
                'simulation'  => 0,
                'conditions'  => array('lignes' => array()),
                'actions'     => array(),
            ), $regle);
        }
        return $propres;
    }

    /*
     * Une règle de ce foyer par son IDENTIFIANT, normalisée, ou null.
     *
     * Par l'identifiant et jamais par le rang. Le bouton « Tester » désignait
     * la règle par sa position dans le tableau : réordonner la liste sans
     * enregistrer — ce que fait l'éditeur, puisqu'il travaille sur une copie —
     * faisait tester, et donc EXÉCUTER pour de vrai, une autre règle que celle
     * affichée à l'écran. Un bouton d'essai qui arme l'alarme alors qu'on
     * croyait déclencher une lampe est la pire promesse que puisse faire une
     * interface.
     *
     * L'identifiant, lui, ne bouge pas quand la liste bouge : c'est la raison
     * d'être de nouvelIdentifiant(), et c'est déjà lui qui porte les attentes et
     * les repos.
     */
    public function regleParId($_id) {
        $id = trim((string) $_id);
        if ($id === '') {
            return null;
        }
        foreach ($this->regles() as $regle) {
            if ((string) $regle['id'] === $id) {
                return $regle;
            }
        }
        return null;
    }

    /*
     * Confronte les transitions franchies à chaque règle, dans l'ordre où elles
     * ont été franchies, puis fait courir les déclencheurs temporels.
     */
    public function jouerRegles($_maintenant, $_transitions, $_instantane) {
        $rendus = array();
        $regles = $this->regles();
        $temporels = array('vide_depuis', 'occupee_depuis');

        /*
         * LES TRANSITIONS À L'EXTÉRIEUR, LES RÈGLES À L'INTÉRIEUR.
         *
         * transitions() construit un ordre qui se lit comme la scène se
         * déroule : arrivee_premier, les arrivées, arrivee_tous, les départs,
         * depart_dernier. Boucler sur les règles à l'extérieur jetait cet ordre
         * et le remplaçait par celui du tableau des règles, c'est-à-dire par
         * l'ordre d'AFFICHAGE dans la modale. Quand quelqu'un rentre à la
         * minute où un autre part, « la maison n'est plus vide → désarmer » et « la maison
         * se vide → armer » se jouaient alors dans l'ordre où l'utilisateur les
         * avait saisies : selon le jour, la maison finissait armée avec
         * quelqu'un dedans, ou ouverte avec personne. C'est la chronologie qui
         * décide, jamais la mise en page.
         */
        /*
         * Une règle ne part qu'UNE FOIS par passage, même si plusieurs
         * transitions lui correspondent. Le cas se produit dès qu'une règle
         * vise « n'importe qui » : quand les deux habitants partent dans la
         * même minute, il y a deux transitions `depart`, et sans ce garde la
         * notification serait envoyée deux fois et la sirène commandée deux
         * fois. Le repos ne rattrape pas le cas, puisqu'il vaut zéro par
         * défaut et que les deux exécutions tiennent dans la même seconde.
         */
        $deja = array();

        foreach ($_transitions as $transition) {
            foreach ($regles as $regle) {
                if (in_array($regle['declencheur'], $temporels, true)) {
                    continue;
                }
                if (isset($deja[$regle['id']])) {
                    continue;
                }
                if (!presenciumRegles::correspond($regle, $transition)) {
                    continue;
                }
                $deja[$regle['id']] = true;
                try {
                    $rendus[] = $this->executerRegle($regle, $_maintenant, array(
                        'transition' => $transition, 'instantane' => $_instantane));
                } catch (Throwable $e) {
                    /* Une règle en échec ne prive pas les suivantes : c'est la
                     * même garantie qu'au niveau de l'équipement, un cran plus
                     * bas. */
                    log::add(__CLASS__, 'error', $this->getHumanName() . ' — ' . $regle['nom'] . ' : ' . $e->getMessage());
                }
            }
        }

        /*
         * Les déclencheurs temporels ensuite, et à part : ils ne naissent pas
         * d'une comparaison entre deux instantanés mais de l'écoulement du
         * temps. C'est le cron qui les fait courir, minute après minute, et
         * c'est une des raisons pour lesquelles il ne fait pas double emploi
         * avec le listener. Ils viennent après les fronts parce qu'ils parlent
         * de l'état auquel ces fronts viennent d'aboutir.
         */
        foreach ($regles as $regle) {
            if (!in_array($regle['declencheur'], $temporels, true)) {
                continue;
            }
            try {
                /*
                 * `actif` et `repos` sont testés ICI, avant transitionTemporelle
                 * — donc avant que l'épisode ne soit marqué consommé.
                 *
                 * L'épisode (une absence, une occupation) n'est présenté qu'une
                 * fois : le marquer alors que la règle est décochée revenait à
                 * la brûler. Décochée le temps d'un essai puis recochée dix
                 * minutes plus tard, elle ne partait plus de TOUTE l'absence, et
                 * l'utilisateur en concluait que le plugin ne marchait pas.
                 * Même chose pour une règle encore au repos.
                 *
                 * Elles ne sont pas journalisées ici, à la différence des
                 * règles de front : un déclencheur temporel se représente à
                 * chaque minute, et une ligne « désactivée » par minute
                 * pendant une absence de six heures rendrait illisible le
                 * journal, qui est justement l'outil qu'on vient y chercher.
                 * Qu'une règle soit décochée se lit dans la liste des règles, à
                 * côté de sa case.
                 */
                if ((int) $regle['actif'] !== 1 || $this->enRepos($regle, $_maintenant)) {
                    continue;
                }
                $transition = $this->transitionTemporelle($regle, $_maintenant, $_instantane);
                if ($transition === null) {
                    continue;
                }
                $rendus[] = $this->executerRegle($regle, $_maintenant, array(
                    'transition' => $transition, 'instantane' => $_instantane));
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $this->getHumanName() . ' — ' . $regle['nom'] . ' : ' . $e->getMessage());
            }
        }
        return $rendus;
    }

    /*
     * Le déclencheur d'une règle `vide_depuis` / `occupee_depuis`, ou null.
     *
     * Une seule fois par ÉPISODE : l'épisode est identifié par l'instant où le
     * foyer s'est vidé (ou rempli), et cet instant est mémorisé dès que la
     * règle est présentée à l'exécution. « La maison est vide depuis 30
     * minutes » est un événement, pas un état : il n'arrive qu'une fois par
     * absence. Le marquer à la présentation et non au déclenchement a un effet
     * à connaître — une règle dont les conditions ou l'horaire sont faux à
     * cette minute-là ne sera pas réessayée pendant cette absence — mais c'est
     * ce qui garantit qu'une condition durablement fausse ne remplit pas le
     * journal d'une ligne par minute, ce qui le rendrait illisible au moment où
     * on en a besoin.
     *
     * « Présentée à l'exécution » se prend au mot : jouerRegles() écarte les
     * règles décochées et celles encore au repos AVANT d'appeler cette méthode,
     * pour qu'elles ne consomment pas l'épisode. Une règle recochée en pleine
     * absence doit encore pouvoir partir pendant cette absence-là.
     */
    private function transitionTemporelle($_regle, $_maintenant, $_instantane) {
        $minutes = (int) $_regle['minutes'];
        if ($minutes <= 0) {
            return null;
        }
        $vide = (count($_instantane['presents']) === 0);
        if (($_regle['declencheur'] === 'vide_depuis') !== $vide) {
            return null;
        }
        /* Un foyer sans personne n'est pas un foyer vide : c'est un foyer qui
         * n'est pas configuré. Armer une alarme là-dessus serait armer sur du
         * néant. */
        if ((int) $_instantane['total'] <= 0) {
            return null;
        }

        $etats = cache::byKey(self::CACHE_FOYER . $this->getId())->getValue(null);
        $debut = 0;
        if (is_array($etats)) {
            $debut = (int) (($_regle['declencheur'] === 'vide_depuis') ? $etats['vide_depuis'] : $etats['occupee_depuis']);
        }
        if ($debut <= 0 || ($_maintenant - $debut) < $minutes * 60) {
            return null;
        }

        $cle = self::CACHE_TEMPOREL . $this->getId() . '::' . $_regle['id'];
        if ((int) cache::byKey($cle)->getValue(0) === $debut) {
            return null;
        }
        cache::set($cle, $debut, self::CACHE_DUREE);

        return array('type' => $_regle['declencheur'], 'personne' => 0);
    }

    /*
     * Une règle, de son déclencheur à ses actions.
     *
     * L'ordre des refus n'est pas indifférent : `desactivee` et `repos`
     * viennent en premier parce qu'ils portent sur la règle elle-même, tandis
     * que l'horaire et les conditions décrivent le monde et sont donc évalués
     * au moment d'AGIR — c'est-à-dire après l'attente, pas avant. Une règle qui
     * arme cinq minutes après le départ doit vérifier que la maison est
     * toujours vide au moment où elle arme, pas au moment où elle a commencé à
     * attendre.
     *
     * $_test est le bouton « Tester » : il ignore `actif`, `repos` et
     * `attente`, qui sont des questions d'ordonnancement, mais évalue l'horaire
     * et les conditions pour les montrer, et exécute quand même les actions —
     * parce que ce qu'on veut vérifier avec ce bouton, c'est justement que les
     * actions partent.
     *
     * $_contexte['simuler'] force la simulation (case cochée à l'écran pour
     * un essai), en plus de enSimulation().
     */
    public function executerRegle($_regle, $_maintenant = null, $_contexte = array(), $_test = false) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $simulation = $this->enSimulation($_regle) || !empty($_contexte['simuler']);
        $attenteTerminee = !empty($_contexte['attente_terminee']);

        /* Le détail des présences n'est calculé qu'après les refus qui ne
         * dépendent que de la règle : il coûte un verdict par personne. */
        $entree = array(
            'genre'       => 'regle',
            'simulation'  => $simulation,
            'essai'       => $_test ? true : false,
            'regle'       => $_regle['id'],
            'nom'         => $_regle['nom'],
            'declencheur' => $this->libelleDeclencheur($_regle),
            'verdict'     => 'declenchee',
            'detail'      => '',
            'conditions'  => array(),
            'actions'     => array(),
        );

        if (!$_test && (int) $_regle['actif'] !== 1) {
            return $this->conclureRegle($entree, 'desactivee');
        }
        if (!$_test && !$attenteTerminee && $this->enRepos($_regle, $maintenant)) {
            return $this->conclureRegle($entree, 'repos');
        }

        $instantane = isset($_contexte['instantane']) ? $_contexte['instantane'] : $this->instantane($maintenant);
        $entree['detail'] = $this->detailPresence($maintenant, $instantane);

        if (!$_test && !$attenteTerminee && (int) $_regle['attente'] > 0) {
            cache::set(self::CACHE_ATTENTE . $this->getId() . '::' . $_regle['id'], array(
                'echeance'   => $maintenant + (int) $_regle['attente'] * 60,
                'pose'       => $maintenant,
                'transition' => isset($_contexte['transition']) ? $_contexte['transition'] : array(),
            ), self::CACHE_DUREE);
            $entree['detail'] = sprintf(__('en attente de %s min — %s', __FILE__),
                (int) $_regle['attente'], $entree['detail']);
            return $this->conclureRegle($entree, 'en_attente');
        }

        $horaireOk = presenciumRegles::horaireOk($_regle, $maintenant);
        $entree['conditions'] = $this->evaluerConditions($_regle);
        $conditionsOk = true;
        foreach ($entree['conditions'] as $condition) {
            if (empty($condition['resultat'])) {
                $conditionsOk = false;
            }
        }

        if (!$_test && !$horaireOk) {
            return $this->conclureRegle($entree, 'hors_horaire');
        }
        if (!$_test && !$conditionsOk) {
            return $this->conclureRegle($entree, 'conditions_non_remplies');
        }

        /*
         * L'ANTI-RÉPÉTITION EST POSÉE AVANT D'AGIR, jamais après.
         *
         * Le cron et l'écouteur tournent dans deux processus : posé après les
         * actions, le repos laissait toute la durée de leur exécution —
         * plusieurs secondes si l'une d'elles appelle un scénario ou une
         * passerelle distante — pendant laquelle l'autre processus lisait un
         * repos encore vide et jouait la même règle. L'alarme s'armait deux
         * fois, la notification partait deux fois.
         *
         * Le prix est assumé : si les actions échouent, la règle n'est pas
         * rejouée avant la fin du repos. Une action qui échoue laisse une ligne
         * « échec » dans le journal, avec son message ; une action jouée deux
         * fois ne laisse aucune trace de ce qu'elle a de doublé.
         *
         * (Le verrou de foyer — voir rafraichirFoyer() — ferme le cas pour de
         * bon ; ceci reste la protection de ce qui ne passe pas par lui.)
         */
        if (!$_test) {
            cache::set(self::CACHE_REPOS . $this->getId() . '::' . $_regle['id'], $maintenant, self::CACHE_DUREE);
        }

        $entree['actions'] = $this->executerActions($_regle, $simulation, $_test);
        foreach ($entree['actions'] as $action) {
            if (!empty($action['differee'])) {
                $entree['detail'] .= ' — ' . __('actions confiées à un processus séparé (la règle contient une pause)', __FILE__);
                break;
            }
        }

        $verdict = 'declenchee';
        if ($_test) {
            /* Un essai porte son propre verdict. Écrire « conditions non
             * remplies » au-dessus d'une liste d'actions marquées « exécutée »
             * ferait mentir le journal, et c'est ce journal qu'on relit des
             * semaines plus tard pour comprendre ce qui s'est passé. Le détail
             * des conditions reste dans l'entrée : on voit ce qui était vrai au
             * moment de l'essai, sans que cela prétende expliquer un
             * déclenchement qui n'en est pas un. */
            $verdict = 'essai';
        } elseif (count($entree['actions']) > 0) {
            /* « Échec » seulement si TOUTES les actions ont échoué : une règle
             * qui allume la lumière et envoie une notification a fait
             * l'essentiel quand seule la notification est tombée, et un verdict
             * d'échec ferait chercher une panne qui n'existe pas. Le détail par
             * action reste dans l'entrée, lui. */
            $echecs = 0;
            foreach ($entree['actions'] as $action) {
                if (empty($action['ok'])) {
                    $echecs++;
                }
            }
            if ($echecs === count($entree['actions'])) {
                $verdict = 'echec';
            }
        }
        return $this->conclureRegle($entree, $verdict);
    }

    /* Écrit l'entrée au journal et la rend à l'appelant : le compte-rendu que
     * lit l'AJAX est exactement la ligne que relira l'utilisateur, il n'y a donc
     * pas deux vérités possibles. */
    private function conclureRegle($_entree, $_verdict) {
        $entree = $_entree;
        $entree['verdict'] = $_verdict;
        $this->journalAjouter($entree);
        log::add(__CLASS__, ($_verdict === 'echec') ? 'error' : 'info',
            $this->getHumanName() . ' — ' . $entree['nom'] . ' : ' . $_verdict
            . ($entree['simulation'] ? ' ' . __('(simulation)', __FILE__) : ''));
        return $entree;
    }

    /*
     * Les conditions d'une règle.
     *
     * Chacune est repérée par l'IDENTIFIANT numérique de sa commande et non par
     * son nom : renommer un objet ou un équipement ne doit pas transformer
     * silencieusement une condition vraie en condition fausse. Le `nom` gardé à
     * côté n'est qu'un libellé de repli pour l'affichage, jamais relu pour
     * décider.
     *
     * Une commande disparue rend la condition FAUSSE, jamais vraie : le doute
     * n'arme pas une alarme.
     */
    private function evaluerConditions($_regle) {
        $rendu = array();
        $conditions = isset($_regle['conditions']) ? $_regle['conditions'] : array();
        $lignes = (isset($conditions['lignes']) && is_array($conditions['lignes'])) ? $conditions['lignes'] : array();

        foreach ($lignes as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $id = isset($ligne['cmd']) ? (int) $ligne['cmd'] : 0;
            $operateur = isset($ligne['operateur']) ? (string) $ligne['operateur'] : '==';
            $attendu = isset($ligne['valeur']) ? $ligne['valeur'] : '';

            $cmd = cmd::byId($id);
            if (!is_object($cmd)) {
                $rendu[] = array(
                    'texte'    => ((isset($ligne['nom']) && $ligne['nom'] !== '') ? $ligne['nom'] : ('#' . $id . '#'))
                                . ' — ' . __('commande introuvable', __FILE__),
                    'resultat' => false,
                );
                continue;
            }
            /* Une condition ne se lit que sur une commande d'information.
             * cmd::execCmd() exécute une commande d'action au lieu d'en rendre
             * la valeur : évaluer une telle condition appuierait sur un bouton
             * à chaque passage du cron, y compris en mode simulation, dont
             * c'est la seule fuite possible. */
            if ($cmd->getType() !== 'info') {
                $rendu[] = array(
                    'texte'    => $cmd->getHumanName() . ' — '
                                . __('ce n\'est pas une commande d\'information, la condition est tenue pour fausse', __FILE__),
                    'resultat' => false,
                );
                continue;
            }
            try {
                $valeur = $cmd->execCmd();
            } catch (Throwable $e) {
                $rendu[] = array('texte' => $cmd->getHumanName() . ' — ' . $e->getMessage(), 'resultat' => false);
                continue;
            }
            $resultat = presenciumRegles::comparer($valeur, $operateur, $attendu);
            $rendu[] = array(
                'texte'    => $cmd->getHumanName() . ' ' . $operateur . ' ' . $attendu
                            . ' (' . __('actuellement', __FILE__) . ' ' . $valeur . ')',
                'resultat' => $resultat ? true : false,
            );
        }
        return $rendu;
    }

    /*
     * Les actions d'une règle.
     *
     * En simulation, rien ne part — et c'est le seul endroit du plugin d'où
     * une action de règle peut partir, ce qui rend la garantie vérifiable d'un
     * coup d'œil.
     *
     * Une règle qui contient une pause (wait, sleep, ask) n'est jamais jouée
     * dans le processus courant hors essai : le cron du cœur est partagé par
     * tous les plugins, et une pause l'y gèlerait. Toute la séquence part
     * alors dans un processus séparé (differerActions()), dans l'ordre.
     *
     * $_arrierePlan : c'est ce processus séparé qui appelle.
     */
    private function executerActions($_regle, $_simulation, $_test, $_arrierePlan = false) {
        $rendu = array();
        $actions = (isset($_regle['actions']) && is_array($_regle['actions'])) ? $_regle['actions'] : array();

        if (!$_simulation && !$_test && !$_arrierePlan && self::regleBloquante($_regle)) {
            return $this->differerActions($_regle, $actions);
        }

        foreach ($actions as $action) {
            $nom = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
            if ($nom === '') {
                continue;
            }
            if ($_simulation) {
                $rendu[] = array('cmd' => $nom, 'resultat' => __('simulée', __FILE__), 'ok' => true);
                continue;
            }
            $rendu[] = self::executerAction($action, $_test);
        }
        return $rendu;
    }

    /*
     * Une action, avec un compte-rendu qui ne ment pas.
     *
     * scenarioExpression::createAndExec() avale toute erreur — y compris
     * « commande introuvable » — et ne dit rien hors scénario. Il ne sert donc
     * plus qu'à ce qui n'est pas une commande (mots-clés de scénario,
     * fonctions utilisateur) et à l'option « arrière-plan », et ces actions
     * sont dites « lancées », jamais « exécutées ». Une commande est résolue
     * ici et exécutée par cmd::execCmd(), qui lève : son échec se voit.
     */
    private static function executerAction($_action, $_test) {
        $nom = isset($_action['cmd']) ? trim((string) $_action['cmd']) : '';
        $options = (isset($_action['options']) && is_array($_action['options'])) ? $_action['options'] : array();

        /* Case « Activer » décochée dans l'éditeur : le cœur ne l'exécute pas. */
        if (isset($options['enable']) && (string) $options['enable'] === '0') {
            return array('cmd' => $nom, 'resultat' => __('désactivée, non exécutée', __FILE__), 'ok' => true);
        }

        try {
            if (self::estMotCle($nom)) {
                if ($_test && in_array($nom, self::ACTIONS_HORS_ESSAI, true)) {
                    return array('cmd' => $nom, 'ok' => true,
                                 'resultat' => __('ignorée pendant un essai (pause, question ou arrêt de Jeedom)', __FILE__));
                }
                scenarioExpression::createAndExec('action', $nom, $options);
                return array('cmd' => $nom, 'resultat' => __('lancée', __FILE__), 'ok' => true);
            }

            $cmd = self::commandeAction($_action);
            if (!is_object($cmd)) {
                if (self::estFonction($nom)) {
                    /* Une fonction utilisateur (data/php/user.function.class.php). */
                    scenarioExpression::createAndExec('action', $nom, $options);
                    return array('cmd' => $nom, 'resultat' => __('lancée', __FILE__), 'ok' => true);
                }
                return array('cmd' => $nom, 'ok' => false, 'resultat' => __('échec', __FILE__) . ' : '
                             . __('commande introuvable, choisissez-la à nouveau', __FILE__));
            }

            if (!$_test && isset($options['background']) && (string) $options['background'] === '1') {
                scenarioExpression::createAndExec('action', '#' . $cmd->getId() . '#', $options);
                return array('cmd' => $nom, 'resultat' => __('lancée en arrière-plan', __FILE__), 'ok' => true);
            }
            $prepares = self::preparerOptions($cmd, $options);
            $cmd->execCmd(count($prepares) > 0 ? $prepares : null);
            return array('cmd' => $nom, 'resultat' => __('exécutée', __FILE__), 'ok' => true);
        } catch (Throwable $e) {
            return array('cmd' => $nom, 'resultat' => __('échec', __FILE__) . ' : ' . $e->getMessage(), 'ok' => false);
        }
    }

    /* Les options telles que scenarioExpression::execute() les prépare pour
     * une commande : noms lisibles traduits (setOptions), tags #...# évalués,
     * curseur calculé. `enable` est retiré, comme le fait le cœur. */
    private static function preparerOptions($_cmd, $_options) {
        $options = array();
        foreach ($_options as $cle => $valeur) {
            if ($cle === 'enable') {
                continue;
            }
            $valeur = jeedom::fromHumanReadable($valeur);
            if (is_string($valeur)) {
                $valeur = scenarioExpression::setTags($valeur);
            }
            $options[$cle] = $valeur;
        }
        if ($_cmd->getSubType() == 'slider' && isset($options['slider'])) {
            $options['slider'] = evaluate($options['slider']);
        }
        return $options;
    }

    /* Un mot-clé d'action de scénario (wait, variable, scenario…). */
    public static function estMotCle($_nom) {
        return in_array(trim((string) $_nom), self::MOTS_CLES_ACTION, true);
    }

    /* Une fonction utilisateur du cœur (data/php/user.function.class.php),
     * reconnue comme le fait scenarioExpression::execute(). */
    private static function estFonction($_nom) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(\(.*\))?$/s', trim((string) $_nom), $morceaux)) {
            return false;
        }
        $fichier = __DIR__ . '/../../../../data/php/user.function.class.php';
        if (!class_exists('userFunction', false) && file_exists($fichier)) {
            require_once $fichier;
        }
        return class_exists('userFunction', false) && method_exists('userFunction', $morceaux[1]);
    }

    /* La règle contient-elle une action qui bloque le processus ? */
    public static function regleBloquante($_regle) {
        $actions = (isset($_regle['actions']) && is_array($_regle['actions'])) ? $_regle['actions'] : array();
        foreach ($actions as $action) {
            $nom = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
            $options = (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array();
            if (isset($options['enable']) && (string) $options['enable'] === '0') {
                continue;
            }
            if (in_array($nom, self::ACTIONS_BLOQUANTES, true)) {
                return true;
            }
        }
        return false;
    }

    /*
     * Confie toute la séquence à un processus séparé.
     *
     * Par le lanceur du cœur (jeeScenarioExpression.php) : une expression de
     * type `code` avec l'option `background` y est exécutée dans un nouveau
     * processus php. Elle ne fait qu'appeler executerSuite() avec une clé de
     * cache ; la règle elle-même voyage par le cache, jamais par le code.
     */
    private function differerActions($_regle, $_actions) {
        $cle = self::CACHE_SUITE . (int) $this->getId() . '::' . config::genKey(16);
        $rendu = array();
        try {
            cache::set($cle, array('foyer' => (int) $this->getId(), 'regle' => $_regle, 'pose' => time()), 600);
            scenarioExpression::createAndExec('code', 'presencium::executerSuite(' . var_export($cle, true) . ');',
                array('background' => 1));
            $resultat = __('confiée au processus séparé', __FILE__);
            $ok = true;
        } catch (Throwable $e) {
            cache::delete($cle);
            $resultat = __('échec', __FILE__) . ' : ' . $e->getMessage();
            $ok = false;
        }
        foreach ($_actions as $action) {
            $nom = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
            if ($nom !== '') {
                $rendu[] = array('cmd' => $nom, 'resultat' => $resultat, 'ok' => $ok, 'differee' => $ok);
            }
        }
        return $rendu;
    }

    /*
     * Le processus séparé : joue la séquence et l'écrit au journal.
     *
     * La simulation est relue ici : elle a pu être activée entre-temps. La
     * garde de réentrance est posée comme dans rafraichirFoyer() : une action
     * qui rappelle un foyer n'y rejoue pas les règles.
     */
    public static function executerSuite($_cle) {
        $cle = (string) $_cle;
        if (strpos($cle, self::CACHE_SUITE) !== 0) {
            return;
        }
        $suite = cache::byKey($cle)->getValue(null);
        cache::delete($cle);
        if (!is_array($suite) || !isset($suite['foyer']) || !isset($suite['regle']) || !is_array($suite['regle'])) {
            log::add(__CLASS__, 'error', __('Suite d\'actions introuvable ou expirée :', __FILE__) . ' ' . $cle);
            return;
        }
        $foyer = self::byId((int) $suite['foyer']);
        if (!is_object($foyer) || $foyer->getEqType_name() !== __CLASS__ || $foyer->type() !== self::TYPE_FOYER) {
            return;
        }
        $regle = $suite['regle'];
        self::$_profondeurFoyer++;
        try {
            $simulation = $foyer->enSimulation($regle);
            $actions = $foyer->executerActions($regle, $simulation, false, true);
            $echecs = 0;
            foreach ($actions as $action) {
                if (empty($action['ok'])) {
                    $echecs++;
                }
            }
            $foyer->conclureRegle(array(
                'genre'       => 'regle',
                'simulation'  => $simulation,
                'essai'       => false,
                'regle'       => isset($regle['id']) ? $regle['id'] : '',
                'nom'         => isset($regle['nom']) ? $regle['nom'] : '',
                'declencheur' => $foyer->libelleDeclencheur($regle),
                'verdict'     => 'declenchee',
                'detail'      => __('suite des actions, jouée dans un processus séparé', __FILE__),
                'conditions'  => array(),
                'actions'     => $actions,
            ), (count($actions) > 0 && $echecs === count($actions)) ? 'echec' : 'declenchee');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $foyer->getHumanName() . ' — '
                   . (isset($regle['nom']) ? $regle['nom'] : '') . ' : ' . $e->getMessage());
        } finally {
            self::$_profondeurFoyer--;
        }
    }

    /* La commande visée par une action : l'identifiant résolu à
     * l'enregistrement d'abord, le nom lisible ensuite. Le nom lisible reste un
     * repli utile — une règle importée d'une autre installation n'a pas
     * d'identifiant qui veuille dire quelque chose. */
    public static function commandeAction($_action) {
        if (self::estMotCle(isset($_action['cmd']) ? $_action['cmd'] : '')) {
            return null;
        }
        if (isset($_action['cmd_id']) && (int) $_action['cmd_id'] > 0) {
            $cmd = cmd::byId((int) $_action['cmd_id']);
            if (is_object($cmd)) {
                return $cmd;
            }
        }
        $id = self::identifiantAction($_action);
        return ($id > 0) ? cmd::byId($id) : null;
    }

    /* Traduit « #[Objet][Équipement][Commande]# » en identifiant numérique, avec
     * la fonction du cœur — celle-là même que scenarioExpression::setExpression
     * applique, pour que ce qui est résolu ici soit exactement ce qui sera
     * exécuté là-bas. */
    public static function identifiantAction($_action) {
        $nom = trim(isset($_action['cmd']) ? (string) $_action['cmd'] : '');
        if ($nom === '' || self::estMotCle($nom)) {
            return 0;
        }
        /* Vérifié dans le cœur : cmd::humanReadableToCmd ne reconnaît un nom
         * lisible qu'entre dièses — « [Objet][Équipement][Commande] » nu, qui
         * est exactement ce que rend cmd::getHumanName(), n'est pas traduit et
         * l'action serait déclarée morte à tort dans la page Santé. On remet
         * donc les dièses quand ils manquent. */
        if (substr($nom, 0, 1) === '[' && substr($nom, -1) === ']') {
            $nom = '#' . $nom . '#';
        }
        $brut = str_replace('#', '', jeedom::fromHumanReadable($nom));
        return is_numeric($brut) ? (int) $brut : 0;
    }

    /*
     * L'action telle qu'enregistrée : `cmd_id` suit le nom lisible. Un nom
     * qui ne se résout plus (objet renommé) ne fait pas perdre un identifiant
     * encore valide : c'est le nom qui est alors rafraîchi depuis la commande.
     * Un mot-clé ou une fonction n'a pas d'identifiant — un ancien laissé là
     * ferait exécuter l'ancienne commande à sa place.
     */
    public static function resoudreAction($_action) {
        $action = is_array($_action) ? $_action : array();
        $nom = trim(isset($action['cmd']) ? (string) $action['cmd'] : '');
        if ($nom === '' || self::estMotCle($nom) || self::estFonction($nom)) {
            $action['cmd_id'] = 0;
            return $action;
        }
        $id = self::identifiantAction($action);
        if ($id > 0 && is_object(cmd::byId($id))) {
            $action['cmd_id'] = $id;
            return $action;
        }
        $ancien = isset($action['cmd_id']) ? (int) $action['cmd_id'] : 0;
        $cmd = ($ancien > 0) ? cmd::byId($ancien) : null;
        if (is_object($cmd)) {
            $action['cmd_id'] = $ancien;
            $action['cmd'] = '#' . $cmd->getHumanName() . '#';
            return $action;
        }
        $action['cmd_id'] = $id;
        return $action;
    }

    private function enRepos($_regle, $_maintenant) {
        $repos = (int) $_regle['repos'];
        if ($repos <= 0) {
            return false;
        }
        $dernier = (int) cache::byKey(self::CACHE_REPOS . $this->getId() . '::' . $_regle['id'])->getValue(0);
        return ($dernier > 0 && ($_maintenant - $dernier) < $repos * 60);
    }

    /*
     * Les attentes en cours.
     *
     * Une attente est annulée si son déclencheur s'inverse : c'est ce qui fait
     * la différence entre « arme cinq minutes après le départ » et « arme cinq
     * minutes après le départ, sauf si quelqu'un revient entre-temps » — la
     * seconde formulation est la seule qui soit utilisable, et c'est celle-ci.
     */
    public function traiterAttentes($_maintenant, $_instantane) {
        $rendus = array();
        foreach ($this->regles() as $regle) {
            /*
             * Un try/catch PAR RÈGLE, comme dans jouerRegles().
             *
             * Sans lui, une seule attente en échec remontait jusqu'à
             * rafraichirFoyer() et privait le foyer de jouerRegles() pour ce
             * passage. Les arrivées et les départs de cette minute-là étaient
             * alors perdus — ce qui, pour un départ, veut dire une alarme qui
             * ne s'arme jamais, sans la moindre erreur ailleurs que dans une
             * ligne du journal de Jeedom.
             */
            try {
                $cle = self::CACHE_ATTENTE . $this->getId() . '::' . $regle['id'];
                $attente = cache::byKey($cle)->getValue(null);
                if (!is_array($attente) || !isset($attente['echeance'])) {
                    continue;
                }
                $transition = isset($attente['transition']) ? $attente['transition'] : array();

                if ($this->declencheurInverse($regle, $transition, $_instantane)) {
                    cache::delete($cle);
                    $rendus[] = $this->conclureRegle(array(
                        'genre'       => 'regle',
                        'simulation'  => $this->enSimulation($regle),
                        'essai'       => false,
                        'regle'       => $regle['id'],
                        'nom'         => $regle['nom'],
                        'declencheur' => $this->libelleDeclencheur($regle),
                        'verdict'     => 'attente_annulee',
                        'detail'      => __('le déclencheur s\'est inversé pendant l\'attente', __FILE__)
                                       . ' — ' . $this->detailPresence($_maintenant, $_instantane),
                        'conditions'  => array(),
                        'actions'     => array(),
                    ), 'attente_annulee');
                    continue;
                }

                if ($_maintenant < (int) $attente['echeance']) {
                    continue;
                }
                /* Effacée AVANT d'agir : si une action lève, l'attente ne doit
                 * pas se rejouer à chaque minute jusqu'à la fin des temps. */
                cache::delete($cle);
                $rendus[] = $this->executerRegle($regle, $_maintenant, array(
                    'transition'       => $transition,
                    'instantane'       => $_instantane,
                    'attente_terminee' => true,
                ));
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $this->getHumanName() . ' — ' . $regle['nom'] . ' : ' . $e->getMessage());
            }
        }
        return $rendus;
    }

    /* Le déclencheur d'une attente s'est-il inversé ?
     *
     * Une attente d'arrivée est annulée par un départ, et réciproquement. Pour
     * les déclencheurs qui nomment quelqu'un, c'est l'état de cette personne
     * précise qui compte ; pour les autres, celui du foyer. */
    private function declencheurInverse($_regle, $_transition, $_instantane) {
        $presents = $_instantane['presents'];
        $nombre = count($presents);
        $personne = (isset($_transition['personne']) && (int) $_transition['personne'] > 0)
                  ? (int) $_transition['personne'] : 0;

        switch ($_regle['declencheur']) {
            case 'arrivee_premier':
            case 'occupee_depuis':
                return ($nombre === 0);
            case 'depart_dernier':
            case 'vide_depuis':
                return ($nombre > 0);
            case 'arrivee_tous':
                return ($_instantane['total'] <= 0 || $nombre < (int) $_instantane['total']);
            case 'arrivee':
                return ($personne > 0) ? !isset($presents[$personne]) : ($nombre === 0);
            case 'depart':
                return ($personne > 0) ? isset($presents[$personne]) : ($nombre >= (int) $_instantane['total'] && $_instantane['total'] > 0);
        }
        return false;
    }

    /* Les noms des personnes du foyer, indexés par identifiant : ce que
     * presenciumRegles::libelleDeclencheur attend pour écrire « Untel arrive »
     * plutôt que « arrivee personne 42 ». */
    public function nomsPersonnes() {
        $noms = array();
        foreach ($this->personnes() as $personne) {
            $noms[(int) $personne->getId()] = $personne->getName();
        }
        return $noms;
    }

    /* Le libellé du déclencheur ; les noms ne sont chargés que si la règle
     * nomme quelqu'un. */
    private function libelleDeclencheur($_regle) {
        $nominative = (isset($_regle['personne']) && (int) $_regle['personne'] > 0);
        return presenciumRegles::libelleDeclencheur($_regle, $nominative ? $this->nomsPersonnes() : array());
    }

    /*
     * La phrase qui répond à « pourquoi ça s'est déclenché ? » six semaines plus
     * tard : qui était là, qui ne l'était pas, et depuis quand.
     *
     * $_instantane, quand il est fourni, est celui SUR LEQUEL la décision a été
     * prise, et c'est lui qui dit qui était présent. Il était reçu et ignoré :
     * la phrase recalculait les présences pour son compte, et une personne dont
     * le délai de départ expirait entre les deux calculs était annoncée absente
     * dans l'explication d'un déclenchement décidé alors qu'elle était encore
     * là. Le journal racontait une autre histoire que celle qui a eu lieu —
     * précisément ce qu'on vient y chercher.
     *
     * Les durées, elles, ne peuvent venir que du verdict : l'instantané ne
     * porte que des noms.
     */
    public function detailPresence($_maintenant, $_instantane = null) {
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $presents = (is_array($_instantane) && isset($_instantane['presents']) && is_array($_instantane['presents']))
                  ? $_instantane['presents'] : null;
        $morceaux = array();
        foreach ($this->personnes() as $personne) {
            try {
                $verdict = $personne->verdictPersonne($maintenant);
            } catch (Throwable $e) {
                continue;
            }
            $present = ($presents === null)
                     ? !empty($verdict['present'])
                     : isset($presents[(int) $personne->getId()]);

            /*
             * DEUX DURÉES, ET ELLES NE DISENT PAS LA MÊME CHOSE.
             *
             * « depuis » se rapporte à l'état annoncé juste avant — présent ou
             * absent —, donc à la présence STABILISÉE, exactement comme la
             * commande « Depuis » : c'est l'instant du dernier changement
             * confirmé, celui que rafraichirPersonne() a calculé à la fin du
             * délai et non au passage du cron qui s'en aperçoit.
             *
             * L'âge du signal brut était affiché à sa place. Les deux coïncident
             * à l'arrivée, le délai d'arrivée valant zéro, et divergent de tout
             * le délai de départ aux départs : le journal annonçait « absent
             * depuis 5 min » à la seconde même où le départ venait d'être
             * confirmé, c'est-à-dire un état vieux de zéro seconde. Or c'est ce
             * fichier-là qu'on relit pour régler le délai, et un chiffre qui
             * vaut le délai lui-même est le pire endroit où se tromper.
             *
             * L'âge du signal reste affiché à côté quand il diffère, parce que
             * c'est l'information qu'on vient chercher : elle dit depuis combien
             * de temps la balise se tait, donc où en est la confirmation en
             * cours. Deux nombres nommés valent mieux qu'un seul ambigu.
             */
            $memoire = cache::byKey(self::CACHE_PRESENCE . (int) $personne->getId())->getValue(null);
            $stable = (is_array($memoire) && isset($memoire['depuis'])) ? (int) $memoire['depuis'] : 0;
            $signal = isset($verdict['signal_depuis']) ? (int) $verdict['signal_depuis'] : 0;
            /* Cache vide — installation neuve, cache purgé : on retombe sur la
             * date du signal, qui est au moins vraie, plutôt que de n'afficher
             * aucune durée. */
            if ($stable <= 0 || $stable > $maintenant) {
                $stable = $signal;
            }

            $texte = $personne->getName() . ' '
                   . ($present ? __('présent(e)', __FILE__) : __('absent(e)', __FILE__))
                   . (($stable > 0) ? ' ' . __('depuis', __FILE__) . ' ' . self::duree($maintenant - $stable) : '');

            /* Une minute d'écart : en deçà, les deux durées racontent le même
             * événement à la latence du cron près, et la précision ne ferait
             * qu'allonger la ligne. */
            if ($signal > 0 && abs($stable - $signal) >= 60) {
                $texte .= ' (' . sprintf(
                    empty($verdict['brut'])
                        ? __('signal perdu il y a %s', __FILE__)
                        : __('signal revenu il y a %s', __FILE__),
                    self::duree($maintenant - $signal)) . ')';
            }
            $morceaux[] = $texte;
        }
        if (count($morceaux) === 0) {
            return __('aucune personne dans ce foyer', __FILE__);
        }
        return implode(', ', $morceaux);
    }

    /* ================================================================= ALARME */

    /*
     * Arme ou désarme l'alarme portée par le foyer.
     *
     * En simulation, l'état ne bouge pas : armer EST une action, et le contrat
     * du mode simulation est qu'aucune action ne part. Ce qui aurait été fait
     * est écrit au journal, avec la mention — c'est ce qu'on relit pour décider
     * si l'on peut enfin sortir de simulation.
     */
    public function armer($_arme) {
        $arme = $_arme ? 1 : 0;
        $libelle = $arme ? __('armement', __FILE__) : __('désarmement', __FILE__);

        /* Relu AVANT de décider, et pas seulement avant d'écrire : le refus
         * d'armer une alarme hors service se lit sur l'état courant, et cet
         * état vient peut-être d'être changé à la main pendant que le cron
         * tournait. Voir objetFrais(). */
        $frais = $this->objetFrais();

        if ($frais->enSimulation()) {
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => true, 'verdict' => 'declenchee',
                'detail' => sprintf(__('%s simulé : l\'état de l\'alarme n\'a pas changé', __FILE__), ucfirst($libelle)),
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle . ' ' . __('(simulation)', __FILE__));
            return ($frais->getConfiguration('etat_armee', 0) == 1);
        }

        /* Armer une alarme hors service n'a pas de sens, et le faire en silence
         * laisserait croire que la maison est protégée. */
        if ($arme === 1 && $frais->getConfiguration('etat_en_service', 0) != 1) {
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => false, 'verdict' => 'echec',
                'detail' => __('armement refusé : l\'alarme est hors service', __FILE__),
            ));
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                   . __('armement refusé, l\'alarme est hors service', __FILE__));
            return false;
        }

        if ((int) $frais->getConfiguration('etat_armee', 0) !== $arme) {
            $frais->setConfiguration('etat_armee', $arme);
            /* Écriture directe sur l'objet relu : voir forcerPersonne(). */
            $frais->save(true);
            $this->reporterConfiguration($frais, array('etat_armee', 'etat_en_service'));
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => false, 'verdict' => 'declenchee',
                'detail' => ucfirst($libelle) . ' — ' . $this->detailPresence(time()),
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle);
        }
        $this->checkAndUpdateCmd('armee', $arme);
        return ($arme === 1);
    }

    /*
     * Met l'alarme en service, ou l'en retire.
     *
     * Mettre hors service désarme : une alarme hors service et armée est un
     * état que personne ne sait lire, et c'est celui dans lequel on découvre
     * que l'alarme n'a pas sonné.
     */
    public function mettreEnService($_actif) {
        $actif = $_actif ? 1 : 0;
        $libelle = $actif ? __('mise en service', __FILE__) : __('mise hors service', __FILE__);

        /* Relu avant de comparer et d'écrire : voir objetFrais(). Une mise hors
         * service décidée par le cron sur un objet vieux d'une minute réécrirait
         * les règles que l'utilisateur vient d'enregistrer. */
        $frais = $this->objetFrais();

        if ($frais->enSimulation()) {
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => true, 'verdict' => 'declenchee',
                'detail' => sprintf(__('%s simulée : l\'état de l\'alarme n\'a pas changé', __FILE__), ucfirst($libelle)),
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle . ' ' . __('(simulation)', __FILE__));
            return ($frais->getConfiguration('etat_en_service', 0) == 1);
        }

        if ((int) $frais->getConfiguration('etat_en_service', 0) !== $actif) {
            $frais->setConfiguration('etat_en_service', $actif);
            if ($actif === 0) {
                $frais->setConfiguration('etat_armee', 0);
            }
            /* Écriture directe sur l'objet relu : voir forcerPersonne(). */
            $frais->save(true);
            $this->reporterConfiguration($frais, array('etat_en_service', 'etat_armee'));
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => false, 'verdict' => 'declenchee',
                'detail' => ucfirst($libelle),
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle);
        }
        $this->checkAndUpdateCmd('en_service', $actif);
        $this->checkAndUpdateCmd('armee', ($frais->getConfiguration('etat_armee', 0) == 1) ? 1 : 0);
        return ($actif === 1);
    }

    /* ============================================================= SIMULATION */

    /*
     * Vrai si l'un des trois l'est : la configuration globale du plugin, le
     * foyer, ou la règle en cours.
     *
     * Trois niveaux et non un seul, parce qu'ils répondent à trois questions
     * différentes : « je viens d'installer, je ne veux rien qui parte »,
     * « ce foyer-ci n'est pas encore réglé », « cette règle-là me fait peur ».
     */
    public function enSimulation($_regle = null) {
        if ((int) self::reglageGlobal('simulation', 0) === 1) {
            return true;
        }
        if ((int) $this->getConfiguration('simulation', 0) === 1) {
            return true;
        }
        if (is_array($_regle) && isset($_regle['simulation']) && (int) $_regle['simulation'] === 1) {
            return true;
        }
        return false;
    }

    /*
     * Entre ou sort de la simulation.
     *
     * Volontairement NON soumise à enSimulation() : une bascule qui serait
     * elle-même simulée enfermerait l'utilisateur dans le mode simulation sans
     * aucun moyen d'en sortir par l'interface.
     *
     * La simulation globale, elle, ne se désarme pas d'ici : elle vit dans la
     * configuration du plugin. On le dit au lieu de laisser croire que c'est
     * fait.
     */
    public function basculerSimulation($_actif) {
        $actif = $_actif ? 1 : 0;
        /* Relu avant de comparer et d'écrire : voir objetFrais(). Sortir de la
         * simulation depuis un scénario ne doit pas ramener avec soi les règles
         * telles qu'elles étaient au chargement de l'objet — c'est justement au
         * sortir de la simulation que l'on vient de les corriger. */
        $frais = $this->objetFrais();
        if ((int) $frais->getConfiguration('simulation', 0) !== $actif) {
            $frais->setConfiguration('simulation', $actif);
            /* Écriture directe sur l'objet relu : voir forcerPersonne(). */
            $frais->save(true);
            $this->reporterConfiguration($frais, array('simulation'));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
                   . ($actif === 1 ? __('mode simulation activé', __FILE__) : __('mode simulation arrêté', __FILE__)));
        }
        if ($actif === 0 && (int) self::reglageGlobal('simulation', 0) === 1) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                   . __('la simulation globale du plugin reste active, rien ne sera exécuté.', __FILE__));
        }
        /* L'état rendu et publié se lit sur l'objet relu : c'est lui qui porte
         * ce qui vient d'être écrit en base. */
        $this->checkAndUpdateCmd('simulation', $frais->enSimulation() ? 1 : 0);
        return $frais->enSimulation();
    }

    /* ================================================================ JOURNAL */

    public function cheminJournal() {
        return self::dossierDonnees() . '/journal-' . (int) $this->getId() . '.json';
    }

    /* data/ n'est jamais écrasé par un déploiement ni par une mise à jour du
     * plugin : c'est la seule raison pour laquelle le journal y vit plutôt que
     * dans un fichier posé à côté de la classe. */
    public static function dossierDonnees() {
        return realpath(__DIR__ . '/../..') . '/data';
    }

    public static function journalTaille() {
        $taille = (int) self::reglageGlobal('journal_taille', self::JOURNAL_TAILLE_DEFAUT);
        return max(self::JOURNAL_TAILLE_MIN, min(self::JOURNAL_TAILLE_MAX, $taille));
    }

    /*
     * Ajoute une entrée en tête du ring buffer.
     *
     * L'écriture est ATOMIQUE — fichier temporaire puis rename() — et ce n'est
     * pas de la précaution gratuite : le journal est réécrit en entier à chaque
     * entrée, et un cron tué au milieu d'un file_put_contents laisserait un
     * JSON tronqué, c'est-à-dire un journal ENTIÈREMENT perdu à la lecture
     * suivante. Le fichier temporaire est créé dans le même dossier pour que le
     * rename reste un renommage à l'intérieur d'un même système de fichiers,
     * seule condition sous laquelle il est atomique. Le PID le rend propre à ce
     * processus : le cron et le listener écrivent en parallèle.
     *
     * Rien de ce qui se passe ici ne doit interrompre une décision : écrire
     * l'histoire est moins important que la faire.
     */
    public function journalAjouter($_entree) {
        try {
            /* Le journal n'est plus réservé aux foyers. Une personne qui
             * n'appartient à aucun foyer voyait ses rebonds absorbés partir
             * dans le vide : la campagne de simulation tournait et
             * n'enregistrait rien, alors que c'est précisément ce qu'on lui
             * demande de montrer. */
            if ((int) $this->getId() <= 0) {
                return false;
            }
            $entree = is_array($_entree) ? $_entree : array('detail' => (string) $_entree);
            $entree['ts'] = isset($entree['ts']) ? (int) $entree['ts'] : time();
            $entree['date'] = date('Y-m-d H:i:s', $entree['ts']);
            if (!isset($entree['genre'])) {
                $entree['genre'] = 'regle';
            }
            $entree['simulation'] = !empty($entree['simulation']);

            /* Lire, ajouter, réécrire : sans verrou, le cron et l'écouteur —
             * qui tournent dans deux processus — lisent la même liste, y
             * ajoutent chacun son entrée, et le second enregistrement écrase
             * le premier. L'entrée perdue est justement celle qu'on vient
             * relire pour comprendre un faux positif. */
            $verrou = $this->journalVerrou();
            try {
                $entrees = $this->journalLire(self::journalTaille());
                array_unshift($entrees, $entree);
                $entrees = array_slice($entrees, 0, self::journalTaille());

                return $this->journalEcrire($entrees);
            } finally {
                $this->journalLibererVerrou($verrou);
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Journal :', __FILE__) . ' ' . $e->getMessage());
            return false;
        }
    }

    /* Le verrou d'écriture du journal, sur son propre fichier. Il ne peut pas
     * être celui du foyer : flock() ne se reprend pas dans un même processus —
     * deux fopen() donnent deux poignées, donc deux verrous — et le journal est
     * écrit DEPUIS la section que le verrou de foyer protège. Un seul fichier
     * pour les deux, et le foyer s'attendrait lui-même indéfiniment. */
    private function journalVerrou() {
        return self::verrouFichier($this->cheminJournal() . '.lock');
    }

    private function journalLibererVerrou($_poignee) {
        self::libererVerrou($_poignee);
    }

    /* Le fichier de verrou d'un foyer : un par équipement, pour que deux foyers
     * ne s'attendent pas l'un l'autre sans raison. */
    public function cheminVerrouFoyer() {
        return self::dossierDonnees() . '/foyer-' . (int) $this->getId() . '.lock';
    }

    /*
     * Un verrou exclusif sur un fichier de data/.
     *
     * Un échec de verrouillage rend null et laisse le travail se faire quand
     * même : c'est exactement ce qui se passait avant que le verrou existe. Une
     * installation où flock n'aboutit pas — data/ sur NFS, dossier appartenant
     * à un autre compte — doit continuer de suivre les présences et d'armer
     * l'alarme, avec le risque de collision qu'elle avait déjà, plutôt que de
     * s'arrêter de décider sans que rien ne l'explique.
     */
    private static function verrouFichier($_chemin, $_attendre = true) {
        $dossier = dirname($_chemin);
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return null;
        }
        $poignee = @fopen($_chemin, 'c');
        if ($poignee === false) {
            return null;
        }
        $occupe = 0;
        if (!@flock($poignee, $_attendre ? LOCK_EX : (LOCK_EX | LOCK_NB), $occupe)) {
            @fclose($poignee);
            /* Trois issues et non deux : une poignée, `false` quand le verrou
             * est DÉJÀ TENU par un autre processus, et null quand le
             * verrouillage lui-même n'aboutit pas. L'appelant n'en tire pas la
             * même conclusion — renoncer parce qu'un autre fait déjà le travail
             * n'a rien à voir avec renoncer parce que flock ne marche pas. */
            return ($occupe) ? false : null;
        }
        /* Le cron tourne en www-data, le déploiement sous un autre compte :
         * sans ce chmod, le verrou posé par l'un devient inouvrable par
         * l'autre et la protection disparaît en silence. */
        @chmod($_chemin, 0664);
        return $poignee;
    }

    private static function libererVerrou($_poignee) {
        if (!is_resource($_poignee)) {
            return;
        }
        @flock($_poignee, LOCK_UN);
        @fclose($_poignee);
    }

    private function journalEcrire($_entrees) {
        $chemin = $this->cheminJournal();
        $dossier = dirname($chemin);
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return $this->journalEchec($dossier);
        }
        $json = json_encode(array_values($_entrees), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $this->journalEchec($dossier);
        }
        $temporaire = $chemin . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporaire, $json) === false) {
            @unlink($temporaire);
            return $this->journalEchec($dossier);
        }
        if (!@rename($temporaire, $chemin)) {
            @unlink($temporaire);
            return $this->journalEchec($dossier);
        }
        /* Le cron tourne en www-data, le déploiement se fait sous un autre
         * compte : sans ce chmod, un journal écrit par l'un devient illisible
         * pour l'autre et la page affiche un journal vide. */
        @chmod($chemin, 0664);
        return true;
    }

    /* Un journal qui n'arrive pas à s'écrire est la pire des pannes de ce
     * plugin : le mode simulation continue de tourner, l'utilisateur relit son
     * journal au bout de trois jours, il est vide, et il en conclut qu'aucune
     * règle ne s'est déclenchée. Le cas le plus fréquent est un dossier de
     * données appartenant à un autre compte que celui du cron. On le dit une
     * fois par heure dans le journal du plugin, et la page Santé le répète. */
    private function journalEchec($_dossier) {
        $cle = self::CACHE_JOURNAL_ECHEC . $this->getId();
        if (cache::byKey($cle)->getValue('') === '') {
            cache::set($cle, '1', 3600);
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                . sprintf(__('journal non enregistrable dans %s — vérifiez que le dossier appartient à www-data.', __FILE__), $_dossier));
        }
        return false;
    }

    /* La plus récente en tête. Un fichier illisible — tronqué par un disque
     * plein, édité à la main — rend un journal vide plutôt qu'une exception :
     * le journal est une commodité, il ne doit jamais empêcher d'ouvrir la
     * page de l'équipement. */
    public function journalLire($_limite = 200, $_genre = '') {
        try {
            $chemin = $this->cheminJournal();
            if (!file_exists($chemin)) {
                return array();
            }
            $entrees = json_decode(@file_get_contents($chemin), true);
            if (!is_array($entrees)) {
                return array();
            }
            if ($_genre !== '' && $_genre !== 'tout') {
                $filtrees = array();
                foreach ($entrees as $entree) {
                    if (isset($entree['genre']) && $entree['genre'] === $_genre) {
                        $filtrees[] = $entree;
                    }
                }
                $entrees = $filtrees;
            }
            $limite = max(1, min(self::JOURNAL_TAILLE_MAX, (int) $_limite));
            return array_slice(array_values($entrees), 0, $limite);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Journal illisible :', __FILE__) . ' ' . $e->getMessage());
            return array();
        }
    }

    public function journalVider() {
        $chemin = $this->cheminJournal();
        if (file_exists($chemin)) {
            @unlink($chemin);
        }
        return true;
    }

    public function journalTronquer() {
        $entrees = $this->journalLire(self::journalTaille());
        if (count($entrees) === 0) {
            return true;
        }
        return $this->journalEcrire($entrees);
    }

    /* ============================================================== ANALYSE */

    /*
     * Relit l'historique d'une commande de présence et rejoue la décision pour
     * plusieurs délais de départ. C'est l'analyse qu'on ferait à la main, en
     * relevant les absences une à une dans les graphiques — sauf qu'elle se
     * fait sur les données de CETTE balise, et pas sur une moyenne.
     *
     * Le raisonnement tient en une phrase : une absence brute courte est
     * presque toujours un décrochage, une absence longue est presque toujours
     * un vrai départ, et le bon délai est le plus petit qui sépare les deux.
     * Le seuil qui définit « longue » est un réglage, pas une vérité : sur une
     * maison où l'on sort faire une course de vingt minutes, il doit descendre.
     *
     * La décision est rejouée par presenciumPersonne::evaluer(), la même
     * fonction que le cron appelle. L'analyse ne peut donc pas diverger de ce
     * que le plugin fera vraiment.
     */
    public static function analyserSource($_cmdId, $_jours = 7, $_valeurPresente = '1', $_seuilVrai = 60) {
        $cmd = cmd::byId((int) $_cmdId);
        if (!is_object($cmd)) {
            throw new Exception(__('Commande introuvable.', __FILE__));
        }
        if ($cmd->getType() !== 'info') {
            throw new Exception(__('Ce n\'est pas une commande d\'information.', __FILE__));
        }
        if ($cmd->getIsHistorized() != 1) {
            throw new Exception(__('Cette commande n\'est pas historisée : sans historique, il n\'y a rien à analyser. Activez l\'historisation, puis revenez dans quelques jours.', __FILE__));
        }

        $jours = max(1, min(90, (int) $_jours));
        $seuilVrai = max(5, min(720, (int) $_seuilVrai));
        $fin = time();
        $debut = $fin - ($jours * 86400);

        $points = $cmd->getHistory(date('Y-m-d H:i:s', $debut), date('Y-m-d H:i:s', $fin));
        if (!is_array($points) || count($points) === 0) {
            throw new Exception(__('Aucun historique sur cette période.', __FILE__));
        }

        /* Les transitions, et elles seules : l'historique d'une balise répète
         * la même valeur toutes les cinq minutes, et compter ces répétitions
         * comme des événements fausserait tout. */
        $transitions = array();
        $precedent = null;
        foreach ($points as $point) {
            $ts = strtotime($point->getDatetime());
            $brut = presenciumPersonne::estPresentBrut($point->getValue(), $_valeurPresente) ? 1 : 0;
            if ($ts <= 0) {
                continue;
            }
            if ($precedent === null || $brut !== $precedent) {
                $transitions[] = array('ts' => $ts, 'brut' => $brut);
                $precedent = $brut;
            }
        }
        if (count($transitions) === 0) {
            throw new Exception(__('Aucun changement d\'état sur cette période.', __FILE__));
        }

        /* Les épisodes d'absence brute, fermés par le retour du signal ou par
         * la fin de la période. Un épisode encore ouvert est écarté : sa durée
         * n'est pas connue, et l'inclure fausserait le compte dans le sens le
         * plus dangereux — celui qui fait croire à un vrai départ. */
        $episodes = array();
        for ($i = 0; $i < count($transitions); $i++) {
            if ($transitions[$i]['brut'] !== 0) {
                continue;
            }
            if (!isset($transitions[$i + 1])) {
                continue;
            }
            /*
             * LA SECONDE FAIT FOI, LA MINUTE N'EST QUE POUR L'AFFICHAGE.
             *
             * L'épisode n'était gardé qu'en minutes arrondies, et comparé au
             * délai par un « > » strict. Le moteur, lui, décide en secondes et
             * avec un « >= » (presenciumPersonne::evaluer). Les deux ne
             * disaient donc pas la même chose : un creux de 15 min 12 s
             * devenait « 15 », 15 > 15 était faux, et le tableau annonçait
             * « aucune fausse absence » pour un délai de 15 minutes que le
             * plugin, lui, aurait bel et bien franchi à la 900e seconde.
             *
             * Le cas s'est produit ici : une balise s'est tue 15 min 12 s, le
             * départ a été déclaré, la maison est devenue vide et la règle
             * d'armement est partie — sur quelqu'un qui n'avait pas bougé.
             * L'analyse aurait recommandé le délai qui laisse passer
             * exactement ce creux-là. C'est le seul chiffre que cet écran sert
             * à choisir : il doit être décidé par la règle du moteur, et par
             * aucune autre.
             */
            $secondes = (int) ($transitions[$i + 1]['ts'] - $transitions[$i]['ts']);
            $episodes[] = array(
                'debut'    => $transitions[$i]['ts'],
                'secondes' => $secondes,
                'minutes'  => (int) round($secondes / 60),
            );
        }

        $courtes = array();
        $longues = array();
        foreach ($episodes as $episode) {
            if ($episode['secondes'] >= $seuilVrai * 60) {
                $longues[] = $episode;
            } else {
                $courtes[] = $episode;
            }
        }

        $lignes = array();
        foreach (array(0, 5, 10, 15, 20, 30, 45, 60) as $delai) {
            $declares = 0;
            $courtesPassees = 0;
            $longuesVues = 0;
            $tenuPresent = 0;
            foreach ($episodes as $episode) {
                /* La comparaison du moteur, mot pour mot : « écoulé >= délai »,
                 * en secondes. Le temps « tenu présent à tort » est ce que le
                 * délai a mangé, épisode par épisode. */
                $tenuPresent += min($episode['secondes'], $delai * 60);
                if ($episode['secondes'] >= $delai * 60) {
                    $declares++;
                    if ($episode['secondes'] >= $seuilVrai * 60) {
                        $longuesVues++;
                    } else {
                        $courtesPassees++;
                    }
                }
            }
            $lignes[] = array(
                'delai'        => $delai,
                'departs'      => $declares,
                'faux'         => $courtesPassees,
                'vrais'        => $longuesVues,
                'vrais_total'  => count($longues),
                'tenu_present' => (int) round($tenuPresent / 60),
            );
        }

        /* Le délai recommandé : le plus petit de la liste qui ne laisse passer
         * aucune absence courte sans perdre une seule absence longue. Aucun ne
         * convient — c'est le cas d'une balise dont les décrochages durent plus
         * longtemps qu'une vraie sortie — et on le dit plutôt que d'en désigner
         * un au hasard. */
        $recommande = null;
        foreach ($lignes as $ligne) {
            if ($ligne['faux'] === 0 && $ligne['vrais'] === count($longues)) {
                $recommande = $ligne['delai'];
                break;
            }
        }

        return array(
            'commande'    => $cmd->getHumanName(),
            'jours'       => $jours,
            'seuil_vrai'  => $seuilVrai,
            'debut'       => date('Y-m-d H:i', $debut),
            'fin'         => date('Y-m-d H:i', $fin),
            'heures'      => (int) round(($fin - $debut) / 3600),
            'points'      => count($points),
            'transitions' => count($transitions),
            'episodes'    => count($episodes),
            'courtes'     => count($courtes),
            'longues'     => count($longues),
            /* Arrondis dans le sens qui ne ment pas : le plus long creux vers le
             * haut, la plus courte vraie absence vers le bas. Un creux de
             * 15 min 12 s affiché « 15 min » à côté d'un délai recommandé de
             * 20 min ferait passer la recommandation pour de la prudence
             * gratuite, alors qu'elle est le premier délai qui le couvre. */
            'plus_longue_courte' => (count($courtes) > 0) ? (int) ceil(max(array_column($courtes, 'secondes')) / 60) : 0,
            'plus_courte_longue' => (count($longues) > 0) ? (int) floor(min(array_column($longues, 'secondes')) / 60) : 0,
            'lignes'      => $lignes,
            'recommande'  => $recommande,
        );
    }

    /* ============================================================= DÉCOUVERTE */

    /*
     * Les commandes qui peuvent servir de signal de présence.
     *
     * Le type générique PRESENCE d'abord, parce que c'est la déclaration
     * explicite du plugin qui l'expose. Le nom ensuite, parce que la moitié des
     * plugins ne renseignent pas les types génériques : une commande binaire
     * appelée « Présence » ou « Home » est un candidat évident qu'il serait
     * absurde de cacher sous prétexte qu'un autre développeur a omis un champ.
     */
    public static function sourcesCandidates() {
        $candidates = array();
        $vues = array();

        $ajouter = function ($_cmd) use (&$candidates, &$vues) {
            $id = (int) $_cmd->getId();
            if (isset($vues[$id]) || $_cmd->getType() !== 'info') {
                return;
            }
            $vues[$id] = true;
            $eqLogic = $_cmd->getEqLogic();
            if (!is_object($eqLogic)) {
                return;
            }
            $objet = $eqLogic->getObject();
            $candidates[] = array(
                'id'      => $id,
                'nom'     => $eqLogic->getName() . ' — ' . $_cmd->getName(),
                'objet'   => is_object($objet) ? $objet->getName() : '',
                'generic' => (string) $_cmd->getGeneric_type(),
                'valeur'  => $_cmd->execCmd(),
            );
        };

        foreach (cmd::byGenericType('PRESENCE') as $cmd) {
            $ajouter($cmd);
        }
        foreach (cmd::byTypeSubType('info', 'binary') as $cmd) {
            if (!preg_match('/pr[ée]sen|occupa|occup[ée]|home|away|arriv|absen/iu', $cmd->getName() . ' ' . $cmd->getLogicalId())) {
                continue;
            }
            $ajouter($cmd);
        }

        usort($candidates, function ($_a, $_b) {
            $objet = strcasecmp($_a['objet'], $_b['objet']);
            return ($objet !== 0) ? $objet : strcasecmp($_a['nom'], $_b['nom']);
        });
        return $candidates;
    }

    /* ================================================================== SANTÉ */

    /*
     * Page Santé du cœur. Statique ET publique : le cœur l'appelle sur la
     * classe, et l'Error d'un appel statique sur une méthode d'instance n'est
     * pas rattrapée par son catch (Exception) — c'est toute la page qui tombe.
     *
     * Tout ce qui est contrôlé ici a la même signature : ça casse en SILENCE.
     * Une personne sans source reste éternellement absente, une règle qui
     * pointe une commande supprimée ne fait rien et ne dit rien, un écouteur
     * perdu se traduit juste par des arrivées vues une minute trop tard.
     * Aucune de ces pannes ne produit d'erreur : elles ne se voient qu'ici.
     */
    public static function health() {
        $sante = array();

        $simulationGlobale = ((int) self::reglageGlobal('simulation', 0) === 1);
        $personnes = 0;
        $foyers = 0;
        $sansSource = array();
        $sourceMorte = array();
        $sourceNonInfo = array();
        $sourcesPerdues = array();
        $sansEcouteur = array();
        $bloquees = array();
        $muettes = array();
        $orphelines = array();
        $foyersVides = array();
        $foyersSimules = array();
        $reglesMortes = array();
        $maintenant = time();

        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->type() === self::TYPE_PERSONNE) {
                $personnes++;

                try {
                    /*
                     * Les deux pannes muettes — la balise qui n'émet plus et le
                     * départ qui n'aboutit pas — sont décidées par diagnostic()
                     * et nulle part ailleurs. Le centre de messages pose la
                     * même question toutes les heures : deux calculs pour une
                     * seule question finiraient par répondre différemment, et
                     * ce jour-là on ne saurait plus lequel croire.
                     */
                    $verdict = $eqLogic->verdictPersonne($maintenant);
                    $diagnostic = $eqLogic->diagnostic($maintenant, $verdict);

                    if (!empty($verdict['source_perdue']) && $eqLogic->getIsEnable() == 1) {
                        $sourcesPerdues[] = $eqLogic->getHumanName() . ' ('
                                          . (isset($verdict['source_perdue_raison']) ? $verdict['source_perdue_raison'] : '') . ')';
                    }

                    if ($diagnostic['muette'] > 0) {
                        $muettes[] = $eqLogic->getHumanName() . ' ('
                                   . $diagnostic['muette'] . ' ' . __('min', __FILE__) . ')';
                    }
                    if ($diagnostic['bloquee'] > 0) {
                        $bloquees[] = $eqLogic->getHumanName() . ' ('
                                    . self::duree($diagnostic['bloquee']) . ')';
                    }

                    if (count(self::foyersDe((int) $eqLogic->getId())) === 0) {
                        $orphelines[] = $eqLogic->getHumanName();
                    }
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
                }

                $source = (int) $eqLogic->getConfiguration('source', 0);
                if ($source <= 0) {
                    $sansSource[] = $eqLogic->getHumanName();
                    continue;
                }
                $cmdSource = cmd::byId($source);
                if (!is_object($cmdSource)) {
                    $sourceMorte[] = $eqLogic->getHumanName();
                    continue;
                }
                /* Une commande d'ACTION choisie comme source est traitée comme
                 * une absence de source par verdictPersonne() — cmd::execCmd()
                 * l'exécuterait au lieu d'en rendre la valeur. La personne reste
                 * donc éternellement absente alors que la page montre une source
                 * bien renseignée : sans cette ligne, rien ne permet de le
                 * comprendre. */
                if ($cmdSource->getType() !== 'info') {
                    $sourceNonInfo[] = $eqLogic->getHumanName() . ' — ' . $cmdSource->getHumanName();
                    continue;
                }
                if ($eqLogic->getIsEnable() == 1) {
                    $listener = listener::byClassAndFunction(__CLASS__, 'onSource', array('id' => (int) $eqLogic->getId()));
                    if (!is_object($listener) || !in_array('#' . $source . '#', $listener->getEvent())) {
                        $sansEcouteur[] = $eqLogic->getHumanName();
                    }
                }
                continue;
            }

            $foyers++;
            if (count($eqLogic->personnes()) === 0) {
                $foyersVides[] = $eqLogic->getHumanName();
            }
            if ((int) $eqLogic->getConfiguration('simulation', 0) === 1) {
                $foyersSimules[] = $eqLogic->getHumanName();
            }
            foreach ($eqLogic->regles() as $regle) {
                foreach (is_array($regle['actions']) ? $regle['actions'] : array() as $action) {
                    /* Un mot-clé (wait, variable, scenario…) ou une fonction
                     * utilisateur n'est pas une commande : rien de mort. */
                    $nomAction = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
                    if ($nomAction === '' || self::estMotCle($nomAction) || self::estFonction($nomAction)) {
                        continue;
                    }
                    if (!is_object(self::commandeAction($action))) {
                        $reglesMortes[] = $regle['nom'] . ' — ' . __('action', __FILE__) . ' '
                                        . (isset($action['cmd']) ? $action['cmd'] : '?');
                    }
                }
                $lignes = isset($regle['conditions']['lignes']) ? $regle['conditions']['lignes'] : array();
                foreach (is_array($lignes) ? $lignes : array() as $ligne) {
                    if (!is_array($ligne) || !isset($ligne['cmd'])) {
                        continue;
                    }
                    if (!is_object(cmd::byId((int) $ligne['cmd']))) {
                        $reglesMortes[] = $regle['nom'] . ' — ' . __('condition', __FILE__) . ' '
                                        . ((isset($ligne['nom']) && $ligne['nom'] !== '') ? $ligne['nom'] : '#' . (int) $ligne['cmd'] . '#');
                    }
                }
            }
        }

        /*
         * Le cron du coeur passe-t-il encore ?
         *
         * En tête, parce que cette ligne-là conditionne toutes les autres :
         * sans cron, les délais de départ n'expirent plus, les attentes des
         * règles ne se terminent plus, les déclencheurs de durée ne tombent
         * plus — et rien ne change à l'écran, les commandes gardant leur
         * dernière valeur, qui a toujours l'air juste. Douze lignes de Santé
         * au vert sous un cron arrêté ne veulent rien dire.
         *
         * Cinq minutes de tolérance : le cron du coeur passe chaque minute,
         * mais il est partagé par tous les plugins et prend parfois du retard.
         * En deçà, on signalerait une panne à chaque installation chargée.
         */
        $dernierCron = (int) cache::byKey(self::CACHE_CRON)->getValue(0);
        $cronOk = ($dernierCron > 0 && ($maintenant - $dernierCron) < 300);
        $sante[] = array(
            'test'   => __('Dernière évaluation', __FILE__),
            'result' => ($dernierCron > 0)
                      ? sprintf(__('il y a %s', __FILE__), self::duree($maintenant - $dernierCron))
                      : __('jamais', __FILE__),
            'advice' => $cronOk ? '' : (($dernierCron > 0)
                      ? __('Le cron du coeur ne passe plus : plus aucune décision n\'est prise. Les délais de départ n\'expirent plus, les attentes des règles ne se terminent plus, et les commandes gardent leur dernière valeur — qui a l\'air juste. Regardez Réglages → Système → Moteur de tâches.', __FILE__)
                      : __('Le plugin n\'a encore jamais été évalué. Le cron du coeur passe chaque minute : attendez une minute après l\'installation, puis rechargez cette page. S\'il reste à « jamais », regardez Réglages → Système → Moteur de tâches.', __FILE__)),
            'state'  => $cronOk,
        );
        $sante[] = array(
            'test'   => __('Personnes suivies', __FILE__),
            'result' => $personnes,
            'advice' => ($personnes > 0) ? '' : __('Aucune personne : rien n\'est suivi.', __FILE__),
            'state'  => ($personnes > 0),
        );
        $sante[] = array(
            'test'   => __('Foyers', __FILE__),
            'result' => $foyers,
            'advice' => ($foyers > 0) ? '' : __('Aucun foyer : aucune règle ne peut se déclencher.', __FILE__),
            'state'  => ($foyers > 0),
        );
        $sante[] = array(
            'test'   => __('Personnes sans source', __FILE__),
            'result' => (count($sansSource) === 0) ? __('aucune', __FILE__) : implode(', ', $sansSource),
            'advice' => (count($sansSource) === 0) ? '' : __('Sans commande source, ces personnes restent indéfiniment absentes.', __FILE__),
            'state'  => (count($sansSource) === 0),
        );
        $sante[] = array(
            'test'   => __('Sources disparues', __FILE__),
            'result' => (count($sourceMorte) === 0) ? __('aucune', __FILE__) : implode(', ', $sourceMorte),
            'advice' => (count($sourceMorte) === 0) ? '' : __('La commande choisie n\'existe plus : ces personnes restent figées sur leur dernier état connu. Rouvrez la personne et choisissez-en une autre.', __FILE__),
            'state'  => (count($sourceMorte) === 0),
        );
        $sante[] = array(
            'test'   => __('Sources qui ne sont pas des commandes d\'information', __FILE__),
            'result' => (count($sourceNonInfo) === 0) ? __('aucune', __FILE__) : implode(', ', $sourceNonInfo),
            'advice' => (count($sourceNonInfo) === 0) ? '' : __('Une commande d\'action ne porte pas de valeur : ces personnes restent figées sur leur dernier état connu. Choisissez une commande d\'information.', __FILE__),
            'state'  => (count($sourceNonInfo) === 0),
        );
        $sante[] = array(
            'test'   => __('Sources perdues', __FILE__),
            'result' => (count($sourcesPerdues) === 0) ? __('aucune', __FILE__) : implode(', ', $sourcesPerdues),
            'advice' => (count($sourcesPerdues) === 0) ? '' : __('La source de ces personnes ne se lit plus : elles gardent leur dernier état connu, qui ne bougera plus. Rouvrez la personne et vérifiez sa commande source.', __FILE__),
            'state'  => (count($sourcesPerdues) === 0),
        );
        $sante[] = array(
            'test'   => __('Départs qui n\'aboutissent pas', __FILE__),
            'result' => (count($bloquees) === 0) ? __('aucun', __FILE__) : implode(', ', $bloquees),
            'advice' => (count($bloquees) === 0) ? '' : __('Ces personnes attendent leur confirmation de départ depuis plus longtemps que leur délai : leur source ne porte sans doute pas de date de changement exploitable. Tant que cela dure, la maison ne devient jamais vide et l\'alarme ne peut pas s\'armer.', __FILE__),
            'state'  => (count($bloquees) === 0),
        );
        $sante[] = array(
            'test'   => __('Balises muettes', __FILE__),
            'result' => (count($muettes) === 0) ? __('aucune', __FILE__) : implode(', ', $muettes),
            'advice' => (count($muettes) === 0) ? '' : __('Ces balises se disent présentes mais n\'émettent plus depuis longtemps — pile morte, hors de portée, passerelle arrêtée. Tant que cela dure, la personne reste présente pour toujours et l\'alarme ne peut plus s\'armer.', __FILE__),
            'state'  => (count($muettes) === 0),
        );
        $sante[] = array(
            'test'   => __('Personnes hors de tout foyer', __FILE__),
            'result' => (count($orphelines) === 0) ? __('aucune', __FILE__) : implode(', ', $orphelines),
            'advice' => (count($orphelines) === 0) ? '' : __('Ces personnes sont suivies mais n\'entrent dans aucun foyer : aucune règle ne peut se déclencher sur leur arrivée ni sur leur départ. Cochez-les dans un foyer.', __FILE__),
            'state'  => (count($orphelines) === 0),
        );
        $sante[] = array(
            /* Sans écouteur, tout marche encore — une minute trop tard à chaque
             * fois. C'est la panne la plus difficile à voir du plugin. */
            'test'   => __('Écouteurs posés', __FILE__),
            'result' => (count($sansEcouteur) === 0) ? __('tous', __FILE__) : implode(', ', $sansEcouteur),
            'advice' => (count($sansEcouteur) === 0) ? '' : __('Sans écouteur, les changements ne sont vus qu\'au passage du cron. Enregistrez la personne pour le reposer.', __FILE__),
            'state'  => (count($sansEcouteur) === 0),
        );
        $sante[] = array(
            'test'   => __('Foyers sans personne', __FILE__),
            'result' => (count($foyersVides) === 0) ? __('aucun', __FILE__) : implode(', ', $foyersVides),
            'advice' => (count($foyersVides) === 0) ? '' : __('Un foyer sans personne ne sait rien et ne déclenche rien.', __FILE__),
            'state'  => (count($foyersVides) === 0),
        );
        /*
         * Le dossier de données : c'est là que vivent le journal et les verrous.
         *
         * Non inscriptible, le plugin continue de tourner parfaitement — les
         * présences sont suivies, les règles jouées — mais le journal reste
         * vide. L'utilisateur le relit au bout de trois jours de simulation et
         * en conclut qu'aucune règle ne s'est déclenchée : il sort de
         * simulation en croyant avoir vérifié quelque chose. Le cas le plus
         * fréquent est un dossier appartenant au compte de déploiement et non à
         * celui du cron.
         */
        $dossier = self::dossierDonnees();
        $dossierOk = (is_dir($dossier) && is_writable($dossier));
        $sante[] = array(
            'test'   => __('Dossier de données inscriptible', __FILE__),
            'result' => $dossierOk ? $dossier : sprintf(__('%s n\'est pas inscriptible', __FILE__), $dossier),
            'advice' => $dossierOk ? '' : __('Sans ce dossier, le journal reste vide et le mode simulation ne prouve plus rien. Donnez-le à www-data.', __FILE__),
            'state'  => $dossierOk,
        );
        $sante[] = array(
            'test'   => __('Références mortes dans les règles', __FILE__),
            'result' => (count($reglesMortes) === 0) ? __('aucune', __FILE__) : implode(' ; ', array_unique($reglesMortes)),
            'advice' => (count($reglesMortes) === 0) ? '' : __('Ces règles pointent une commande qui n\'existe plus : elles ne feront rien, sans erreur.', __FILE__),
            'state'  => (count($reglesMortes) === 0),
        );
        $sante[] = array(
            /* Une information, pas une erreur : la simulation est un mode de
             * travail normal. Mais elle explique à elle seule « le plugin ne
             * fait rien », et c'est la première chose à regarder. */
            'test'   => __('Mode simulation', __FILE__),
            'result' => $simulationGlobale
                      ? __('actif pour tout le plugin — aucune action n\'est exécutée', __FILE__)
                      : ((count($foyersSimules) === 0) ? __('inactif', __FILE__) : implode(', ', $foyersSimules)),
            'advice' => ($simulationGlobale || count($foyersSimules) > 0)
                      ? __('Les règles sont évaluées et journalisées, mais aucune action ne part.', __FILE__) : '',
            'state'  => true,
        );

        return $sante;
    }

    /* ================================================================= OUTILS */

    /* Le type de cet équipement, toujours l'une des deux constantes : un
     * getConfiguration('type') nu rendrait '' sur un équipement créé avant que
     * le champ existe, et chaque appelant devrait s'en méfier. */
    public function type() {
        return ($this->getConfiguration('type') === self::TYPE_FOYER) ? self::TYPE_FOYER : self::TYPE_PERSONNE;
    }

    /*
     * L'équipement RELU en base, sur lequel écrire une bascule d'état.
     *
     * Vérifié dans le cœur (DB::save, branche « Object to update ») :
     * `save(true)` produit un `UPDATE eqLogic SET …` construit sur TOUS les
     * champs de l'objet en mémoire, colonne `configuration` comprise. Il
     * n'écrit pas la clé qu'on vient de changer, il réécrit le bloc entier tel
     * qu'il était au moment où l'objet a été chargé.
     *
     * Or les quatre bascules — forçage, armement, mise en service, simulation —
     * partent du cron, d'un scénario ou d'une action de règle, avec un objet
     * chargé au début du passage. Une seconde plus tard suffit : l'utilisateur
     * enregistre ses règles depuis le navigateur, le cron arme l'alarme, et le
     * bloc périmé écrase les règles qui viennent d'être écrites. Dans l'autre
     * sens, une mise hors service faite à la main est annulée par un objet qui
     * la précède d'une minute — et l'alarme s'arme sur une maison dont on avait
     * justement demandé qu'elle soit laissée tranquille.
     *
     * On relit donc la ligne juste avant d'écrire, et c'est sur cette relecture
     * qu'on décide : la fenêtre passe de « la durée du passage » à « les
     * quelques instructions entre la lecture et l'UPDATE ».
     */
    private function objetFrais() {
        try {
            $id = (int) $this->getId();
            if ($id > 0) {
                $frais = self::byId($id);
                if (is_object($frais) && $frais->getEqType_name() === __CLASS__) {
                    return $frais;
                }
            }
        } catch (Throwable $e) {
            /* Relecture impossible : on écrit depuis l'objet qu'on tient plutôt
             * que de renoncer à la bascule. Perdre une configuration est grave,
             * ne pas armer une alarme l'est tout autant. */
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : '
                   . __('relecture impossible avant écriture,', __FILE__) . ' ' . $e->getMessage());
        }
        return $this;
    }

    /* Recopie sur l'objet courant les clés qui viennent d'être écrites sur sa
     * relecture. Sans cela, l'appelant continuerait de lire l'ancienne valeur —
     * rafraichirPersonne(), appelée juste après forcerPersonne(), republierait
     * le mode précédent et le bouton paraîtrait sans effet. */
    private function reporterConfiguration($_frais, $_cles) {
        if ($_frais === $this) {
            return;
        }
        foreach ($_cles as $cle) {
            $this->setConfiguration($cle, $_frais->getConfiguration($cle));
        }
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
