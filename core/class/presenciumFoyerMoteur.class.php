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
 * Le moteur d'un foyer : sa composition, l'instantané de qui est là, le
 * rafraîchissement sous verrou, la publication des commandes, les
 * déclencheurs temporels et la phrase qui dit qui était là.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumFoyerMoteur {

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
             * Une personne dont le champ `type` est perdu — copie
             * d'équipement, restauration partielle, configuration éditée à la
             * main — reste rafraîchie par le cron ; l'écarter ici la ferait
             * disparaître de ses foyers, et le total qui baisse fait renoncer
             * aux départs de ce passage : son départ serait supprimé en
             * silence.
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

    /*
     * Les foyers qui contiennent cette personne.
     *
     * La correspondance personne → foyers est calculée une fois par processus
     * (un byType() par passage de cron au lieu d'un par personne), et oubliée
     * par postSave() et preRemove(). Ce sont les IDENTIFIANTS qui sont gardés,
     * pas les objets : armer() ou basculerSimulation() écrivent la
     * configuration d'un foyer par save(true), sans postSave, et un objet
     * gardé en mémoire republierait l'état d'avant. Chaque appel relit donc
     * ses foyers en base. La minute d'expiration couvre les processus longs.
     */
    public static function foyersDe($_idPersonne) {
        $foyers = array();
        $id = (int) $_idPersonne;
        if ($id <= 0) {
            return $foyers;
        }
        $lus = array();
        if (self::$_foyersParPersonne === null || (time() - self::$_foyersParPersonneDate) > 60) {
            $carte = array();
            foreach (self::byType(__CLASS__, true) as $eqLogic) {
                if ($eqLogic->type() !== self::TYPE_FOYER) {
                    continue;
                }
                $ids = $eqLogic->getConfiguration('personnes');
                if (!is_array($ids)) {
                    continue;
                }
                $lus[(int) $eqLogic->getId()] = $eqLogic;
                foreach ($ids as $candidat) {
                    $carte[(int) $candidat][(int) $eqLogic->getId()] = true;
                }
            }
            self::$_foyersParPersonne = $carte;
            self::$_foyersParPersonneDate = time();
        }
        if (!isset(self::$_foyersParPersonne[$id])) {
            return $foyers;
        }
        foreach (array_keys(self::$_foyersParPersonne[$id]) as $idFoyer) {
            /* Lu juste au-dessus, l'objet est frais : inutile de le relire. */
            $foyer = isset($lus[$idFoyer]) ? $lus[$idFoyer] : self::byId($idFoyer);
            if (!is_object($foyer) || $foyer->getEqType_name() !== __CLASS__
                || $foyer->getIsEnable() != 1 || $foyer->type() !== self::TYPE_FOYER) {
                continue;
            }
            /* Une composition changée sans postSave ne doit pas garder la
             * personne dans un foyer qu'elle a quitté. */
            $ids = $foyer->getConfiguration('personnes');
            if (!is_array($ids) || !in_array($id, array_map('intval', $ids), true)) {
                continue;
            }
            $foyers[] = $foyer;
        }
        return $foyers;
    }

    /* À appeler dès qu'un équipement change : la correspondance sera refaite
     * au prochain foyersDe(). */
    private static function oublierFoyers() {
        self::$_foyersParPersonne = null;
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
     * retrouvera. Écrit plus tôt, une exception échappée de publierFoyer() ou
     * de traiterAttentes() priverait le foyer de jouerRegles() alors que
     * l'instantané aurait déjà avancé : les arrivées et les départs de cette
     * minute-là seraient perdus pour toujours, et l'alarme ne s'armerait
     * jamais sur ce départ-là.
     *
     * Chaque étape porte en plus son propre try/catch, pour que la suivante ait
     * lieu quoi qu'il arrive. Entre l'instant où les transitions sont calculées
     * et celui où elles sont jouées, plus rien ne peut échapper.
     */
    private function rafraichirFoyerSousVerrou($_maintenant) {
        $instantane = $this->instantane($_maintenant);

        $avant = $this->etatValeur('instantane');
        if (!is_array($avant) || !isset($avant['presents']) || !is_array($avant['presents'])) {
            /* Premier passage, ou état perdu : on part de l'instantané courant.
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
        $this->etatPoser('instantane', null, $instantane);

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
        $etats = $this->etatValeur('foyer');
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
        $this->etatPoser('foyer', null, $etats);

        $this->checkAndUpdateCmd('presence', $occupe);
        $this->checkAndUpdateCmd('tous', ($_instantane['total'] > 0 && $nombre === (int) $_instantane['total']) ? 1 : 0);
        $this->checkAndUpdateCmd('occupation', $nombre);
        $this->checkAndUpdateCmd('qui', ($nombre > 0) ? implode(', ', $_instantane['presents']) : __('Personne', __FILE__));
        $this->checkAndUpdateCmd('etat', self::libelleEtatFoyer($nombre, (int) $_instantane['total']));
        $this->checkAndUpdateCmd('vide_depuis', ($occupe === 1 || (int) $etats['vide_depuis'] <= 0)
            ? 0 : (int) floor(($_maintenant - (int) $etats['vide_depuis']) / 60));
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

        $etats = $this->etatValeur('foyer');
        $debut = 0;
        if (is_array($etats)) {
            $debut = (int) (($_regle['declencheur'] === 'vide_depuis') ? $etats['vide_depuis'] : $etats['occupee_depuis']);
        }
        if ($debut <= 0 || ($_maintenant - $debut) < $minutes * 60) {
            return null;
        }

        if ((int) $this->etatValeur('temporel', $_regle['id'], 0) === $debut) {
            return null;
        }
        $this->etatPoser('temporel', $_regle['id'], $debut);

        return array('type' => $_regle['declencheur'], 'personne' => 0);
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

    /*
     * La phrase qui répond à « pourquoi ça s'est déclenché ? » six semaines plus
     * tard : qui était là, qui ne l'était pas, et depuis quand.
     *
     * $_instantane, quand il est fourni, est celui SUR LEQUEL la décision a été
     * prise, et c'est lui qui dit qui était présent. Recalculer les présences
     * ici annoncerait absente une personne dont le délai de départ a expiré
     * entre les deux calculs, dans l'explication d'un déclenchement décidé
     * alors qu'elle était encore là.
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
             * L'âge du signal brut ne peut pas en tenir lieu : les deux
             * coïncident à l'arrivée, le délai d'arrivée valant zéro, mais
             * divergent de tout le délai de départ aux départs — « absent
             * depuis 5 min » à la seconde même où le départ vient d'être
             * confirmé. Or c'est ce fichier-là qu'on relit pour régler le
             * délai, et un chiffre qui vaut le délai lui-même est le pire
             * endroit où se tromper.
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
}
