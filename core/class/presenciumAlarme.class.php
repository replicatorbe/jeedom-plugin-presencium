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

/*
 * L'alarme portée par le foyer — armement, mise en service, liaison à une
 * vraie alarme — et la simulation, avec l'écriture sur l'objet relu que
 * partagent les bascules.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumAlarme {

    /* ================================================================= ALARME */

    /*
     * Arme ou désarme l'alarme portée par le foyer.
     *
     * En simulation, l'état ne bouge pas : armer EST une action, et le contrat
     * du mode simulation est qu'aucune action ne part. Ce qui aurait été fait
     * est écrit au journal, avec la mention — c'est ce qu'on relit pour décider
     * si l'on peut enfin sortir de simulation.
     *
     * Quand le foyer est relié à une vraie alarme (voir alarmeLiee()), rien de
     * ce qui suit ne s'applique : l'ordre part vers la centrale, et l'état
     * « armée » attend qu'elle le confirme. Voir alarmeOrdonner().
     *
     * $_origine : qui demande — « la règle « J'arme en partant » », « admin
     * depuis l'interface »… Seul le chemin lié l'écrit au journal ; voir
     * origineOrdre().
     */
    public function armer($_arme, $_origine = '') {
        $arme = $_arme ? 1 : 0;
        $libelle = $arme ? __('armement', __FILE__) : __('désarmement', __FILE__);

        /* Relu AVANT de décider, et pas seulement avant d'écrire : le refus
         * d'armer une alarme hors service se lit sur l'état courant, et cet
         * état vient peut-être d'être changé à la main pendant que le cron
         * tournait. Voir objetFrais(). */
        $frais = $this->objetFrais();

        /* La liaison se lit sur l'objet relu, comme le reste : elle vient
         * peut-être d'être posée depuis la page pendant que ce processus
         * tenait un objet d'avant. */
        if ($frais->alarmeLiee()) {
            return $this->alarmeOrdonner($frais, $arme ? 'armer' : 'desarmer', $_origine);
        }

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
     *
     * Sauf quand le foyer est relié à une vraie alarme : « en service » reste
     * alors l'interrupteur des armements AUTOMATIQUES du foyer, et ne commande
     * pas la centrale. La mise hors service n'y envoie pas de désarmement —
     * suspendre les règles pour un week-end ne doit pas ouvrir une maison
     * qu'on a armée à la main en partant. L'état « armée » continue de suivre
     * la centrale, qui seule sait s'il est vrai.
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
            $detail = ucfirst($libelle);
            if ($actif === 0 && $frais->alarmeLiee()) {
                $detail .= ' — ' . __('la vraie alarme n\'est pas désarmée pour autant : seuls les armements automatiques du foyer sont suspendus', __FILE__);
            }
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => false, 'verdict' => 'declenchee',
                'detail' => $detail,
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle);
        }
        $this->checkAndUpdateCmd('en_service', $actif);
        if ($frais->alarmeLiee()) {
            /* Liée, l'état armé est celui de la centrale, pas la clé
             * `etat_armee` que la mise hors service vient de remettre à zéro
             * — la publier ici afficherait « désarmée » sur une maison armée. */
            $frais->alarmeSynchroniser();
        } else {
            $this->checkAndUpdateCmd('armee', ($frais->getConfiguration('etat_armee', 0) == 1) ? 1 : 0);
        }
        return ($actif === 1);
    }

    /* ============================================================ ALARME LIÉE */

    /*
     * Le foyer est-il relié à une vraie alarme ?
     *
     * Tant qu'aucune alarme n'est installée, le foyer porte ses deux états
     * lui-même (voir armer()). Le jour où une centrale arrive — une Ajax
     * exposée par un plugin, par exemple —, deux alarmes qui s'ignorent
     * seraient pires qu'une : « Alarme armée » dirait 0 sur une maison armée,
     * et les assistants vocaux, les widgets et les règles qui la lisent
     * croiraient la maison ouverte. La liaison fait du foyer une FAÇADE de la
     * centrale : ses actions lui envoient des ordres, son état la suit.
     *
     * Un simple interrupteur, et non « une commande d'état est choisie » : la
     * page doit pouvoir garder une configuration préparée sans l'appliquer, et
     * un foyer existant ne doit rien changer à son comportement tant qu'on ne
     * l'a pas explicitement demandé. Quand l'interrupteur est levé mais qu'une
     * commande manque, le foyer reste lié quand même : revenir en silence à la
     * bascule locale ferait « armer » un foyer qui ne commande plus rien, et
     * la page Santé dit ce qui manque.
     */
    public function alarmeLiee() {
        return $this->type() === self::TYPE_FOYER && (int) $this->getConfiguration('alarme_liee', 0) === 1;
    }

    /* Le mode nuit est-il disponible ? Seulement lié, et seulement si une
     * commande est désignée pour lui : le foyer n'a pas de mode nuit à lui. */
    public function alarmeNuitDisponible() {
        return $this->alarmeLiee() && (int) $this->getConfiguration('alarme_cmd_nuit', 0) > 0;
    }

    /*
     * Passe l'alarme en mode nuit — une action qui n'existe que liée.
     *
     * Appelée par la commande « Mode nuit » du foyer, qui n'est créée que si
     * une commande est liée, mais qui SURVIT au retrait de la liaison : la
     * supprimer casserait en silence les scénarios et les règles qui la
     * nomment. Elle refuse alors proprement, au journal, plutôt que de ne rien
     * faire sans le dire.
     */
    public function modeNuit($_origine = '') {
        $frais = $this->objetFrais();
        return $this->alarmeOrdonner($frais, 'nuit', $_origine);
    }

    /*
     * Envoie un ordre à la vraie alarme : 'armer', 'desarmer' ou 'nuit'.
     *
     * Trois règles, et chacune a sa raison :
     *
     *  - L'ÉTAT N'EST PAS ÉCRIT D'AVANCE. Une centrale peut refuser un
     *    armement — une porte ouverte, une batterie faible, une zone en
     *    défaut — et c'est précisément le cas où « armée = 1 » serait un
     *    mensonge dangereux. L'état suit la commande d'état, par l'écouteur
     *    (onAlarme()) ou au passage suivant du cron.
     *  - LA SIMULATION S'APPLIQUE COMME AILLEURS. Un ordre à une centrale est
     *    une action, et le contrat du mode simulation est qu'aucune action ne
     *    part. Il est journalisé, avec la commande qui aurait été exécutée.
     *  - L'ÉCHEC SE DIT. Une commande liée supprimée — une réinstallation du
     *    plugin de la centrale suffit — ne lève rien vers l'appelant : on ne
     *    fait pas tomber un scénario, ni le cron, pour une alarme qui ne
     *    répond pas. Mais le journal du foyer porte une ligne « échec » qui
     *    dit laquelle, et le journal du plugin un avertissement.
     *
     * Le refus d'armer une alarme hors service est conservé : « en service »
     * reste l'interrupteur général du foyer. Le désarmement, lui, passe
     * toujours — on n'empêche jamais d'ouvrir.
     *
     * Rend vrai si l'ordre est parti (ou aurait pu partir, en simulation).
     */
    private function alarmeOrdonner($_frais, $_ordre, $_origine) {
        $ordres = array(
            'armer'    => array('cle' => 'alarme_cmd_armer',    'libelle' => __('armement', __FILE__)),
            'desarmer' => array('cle' => 'alarme_cmd_desarmer', 'libelle' => __('désarmement', __FILE__)),
            'nuit'     => array('cle' => 'alarme_cmd_nuit',     'libelle' => __('mode nuit', __FILE__)),
        );
        if (!isset($ordres[$_ordre])) {
            return false;
        }
        $libelle = $ordres[$_ordre]['libelle'];
        $qui = ((string) $_origine !== '') ? ' — ' . sprintf(__('demandé par %s', __FILE__), $_origine) : '';

        if (!$_frais->alarmeLiee()) {
            /* Seul le mode nuit arrive ici sans liaison : armer() et désarmer
             * gardent leur bascule locale. */
            return $this->alarmeRefuser(sprintf(__('%s refusé : le foyer n\'est relié à aucune alarme', __FILE__), ucfirst($libelle)) . $qui);
        }

        $id = (int) $_frais->getConfiguration($ordres[$_ordre]['cle'], 0);
        if ($id <= 0) {
            return $this->alarmeRefuser(sprintf(__('%s refusé : aucune commande de l\'alarme n\'est liée à cette action', __FILE__), ucfirst($libelle)) . $qui);
        }

        /* Hors service, le foyer n'arme pas — ni en armement total, ni en mode
         * nuit, qui est un armement. Vérifié AVANT la simulation, au contraire
         * du chemin local : la simulation doit montrer ce qui se serait passé,
         * et ce qui se serait passé, c'est ce refus. */
        if ($_ordre !== 'desarmer' && (int) $_frais->getConfiguration('etat_en_service', 0) !== 1) {
            return $this->alarmeRefuser(sprintf(__('%s refusé : l\'alarme est hors service', __FILE__), ucfirst($libelle)) . $qui);
        }

        $cmd = cmd::byId($id);
        $nomCmd = is_object($cmd) ? $cmd->getHumanName() : ('#' . $id . '#');

        if ($_frais->enSimulation()) {
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => true, 'verdict' => 'declenchee',
                'detail' => sprintf(__('%1$s simulé : %2$s n\'a pas été exécutée', __FILE__), ucfirst($libelle), $nomCmd) . $qui,
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle . ' ' . __('(simulation)', __FILE__) . ' — ' . $nomCmd);
            return true;
        }

        if (!is_object($cmd)) {
            return $this->alarmeRefuser(sprintf(__('%1$s impossible : la commande liée %2$s est introuvable, rien n\'a été envoyé à l\'alarme', __FILE__), ucfirst($libelle), $nomCmd) . $qui);
        }
        /* Une commande d'information désignée par erreur ne « s'exécute » pas :
         * execCmd() en rendrait la valeur, et le journal dirait « envoyé » à
         * une centrale qui n'a rien reçu. */
        if ($cmd->getType() !== 'action') {
            return $this->alarmeRefuser(sprintf(__('%1$s impossible : %2$s n\'est pas une commande action', __FILE__), ucfirst($libelle), $nomCmd) . $qui);
        }

        try {
            $cmd->execCmd();
        } catch (Throwable $e) {
            return $this->alarmeRefuser(sprintf(__('%1$s : échec de %2$s — %3$s', __FILE__), ucfirst($libelle), $nomCmd, $e->getMessage()) . $qui);
        }

        $this->journalAjouter(array(
            'genre' => 'alarme', 'simulation' => false, 'verdict' => 'declenchee',
            'detail' => sprintf(__('%1$s demandé à l\'alarme : %2$s exécutée, l\'état suivra la centrale', __FILE__), ucfirst($libelle), $nomCmd)
                      . $qui . ' — ' . $this->detailPresence(time()),
        ));
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $libelle . ' → ' . $nomCmd
               . (((string) $_origine !== '') ? ' (' . $_origine . ')' : ''));
        return true;
    }

    /* Un ordre qui ne part pas : une ligne « échec » au journal du foyer, un
     * avertissement au journal du plugin, et jamais d'exception. */
    private function alarmeRefuser($_detail) {
        $this->journalAjouter(array(
            'genre' => 'alarme', 'simulation' => false, 'verdict' => 'echec',
            'detail' => $_detail,
        ));
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . $_detail);
        return false;
    }

    /*
     * Lit l'état de la vraie alarme : array('etat' => 1|0|null, 'valeur' =>
     * brute, 'erreur' => '').
     *
     * `etat` vaut null quand on ne sait pas — commande absente, pas une info,
     * jamais collectée. Et « on ne sait pas » ne devient JAMAIS « désarmée » :
     * c'est la seule erreur qui ait une conséquence, puisqu'une règle « si
     * l'alarme n'est pas armée, armer » ou un assistant vocal croiraient la
     * maison ouverte. L'état publié reste alors le dernier connu.
     *
     * La comparaison est celle des lignes de condition des règles
     * (presenciumRegles::comparer()) : une centrale qui publie « Armé » ou
     * « Mode nuit » plutôt que 1 se lit avec « != Désarmé », sans code de plus.
     */
    public function alarmeLireEtat() {
        $id = (int) $this->getConfiguration('alarme_etat', 0);
        if ($id <= 0) {
            return array('etat' => null, 'valeur' => null, 'erreur' => __('aucune commande d\'état n\'est liée', __FILE__));
        }
        $cmd = cmd::byId($id);
        if (!is_object($cmd)) {
            return array('etat' => null, 'valeur' => null,
                         'erreur' => sprintf(__('la commande d\'état #%s# est introuvable', __FILE__), $id));
        }
        /* Jamais execCmd() sur une action : ce serait appuyer sur un bouton de
         * la centrale à chaque passage du cron, simulation comprise. */
        if ($cmd->getType() !== 'info') {
            return array('etat' => null, 'valeur' => null,
                         'erreur' => sprintf(__('%s n\'est pas une commande info', __FILE__), $cmd->getHumanName()));
        }
        try {
            $valeur = $cmd->execCmd();
        } catch (Throwable $e) {
            return array('etat' => null, 'valeur' => null, 'erreur' => $cmd->getHumanName() . ' : ' . $e->getMessage());
        }
        if ($valeur === null || (is_string($valeur) && trim($valeur) === '')) {
            return array('etat' => null, 'valeur' => $valeur,
                         'erreur' => sprintf(__('%s n\'a pas encore de valeur', __FILE__), $cmd->getHumanName()));
        }
        $etat = presenciumRegles::comparer($valeur,
                    (string) $this->getConfiguration('alarme_operateur', '=='),
                    (string) $this->getConfiguration('alarme_valeur', '1')) ? 1 : 0;
        return array('etat' => $etat, 'valeur' => $valeur, 'erreur' => '', 'nom' => $cmd->getHumanName());
    }

    /*
     * Reporte l'état de la vraie alarme sur « Alarme armée ».
     *
     * Appelée par l'écouteur de la commande d'état (onAlarme()), à chaque
     * passage du cron (publierFoyer()) et donc à l'enregistrement : trois
     * chemins pour une même lecture, parce qu'un écouteur se perd —
     * listener::clean(), une commande recréée — et que rien alors ne le dirait.
     *
     * Le changement observé est écrit au journal du foyer : c'est lui qu'on
     * relit pour savoir QUAND la maison a été armée, et un armement fait
     * depuis l'application de la centrale n'a pas d'autre trace chez nous.
     * La première publication (commande encore vide) ne l'est pas : ce n'est
     * pas un changement, c'est une découverte.
     *
     * Rend l'état publié (0 ou 1), ou null s'il n'a pas pu être lu.
     */
    public function alarmeSynchroniser() {
        if (!$this->alarmeLiee()) {
            return null;
        }
        $lecture = $this->alarmeLireEtat();
        if ($lecture['etat'] === null) {
            /* En debug seulement : le cron repasse chaque minute, et la page
             * Santé porte la même phrase là où on la lira. */
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('état de l\'alarme liée illisible,', __FILE__)
                   . ' ' . $lecture['erreur']);
            return null;
        }
        $etat = (int) $lecture['etat'];

        $precedent = null;
        $cmdArmee = $this->getCmd('info', 'armee');
        if (is_object($cmdArmee)) {
            $precedent = $cmdArmee->execCmd();
        }
        if ($precedent !== null && $precedent !== '' && (int) $precedent !== $etat) {
            $this->journalAjouter(array(
                'genre' => 'alarme', 'simulation' => false, 'verdict' => 'declenchee',
                'detail' => sprintf($etat === 1 ? __('L\'alarme liée est maintenant armée (%1$s = %2$s)', __FILE__)
                                                : __('L\'alarme liée est maintenant désarmée (%1$s = %2$s)', __FILE__),
                                    $lecture['nom'], is_scalar($lecture['valeur']) ? (string) $lecture['valeur'] : '?'),
            ));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
                   . ($etat === 1 ? __('alarme liée armée', __FILE__) : __('alarme liée désarmée', __FILE__)));
        }
        $this->checkAndUpdateCmd('armee', $etat);
        return $etat;
    }

    /*
     * Ce qui manque à la liaison, en phrases : la page Santé les affiche, et
     * la fiche du foyer aussi. Vide quand le foyer n'est pas lié.
     */
    public function alarmeProblemes() {
        $problemes = array();
        if (!$this->alarmeLiee()) {
            return $problemes;
        }
        /* Un état illisible fige « Alarme armée » sur sa dernière valeur :
         * c'est le premier problème à dire. */
        $lecture = $this->alarmeLireEtat();
        if ($lecture['etat'] === null) {
            $problemes[] = $lecture['erreur'];
        }
        $actions = array(
            'alarme_cmd_armer'    => array(__('Armer', __FILE__), true),
            'alarme_cmd_desarmer' => array(__('Désarmer', __FILE__), true),
            'alarme_cmd_nuit'     => array(__('Mode nuit', __FILE__), false),
        );
        foreach ($actions as $cle => $action) {
            $id = (int) $this->getConfiguration($cle, 0);
            if ($id <= 0) {
                if ($action[1]) {
                    $problemes[] = sprintf(__('aucune commande liée pour « %s »', __FILE__), $action[0]);
                }
                continue;
            }
            $cmd = cmd::byId($id);
            if (!is_object($cmd)) {
                $problemes[] = sprintf(__('« %1$s » : la commande #%2$s# est introuvable', __FILE__), $action[0], $id);
            } elseif ($cmd->getType() !== 'action') {
                $problemes[] = sprintf(__('« %1$s » : %2$s n\'est pas une commande action', __FILE__), $action[0], $cmd->getHumanName());
            }
        }
        return $problemes;
    }

    /*
     * Qui a demandé l'ordre, pour le journal.
     *
     * Le cœur ne transmet pas l'appelant à cmd::execute() ; on le reconstitue
     * du mieux possible, dans cet ordre :
     *  - une règle du plugin en train de jouer ses actions (posée par
     *    executerActions(), dans ce même processus) ;
     *  - un utilisateur : cmd.ajax.php ajoute `user_login` aux options quand
     *    l'ordre vient de l'interface — tableau de bord, application mobile ;
     *  - un scénario : il n'est dans aucune option, mais il est dans la pile
     *    d'appel (scenario::execute() → … → cmd::execCmd()), et c'est le seul
     *    endroit où on peut le lire ;
     *  - sinon, on le dit : un plugin, l'API, un autre automatisme.
     */
    public static function origineOrdre($_options = array()) {
        if (self::$_regleEnCours !== '') {
            return sprintf(__('la règle « %s »', __FILE__), self::$_regleEnCours);
        }
        if (is_array($_options) && isset($_options['user_login']) && (string) $_options['user_login'] !== '') {
            return sprintf(__('%s depuis l\'interface', __FILE__), (string) $_options['user_login']);
        }
        try {
            if (class_exists('scenario', false)) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $appel) {
                    if (isset($appel['object']) && $appel['object'] instanceof scenario) {
                        return sprintf(__('le scénario %s', __FILE__), $appel['object']->getHumanName());
                    }
                }
            }
        } catch (Throwable $e) {
            /* Une origine qu'on ne sait pas lire n'empêche pas l'ordre. */
        }
        return __('une commande Jeedom (scénario, plugin ou API)', __FILE__);
    }

    /* ================================================== ÉCOUTEUR DE L'ALARME */

    /*
     * Rappel de l'écouteur posé sur la commande d'état de l'alarme liée.
     *
     * Même contrat qu'onSource() : processus séparé, rien ne remonte, donc
     * rien ne lève. Et même nettoyage : un foyer supprimé sans que son
     * écouteur le soit le perd ici, au lieu de réveiller jeeListener.php à
     * chaque changement d'état de la centrale.
     */
    public static function onAlarme($_option) {
        try {
            $id = (is_array($_option) && isset($_option['id'])) ? (int) $_option['id'] : 0;
            $eqLogic = ($id > 0) ? eqLogic::byId($id) : null;
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) {
                $listener = listener::byClassAndFunction(__CLASS__, 'onAlarme', array('id' => $id));
                if (is_object($listener)) {
                    $listener->remove();
                }
                return;
            }
            if ($eqLogic->getIsEnable() != 1) {
                return;
            }
            $eqLogic->alarmeSynchroniser();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', __('Écouteur de l\'alarme :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /*
     * Pose ou retire l'écouteur de l'alarme liée. Idempotent, appelé à chaque
     * enregistrement et par presencium_update(), comme syncListener().
     *
     * Une fonction distincte (onAlarme et non onSource) plutôt qu'un second
     * événement sur le même écouteur : l'option array('id' => …) identifie un
     * écouteur, et un foyer qui partagerait la clé d'une personne de même
     * identifiant — impossible, mais rien ne coûte de ne pas y penser — serait
     * aussi un foyer dont la source deviendrait la centrale.
     */
    public function syncListenerAlarme() {
        $option = array('id' => (int) $this->getId());
        $listener = listener::byClassAndFunction(__CLASS__, 'onAlarme', $option);
        $etat = (int) $this->getConfiguration('alarme_etat', 0);

        $utile = $this->alarmeLiee()
              && ($this->getIsEnable() == 1)
              && ($etat > 0)
              && is_object(cmd::byId($etat));

        if (!$utile) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('onAlarme');
            $listener->setOption($option);
        }
        /* emptyEvent() avant addEvent() : changer de commande d'état ne doit
         * pas laisser le foyer écouter aussi l'ancienne. */
        $listener->emptyEvent();
        $listener->addEvent($etat);
        $listener->save();
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
}
