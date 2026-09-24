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
 * Les pannes muettes et leurs messages, la découverte des sources
 * candidates, et la page Santé du cœur.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumSante {

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
}
