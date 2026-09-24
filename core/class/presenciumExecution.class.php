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
 * Les règles d'un foyer : les jouer, les attendre, évaluer leurs conditions,
 * exécuter leurs actions — ou les confier à un processus séparé.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumExecution {

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
     * Par l'identifiant et jamais par le rang : l'éditeur travaille sur une
     * copie, et réordonner la liste sans enregistrer ferait tester — donc
     * EXÉCUTER pour de vrai — une autre règle que celle affichée à l'écran. Un
     * bouton d'essai qui arme l'alarme alors qu'on croyait déclencher une
     * lampe est la pire promesse que puisse faire une interface.
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
         * depart_dernier. Boucler sur les règles à l'extérieur jetterait cet
         * ordre pour celui du tableau des règles, c'est-à-dire l'ordre
         * d'AFFICHAGE dans la modale. Quand quelqu'un rentre à la minute où un
         * autre part, « la maison n'est plus vide → désarmer » et « la maison
         * se vide → armer » se joueraient alors dans l'ordre de saisie : selon
         * le jour, la maison finirait armée avec quelqu'un dedans, ou ouverte
         * avec personne. C'est la chronologie qui décide, jamais la mise en
         * page.
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
            $this->etatPoser('attentes', $_regle['id'], array(
                'echeance'   => $maintenant + (int) $_regle['attente'] * 60,
                'pose'       => $maintenant,
                'transition' => isset($_contexte['transition']) ? $_contexte['transition'] : array(),
            ));
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
         * actions, le repos laisserait toute la durée de leur exécution —
         * plusieurs secondes si l'une d'elles appelle un scénario ou une
         * passerelle distante — pendant laquelle l'autre processus lirait un
         * repos encore vide et jouerait la même règle : l'alarme armée deux
         * fois, la notification partie deux fois.
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
            $this->etatPoser('repos', $_regle['id'], $maintenant);
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
        $dernier = (int) $this->etatValeur('repos', $_regle['id'], 0);
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
             * Sans lui, une seule attente en échec remonterait jusqu'à
             * rafraichirFoyer() et priverait le foyer de jouerRegles() pour ce
             * passage. Les arrivées et les départs de cette minute-là seraient
             * alors perdus — ce qui, pour un départ, veut dire une alarme qui
             * ne s'arme jamais, sans la moindre erreur ailleurs que dans une
             * ligne du journal de Jeedom.
             */
            try {
                $attente = $this->etatValeur('attentes', $regle['id']);
                if (!is_array($attente) || !isset($attente['echeance'])) {
                    continue;
                }
                $transition = isset($attente['transition']) ? $attente['transition'] : array();

                if (presenciumRegles::declencheurInverse($regle, $transition, $_instantane)) {
                    $this->etatPoser('attentes', $regle['id'], null);
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
                $this->etatPoser('attentes', $regle['id'], null);
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

    /* Le libellé du déclencheur ; les noms ne sont chargés que si la règle
     * nomme quelqu'un. */
    private function libelleDeclencheur($_regle) {
        $nominative = (isset($_regle['personne']) && (int) $_regle['personne'] > 0);
        return presenciumRegles::libelleDeclencheur($_regle, $nominative ? $this->nomsPersonnes() : array());
    }
}
