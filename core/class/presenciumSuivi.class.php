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
 * Le suivi d'une personne : le verdict tiré du signal brut, sa publication
 * et ses fronts au journal, la source perdue, le faux départ, le forçage.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumSuivi {

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
            /* Sans la mention du mode, un départ obtenu par « Forcer absent »
             * s'écrirait exactement comme un vrai : relu des semaines plus
             * tard, rien ne distinguerait une personne sortie d'une personne
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
     * départ ont été jouées — l'alarme a pu s'armer — et le journal
     * n'écrirait qu'un départ puis une arrivée, dix minutes plus loin, sans
     * rien dire du lien entre les deux. C'est pourtant le seul cas qui coûte
     * cher, et le seul qui dise que le délai est trop court.
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
         * écrit aussi la ligne de log. */
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

    /* Journalise une transition de présence dans le journal de la personne,
     * puis dans celui de CHAQUE foyer qui la contient : ce qu'on relit, c'est
     * l'histoire d'une maison, pas celle d'une balise prise isolément. */
    private function journaliserPresence($_verdict, $_detail) {
        /* Chez elle d'abord. Une personne qui n'appartient encore à aucun foyer
         * — le cas de toute installation qui démarre — verrait sinon ses
         * rebonds absorbés disparaître sans laisser de trace, alors que c'est
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
}
