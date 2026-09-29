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
 * Les règles d'un foyer : ce qui se déclenche, quand, et sous quelles
 * conditions.
 *
 * Comme presenciumPersonne, cette classe ignore Jeedom et la base de données.
 * Elle ne lit aucune commande et n'en exécute aucune : elle compare deux
 * instantanés et dit ce qui a été franchi, elle compare deux valeurs qu'on lui
 * tend et dit si la condition tient. C'est la classe Jeedom qui va chercher les
 * valeurs et qui agit.
 *
 * Cette séparation n'est pas de l'élégance : c'est ce qui permet d'éprouver
 * hors ligne, en quelques millisecondes, les cas qu'on ne reproduit pas à la
 * demande dans une vraie maison — deux personnes qui rentrent dans la même
 * minute, la dernière qui part, le premier démarrage sans historique, une
 * personne retirée du foyer. Chacun de ces cas peut armer une alarme à tort, et
 * aucun ne lève d'erreur quand il se trompe.
 */
class presenciumRegles {

    /* `heure` est le seul déclencheur qui ne parle pas de présence : il tombe
     * à une heure de l'horloge (« À heure fixe »). Il est rangé ici avec les
     * autres parce qu'une règle n'en a qu'un, et que tout ce qui suit — les
     * conditions, l'attente, la relance, le repos, la simulation, le journal —
     * s'applique à lui sans exception. */
    const DECLENCHEURS = array('arrivee_premier', 'depart_dernier', 'arrivee_tous',
                               'arrivee', 'depart', 'vide_depuis', 'occupee_depuis', 'heure');
    const OPERATEURS = array('==', '!=', '>', '>=', '<', '<=');

    /* Douze heures d'attente, vingt-quatre heures de repos : au-delà, la règle
     * ne se comprend plus et surtout ne se vérifie plus — personne ne se
     * souvient d'une attente commencée avant-hier. */
    const ATTENTE_MAX = 720;
    const REPOS_MAX = 1440;

    /*
     * La relance : l'intervalle entre deux essais, et la durée pendant
     * laquelle on réessaie.
     *
     * L'intervalle s'arrête à deux heures. Au-delà, ce n'est plus « réessayer
     * dès que c'est calme » mais une seconde règle déguisée, qui agirait sur une
     * situation que plus personne n'a en tête — la porte qu'on voulait
     * reverrouiller dix minutes après l'arrivée le serait trois heures plus
     * tard, au milieu de la soirée.
     *
     * La durée maximale, elle, reprend exactement la borne de l'attente
     * (ATTENTE_MAX, douze heures) et pour la même raison : une relance EST une
     * suite d'attentes, et une règle qui attend encore le lendemain ne se
     * vérifie plus. Une constante distincte laisserait croire que les deux
     * bornes peuvent diverger ; elles ne le doivent pas. Son plancher est une
     * minute et non zéro : « réessayer pendant zéro minute » ne veut rien dire,
     * c'est `relance` à zéro qui désactive.
     *
     * Soixante minutes par défaut : assez pour qu'un passage devant une caméra
     * finisse par se calmer, assez peu pour qu'une condition durablement
     * fausse (une porte restée ouverte) n'occupe pas la règle tout l'après-midi.
     */
    const RELANCE_MAX = 120;
    const RELANCE_DUREE_DEFAUT = 60;

    /* Borne du `minutes` de vide_depuis / occupee_depuis : une semaine. Un
     * réglage plus grand ne se déclencherait jamais, ce qui ressemble
     * exactement à un plugin en panne. */
    const MINUTES_MAX = 10080;

    /*
     * Le déclencheur « À heure fixe » : combien d'heures, et quel retard on
     * rattrape.
     *
     * Vingt-quatre heures au plus : une par heure de la journée. Au-delà, ce
     * n'est plus une liste d'heures qu'on relit d'un coup d'œil mais une
     * programmation, qui a sa place dans un scénario ou dans un plugin de
     * programmation — et une liste qu'on ne relit plus est une liste où l'on
     * ne voit plus l'heure en trop.
     *
     * Cinq minutes de rattrapage. Le cron du cœur passe chaque minute, mais pas
     * forcément À la minute : un autre plugin qui tarde à rendre la main, une
     * sauvegarde, un redémarrage de Jeedom peuvent faire manquer 21:30 et ne
     * donner la main qu'à 21:32. Sans rattrapage, l'heure serait perdue, en
     * silence — exactement la nuit où la maison dormait sans alarme. Mais un
     * rattrapage sans borne serait pire : Jeedom redémarré à 23:10 jouerait
     * 22:30, sur une maison qui a eu quarante minutes pour changer. Cinq
     * minutes couvrent les retards ordinaires du cron (quelques secondes à
     * une ou deux minutes) et un redémarrage rapide, sans jamais agir sur une
     * situation que l'heure choisie ne décrivait plus. Au-delà, c'est l'heure
     * suivante de la liste qui prend le relais — la liste est elle-même le
     * filet, avec la relance.
     */
    const HEURES_FIXES_MAX = 24;
    const RATTRAPAGE_HEURE = 5;

    /*
     * Remet en forme la liste complète des règles d'un foyer.
     *
     * Les règles irrécupérables disparaissent plutôt que de rester en place en
     * silence : une règle sans déclencheur ne peut ni se déclencher ni
     * s'expliquer dans le journal, et l'utilisateur la croirait armée.
     *
     * Les identifiants en double sont refaits : `attente` et `repos` sont
     * mémorisés par identifiant de règle, deux règles homonymes se
     * neutraliseraient donc l'une l'autre — la seconde se croirait au repos
     * parce que la première vient d'agir. Un copier-coller de règle suffit à
     * produire le cas.
     */
    public static function normaliser($_regles) {
        $propres = array();
        if (!is_array($_regles)) {
            return $propres;
        }
        $vus = array();
        $rang = 0;
        foreach ($_regles as $regle) {
            /* Le rang est passé pour que l'identifiant de repli soit
             * DÉTERMINISTE : voir identifiantDeRang(). */
            $propre = self::normaliserRegle($regle, $rang);
            if ($propre === null) {
                continue;
            }
            if (isset($vus[$propre['id']])) {
                /* Un doublon reçoit lui aussi un identifiant dérivé du rang, et
                 * non un tirage au sort : normaliser() est rejouée à CHAQUE
                 * lecture de la configuration, et un tirage y donnerait à la
                 * règle un identifiant neuf à chaque passage du cron — ses
                 * attentes et son repos, mémorisés sous l'identifiant
                 * précédent, ne seraient jamais relus. */
                $propre['id'] = self::identifiantLibre($rang, $vus);
            }
            $vus[$propre['id']] = true;
            $propres[] = $propre;
            $rang++;
        }
        return $propres;
    }

    /*
     * Impose sa forme à une règle, ou rend null si elle est irrécupérable.
     *
     * Le seul motif de rejet est l'absence de déclencheur connu : tout le reste
     * a un défaut raisonnable. Une règle sans action est conservée — en
     * simulation, une règle qui n'agit pas mais qui se journalise est justement
     * ce qu'on écrit en premier pour observer avant de brancher.
     */
    public static function normaliserRegle($_regle, $_rang = null) {
        if (!is_array($_regle)) {
            return null;
        }
        $declencheur = isset($_regle['declencheur']) ? (string) $_regle['declencheur'] : '';
        if (!in_array($declencheur, self::DECLENCHEURS, true)) {
            return null;
        }

        $regle = array();
        /*
         * L'identifiant manquant est REMPLACÉ, pas tiré au sort — sauf quand on
         * ignore le rang, c'est-à-dire quand la règle naît (modale, script) et
         * qu'il n'y a encore aucune liste où la situer.
         *
         * normaliser() est rejouée à chaque lecture de la configuration : une
         * règle importée, restaurée ou écrite par un script, qui n'a donc jamais
         * eu d'identifiant, en recevrait un différent à chaque passage du cron.
         * Les clés d'attente et de repos étant dérivées de cet identifiant, une
         * attente posée à une minute ne serait jamais retrouvée à la suivante :
         * la règle « arme cinq minutes après le départ » se réarmerait sans fin
         * et n'armerait jamais.
         */
        $regle['id'] = (isset($_regle['id']) && trim((string) $_regle['id']) !== '')
            ? trim((string) $_regle['id'])
            : (($_rang === null) ? self::nouvelIdentifiant() : self::identifiantDeRang($_rang));
        $regle['nom'] = isset($_regle['nom']) ? trim((string) $_regle['nom']) : '';
        /* Une case à cocher décochée n'arrive pas du tout dans le formulaire.
         * On prend donc l'absence pour un « non », dans les deux sens : une
         * règle qu'on croyait désactivée et qui agit quand même, c'est une
         * alarme qui s'arme ; une règle qu'on croyait active et qui se tait, ce
         * n'est qu'une déception, et elle se voit dans la liste. */
        $regle['actif'] = self::caseCochee(isset($_regle['actif']) ? $_regle['actif'] : 0);
        $regle['declencheur'] = $declencheur;

        /* La personne ne veut dire quelque chose que pour arrivee / depart.
         * Ailleurs on la remet à zéro : un identifiant resté d'un déclencheur
         * précédemment choisi dans la modale filtrerait une transition qui ne
         * porte aucune personne, et la règle ne partirait jamais. */
        $regle['personne'] = 0;
        if (($declencheur === 'arrivee' || $declencheur === 'depart') && isset($_regle['personne'])) {
            $regle['personne'] = max(0, (int) $_regle['personne']);
        }

        $regle['minutes'] = self::entierBorne(isset($_regle['minutes']) ? $_regle['minutes'] : 0, 0, self::MINUTES_MAX);
        /* Les heures ne veulent dire quelque chose que pour `heure`, et on les
         * vide ailleurs pour la même raison que la personne : une liste restée
         * d'un déclencheur choisi puis abandonné dans la modale serait relue
         * par la page Santé, le tableau et l'export comme si elle comptait.
         * La clé, elle, est toujours là : une règle a toujours la même forme,
         * et c'est ce qui permet de la relire sans isset() partout. */
        $regle['heures_fixes'] = ($declencheur === 'heure')
            ? self::heuresFixes(isset($_regle['heures_fixes']) ? $_regle['heures_fixes'] : null)
            : array();
        $regle['attente'] = self::entierBorne(isset($_regle['attente']) ? $_regle['attente'] : 0, 0, self::ATTENTE_MAX);
        $regle['repos'] = self::entierBorne(isset($_regle['repos']) ? $_regle['repos'] : 0, 0, self::REPOS_MAX);
        /* `relance` à zéro désactive : c'est le défaut, pour qu'une règle
         * écrite avant l'arrivée de la relance garde exactement le
         * comportement qu'on lui connaît — abandonner au premier « non ».
         * `relance_max` vide ou illisible retombe sur son défaut et non sur le
         * plancher : une minute de relance, c'est une relance qui n'a jamais
         * lieu, et l'utilisateur croirait la fonction en panne. */
        $regle['relance'] = self::entierBorne(isset($_regle['relance']) ? $_regle['relance'] : 0, 0, self::RELANCE_MAX);
        $regle['relance_max'] = self::entierBorne(isset($_regle['relance_max']) ? $_regle['relance_max'] : self::RELANCE_DUREE_DEFAUT,
                                                  1, self::ATTENTE_MAX, self::RELANCE_DUREE_DEFAUT);
        $regle['simulation'] = self::caseCochee(isset($_regle['simulation']) ? $_regle['simulation'] : 0);

        $regle['conditions'] = self::normaliserConditions(
            isset($_regle['conditions']) ? $_regle['conditions'] : null);
        $regle['actions'] = self::normaliserActions(
            isset($_regle['actions']) ? $_regle['actions'] : null);

        return $regle;
    }

    /* Les conditions d'une règle : l'horaire, les jours, les lignes. */
    private static function normaliserConditions($_conditions) {
        $propres = array(
            'heures' => array('actif' => 0, 'de' => '00:00', 'a' => '23:59'),
            'jours'  => array(1, 2, 3, 4, 5, 6, 7),
            'lignes' => array(),
        );
        if (!is_array($_conditions)) {
            return $propres;
        }

        if (isset($_conditions['heures']) && is_array($_conditions['heures'])) {
            $heures = $_conditions['heures'];
            $propres['heures']['actif'] = self::caseCochee(isset($heures['actif']) ? $heures['actif'] : 0);
            $propres['heures']['de'] = self::heure(isset($heures['de']) ? $heures['de'] : '', '00:00');
            $propres['heures']['a'] = self::heure(isset($heures['a']) ? $heures['a'] : '', '23:59');
        }

        if (isset($_conditions['jours']) && is_array($_conditions['jours'])) {
            $jours = array();
            foreach ($_conditions['jours'] as $jour) {
                $jour = (int) $jour;
                if ($jour >= 1 && $jour <= 7 && !in_array($jour, $jours, true)) {
                    $jours[] = $jour;
                }
            }
            sort($jours);
            /*
             * Aucun jour coché vaut ici « tous les jours », à l'inverse d'un
             * plugin de programmation où les jours SONT le programme. Les jours
             * ne sont qu'un filtre posé sur un déclencheur qui, lui, existe
             * déjà : un filtre vide ne filtre rien. Et pour suspendre une
             * règle, la case `actif` est juste à côté et dit ce qu'elle fait —
             * une règle muette parce qu'on a décoché sept cases est une panne
             * qu'on ne retrouve pas.
             */
            if (count($jours) > 0) {
                $propres['jours'] = $jours;
            }
        }

        if (isset($_conditions['lignes']) && is_array($_conditions['lignes'])) {
            foreach ($_conditions['lignes'] as $ligne) {
                if (!is_array($ligne)) {
                    continue;
                }
                $cmd = isset($ligne['cmd']) ? (int) $ligne['cmd'] : 0;
                /* Une ligne sans identifiant de commande est une ligne que la
                 * modale vient d'ajouter et que l'utilisateur n'a pas remplie.
                 * On la jette à l'enregistrement plutôt que de l'évaluer :
                 * évaluée, elle serait fausse pour toujours et la règle ne
                 * partirait plus, sans que rien ne l'explique. */
                if ($cmd <= 0) {
                    continue;
                }
                $operateur = isset($ligne['operateur']) ? (string) $ligne['operateur'] : '==';
                if (!in_array($operateur, self::OPERATEURS, true)) {
                    $operateur = '==';
                }
                $propres['lignes'][] = array(
                    'cmd'       => $cmd,
                    'operateur' => $operateur,
                    'valeur'    => isset($ligne['valeur']) && !is_array($ligne['valeur'])
                                   ? (string) $ligne['valeur'] : '',
                    /* `nom` n'est qu'un libellé de repli pour le journal et la
                     * modale : jamais relu pour décider, donc jamais faux au
                     * point de changer une décision après un renommage. */
                    'nom'       => isset($ligne['nom']) && !is_array($ligne['nom'])
                                   ? (string) $ligne['nom'] : '',
                );
            }
        }
        return $propres;
    }

    /* Les actions d'une règle, telles que scenarioExpression les attend. */
    private static function normaliserActions($_actions) {
        $propres = array();
        if (!is_array($_actions)) {
            return $propres;
        }
        foreach ($_actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $cmd = isset($action['cmd']) && !is_array($action['cmd']) ? trim((string) $action['cmd']) : '';
            if ($cmd === '') {
                continue;
            }
            $propres[] = array(
                /* Le nom lisible reste la référence : c'est lui que
                 * scenarioExpression::createAndExec sait exécuter et que
                 * jeedom.cmd.displayActionsOption sait rendre. `cmd_id` n'est
                 * qu'une résolution d'appoint, pour que health() sache dire
                 * qu'une référence est morte. */
                'cmd'     => $cmd,
                'cmd_id'  => isset($action['cmd_id']) ? max(0, (int) $action['cmd_id']) : 0,
                'options' => (isset($action['options']) && is_array($action['options']))
                             ? $action['options'] : array(),
            );
        }
        return $propres;
    }

    /*
     * Les heures d'un déclencheur « À heure fixe » : des « HH:MM » valides,
     * triées, sans doublon, vingt-quatre au plus.
     *
     * Une liste ou une chaîne : un JSON écrit à la main porte volontiers
     * "21:30, 22:00", et le refuser rendrait une règle muette pour une virgule.
     * Chaque heure passe par heure(), la même lecture que la plage horaire —
     * « 7:5 », « 07h05 », « 0705 » —, pour qu'une même saisie ne vaille pas
     * deux choses selon le champ où on l'a tapée.
     *
     * Une heure illisible est RETIRÉE, pas remplacée : l'heure de repli d'une
     * borne de plage (00:00) serait ici une heure où la règle agit, que
     * personne n'a choisie. Une liste qui se retrouve vide est gardée vide —
     * la règle ne se déclenche alors jamais, la page Santé le signale, et
     * l'éditeur refuse de l'enregistrer ainsi.
     *
     * Le tri n'est pas cosmétique : une liste triée se relit comme une soirée
     * (21:30, 22:00… 00:00, 00:30 — minuit en tête, puisqu'une heure appartient
     * à sa journée), et deux listes équivalentes s'écrivent pareil, ce qui
     * compte pour la signature que mémorise le moteur (voir signatureHeures()).
     */
    public static function heuresFixes($_heures) {
        $brutes = array();
        if (is_array($_heures)) {
            $brutes = $_heures;
        } elseif (is_string($_heures) && trim($_heures) !== '') {
            $brutes = preg_split('/[\s,;]+/', trim($_heures));
        }
        $propres = array();
        foreach ($brutes as $brute) {
            if (is_array($brute) || is_object($brute) || is_bool($brute) || $brute === null) {
                continue;
            }
            $heure = self::heure((string) $brute, null);
            if ($heure !== null && !in_array($heure, $propres, true)) {
                $propres[] = $heure;
            }
        }
        sort($propres, SORT_STRING);
        return array_slice($propres, 0, self::HEURES_FIXES_MAX);
    }

    /*
     * Un identifiant de règle.
     *
     * Il doit survivre à la réorganisation de la liste : l'attente en cours et
     * le repos d'une règle sont mémorisés sous cet identifiant, et un simple
     * index de tableau les ferait glisser d'une règle à l'autre dès qu'on en
     * supprime une au milieu — la règle suivante hériterait du repos de celle
     * qu'on vient d'effacer.
     */
    public static function nouvelIdentifiant() {
        return 'r-' . substr(md5(uniqid('presencium', true) . mt_rand()), 0, 6);
    }

    /*
     * L'identifiant de repli d'une règle qui n'en porte pas : dérivé du rang,
     * donc le même à chaque lecture de la même configuration.
     *
     * Il n'est qu'un repli et ne remplace pas nouvelIdentifiant() : dès que la
     * liste est enregistrée, chaque règle garde SON identifiant, qui la suit
     * quand on réordonne. Le repli ne vaut que le temps qui sépare une
     * configuration écrite à la main de son premier enregistrement — mais
     * pendant ce temps, il doit être stable, faute de quoi rien de ce qui est
     * mémorisé par règle ne se retrouve.
     *
     * Le rang passe par un md5 plutôt que d'être écrit tel quel : « r-3 » est
     * exactement ce qu'un humain tape dans un JSON, et l'identifiant de repli
     * doit pouvoir se distinguer d'un identifiant choisi.
     */
    public static function identifiantDeRang($_rang) {
        return 'r-' . substr(md5('presencium::regle::' . (string) $_rang), 0, 6);
    }

    /* Le premier identifiant dérivé du rang qui ne soit pas déjà pris : deux
     * règles qui partageraient le même se partageraient aussi leur repos, et la
     * seconde se croirait au repos parce que la première vient d'agir. */
    private static function identifiantLibre($_rang, $_vus) {
        $candidat = self::identifiantDeRang($_rang);
        $essai = 0;
        while (isset($_vus[$candidat]) && $essai < 100) {
            $essai++;
            $candidat = self::identifiantDeRang($_rang . '::' . $essai);
        }
        return isset($_vus[$candidat]) ? self::nouvelIdentifiant() : $candidat;
    }

    /*
     * Compare deux instantanés et rend les déclencheurs franchis.
     *
     * $_avant / $_apres = array('presents' => array(idEqLogic => nom),
     *                           'total' => int, 'membres' => array(id, …))
     * `membres` (toutes les personnes du foyer) est facultatif : un instantané
     * mémorisé par une version antérieure n'en a pas.
     * Rend une liste de array('type' => …, 'personne' => 0|id).
     *
     * L'ordre est stable et se lit comme la scène se déroule :
     *
     *   arrivee_premier, puis chaque arrivee (par identifiant croissant), puis
     *   arrivee_tous, puis chaque depart, puis depart_dernier.
     *
     * Les arrivées passent avant les départs dans un même passage, et ce n'est
     * pas arbitraire : quand l'un rentre à la minute où l'autre sort, traiter
     * d'abord l'arrivée évite qu'une règle « la maison se vide » ne parte sur un
     * instantané qui n'a jamais existé. `arrivee_premier` ouvre la série et
     * `depart_dernier` la ferme, parce que la maison cesse d'être vide avant que
     * le premier ne soit entré, et ne devient vide qu'une fois le dernier sorti.
     */
    public static function transitions($_avant, $_apres) {
        $sortie = array();
        /*
         * Premier démarrage : pas d'instantané mémorisé, donc rien à comparer.
         * Prendre un cache vide pour une maison vide déclencherait, au premier
         * passage du cron suivant l'installation, un `arrivee_premier`, autant
         * d'`arrivee` que de personnes et probablement un `arrivee_tous` — et
         * symétriquement, un plugin qui s'installe pendant que personne n'est là
         * armerait l'alarme tout seul. Un plugin qui vient d'être installé ne
         * déclenche rien : il observe d'abord, il agit au changement suivant.
         */
        if (!self::estInstantane($_avant) || !self::estInstantane($_apres)) {
            return $sortie;
        }

        $presentsAvant = self::identifiants($_avant['presents']);
        $presentsApres = self::identifiants($_apres['presents']);
        $membresAvant = self::membres($_avant);
        $membresApres = self::membres($_apres);

        if ($membresAvant !== null && $membresApres !== null) {
            /*
             * Composition connue des deux côtés : seuls les membres présents
             * dans LES DEUX instantanés peuvent arriver ou partir. Une personne
             * ajoutée ou retirée n'a rien franchi — on vient de cocher ou de
             * décocher sa case — et les vrais fronts des autres membres, dans
             * le même passage, sortent quand même.
             *
             * Les présents sont bornés aux membres de leur instantané et les
             * totaux relus sur la liste, pour que les trois restent cohérents.
             */
            $presentsAvant = array_values(array_intersect($presentsAvant, $membresAvant));
            $presentsApres = array_values(array_intersect($presentsApres, $membresApres));
            $communs = array_intersect($membresAvant, $membresApres);
            $totalAvant = count($membresAvant);
            $totalApres = count($membresApres);
            $arrivees = array_values(array_intersect(array_diff($presentsApres, $presentsAvant), $communs));
            $departs = array_values(array_intersect(array_diff($presentsAvant, $presentsApres), $communs));
        } else {
            $totalAvant = isset($_avant['total']) ? max(0, (int) $_avant['total']) : count($presentsAvant);
            $totalApres = isset($_apres['total']) ? max(0, (int) $_apres['total']) : count($presentsApres);
            $arrivees = array_values(array_diff($presentsApres, $presentsAvant));
            $departs = array_values(array_diff($presentsAvant, $presentsApres));
            /*
             * Repli pour un instantané sans `membres` (version antérieure) : le
             * total est alors la seule trace d'un changement de composition, et
             * la garde est symétrique parce que les deux sens trompent pareil.
             *
             * Le total DIMINUE : une personne retirée disparaît comme si elle
             * partait, et l'alarme s'armerait en pleine séance de réglage.
             *
             * Le total AUGMENTE : une personne ajoutée apparaît comme si elle
             * arrivait, et « la maison n'est plus vide → désarmer » partirait
             * sur une occupation qui n'a pas bougé.
             *
             * Le prix : un vrai front du même passage est perdu POUR DE BON — le
             * passage suivant compare à cet instantané-ci, où il est déjà
             * acquis — et un échange à effectif constant passe inaperçu. D'où
             * `membres` ; ce repli ne sert qu'au passage qui suit la mise à jour.
             */
            if ($totalApres < $totalAvant) {
                $departs = array();
            }
            if ($totalApres > $totalAvant) {
                /* Vider les arrivées neutralise aussi `arrivee_premier` et
                 * `arrivee_tous`, qui exigent une arrivée dans ce passage. */
                $arrivees = array();
            }
        }
        sort($arrivees);
        sort($departs);

        /* Sur les présents RÉELS d'avant : une maison où restait quelqu'un
         * qu'on vient de retirer du foyer n'était pas vide. */
        if (count($presentsAvant) === 0 && count($arrivees) > 0) {
            $sortie[] = array('type' => 'arrivee_premier', 'personne' => 0);
        }
        foreach ($arrivees as $id) {
            $sortie[] = array('type' => 'arrivee', 'personne' => $id);
        }

        /*
         * « Tout le monde est là » ne se dit qu'au moment où le dernier manquant
         * arrive. D'où les deux garde-fous : il faut au moins une arrivée dans
         * ce passage — sinon retirer du foyer la seule personne absente
         * suffirait à l'annoncer — et le foyer ne devait pas déjà être au
         * complet avant, sinon ajouter une personne présente à la liste le
         * redéclencherait alors que l'état n'a pas changé.
         */
        $etaitComplet = ($totalAvant > 0 && count($presentsAvant) >= $totalAvant);
        if (count($arrivees) > 0 && $totalApres > 0 && !$etaitComplet
            && count($presentsApres) >= $totalApres) {
            $sortie[] = array('type' => 'arrivee_tous', 'personne' => 0);
        }

        foreach ($departs as $id) {
            $sortie[] = array('type' => 'depart', 'personne' => $id);
        }
        /* La maison ne devient vide que si quelqu'un en est sorti : un foyer
         * vidé de ses personnes dans la configuration n'est pas une maison qui
         * se vide. Un vrai départ qui, avec un retrait du même passage, laisse
         * la maison vide la vide bien : le foyer tel qu'il est s'est vidé. */
        if (count($departs) > 0 && count($presentsApres) === 0) {
            $sortie[] = array('type' => 'depart_dernier', 'personne' => 0);
        }

        return $sortie;
    }

    /* Les membres d'un instantané, en entiers, ou null s'il n'en porte pas. */
    private static function membres($_instantane) {
        if (!isset($_instantane['membres']) || !is_array($_instantane['membres'])) {
            return null;
        }
        $ids = array();
        foreach ($_instantane['membres'] as $id) {
            if (is_scalar($id) && is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /* Un instantané exploitable, par opposition à « pas encore d'instantané ».
     * La distinction est tout l'objet du garde-fou de transitions() : une
     * maison vide est array('presents' => array(), 'total' => 3), une absence
     * d'historique est null. */
    private static function estInstantane($_instantane) {
        return (is_array($_instantane) && isset($_instantane['presents']) && is_array($_instantane['presents']));
    }

    /* Les identifiants d'eqLogic d'un instantané, en entiers. Les clés d'un
     * tableau PHP relues depuis du JSON reviennent en chaînes : sans cette
     * conversion, array_diff comparerait '12' à 12 par leur écriture et la
     * moindre différence de forme inventerait un départ. */
    private static function identifiants($_presents) {
        $ids = array();
        foreach (array_keys($_presents) as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /*
     * La règle répond-elle à ce déclencheur ?
     *
     * Volontairement muette sur `actif` : c'est l'appelant qui teste la case et
     * journalise le verdict `desactivee`. Si cette méthode écartait les règles
     * inactives, elles disparaîtraient du journal, et l'utilisateur qui cherche
     * pourquoi « rien ne se passe » n'aurait aucune trace lui disant que sa
     * règle est simplement décochée.
     */
    public static function correspond($_regle, $_transition) {
        if (!is_array($_regle) || !is_array($_transition)) {
            return false;
        }
        $declencheur = isset($_regle['declencheur']) ? (string) $_regle['declencheur'] : '';
        $type = isset($_transition['type']) ? (string) $_transition['type'] : '';
        if ($declencheur === '' || $declencheur !== $type) {
            return false;
        }
        if ($declencheur === 'arrivee' || $declencheur === 'depart') {
            $personne = isset($_regle['personne']) ? (int) $_regle['personne'] : 0;
            /* 0 = n'importe qui. */
            if ($personne > 0) {
                $cible = isset($_transition['personne']) ? (int) $_transition['personne'] : 0;
                if ($personne !== $cible) {
                    return false;
                }
            }
        }
        return true;
    }

    /*
     * Le déclencheur d'une attente s'est-il inversé ?
     *
     * $_transition est celle mémorisée avec l'attente, $_instantane l'état
     * courant du foyer. Une attente d'arrivée est annulée par un départ, et
     * réciproquement : pour un déclencheur qui nomme quelqu'un, c'est l'état de
     * cette personne qui compte ; pour les autres, celui du foyer.
     *
     * Une entrée illisible rend false : dans le doute, l'attente continue, et
     * l'horaire et les conditions seront de toute façon relus au moment d'agir.
     */
    public static function declencheurInverse($_regle, $_transition, $_instantane) {
        if (!is_array($_regle) || !isset($_regle['declencheur']) || !self::estInstantane($_instantane)) {
            return false;
        }
        $presents = $_instantane['presents'];
        $nombre = count($presents);
        $total = isset($_instantane['total']) ? (int) $_instantane['total'] : 0;
        $personne = (is_array($_transition) && isset($_transition['personne']) && (int) $_transition['personne'] > 0)
                  ? (int) $_transition['personne'] : 0;

        switch ((string) $_regle['declencheur']) {
            case 'arrivee_premier':
            case 'occupee_depuis':
                return ($nombre === 0);
            case 'depart_dernier':
            case 'vide_depuis':
                return ($nombre > 0);
            case 'arrivee_tous':
                return ($total <= 0 || $nombre < $total);
            case 'arrivee':
                return ($personne > 0) ? !isset($presents[$personne]) : ($nombre === 0);
            case 'depart':
                /* « Quelqu'un part » ne s'annule que quand tout le monde est
                 * revenu : un autre départ ne le contredit pas. */
                return ($personne > 0) ? isset($presents[$personne]) : ($nombre >= $total && $total > 0);
            case 'heure':
                /*
                 * Une heure ne s'inverse pas : 21:30 est passé, rien ne le
                 * « dé-passera ». Il n'y a donc pas d'annulation par
                 * inversion pour ce déclencheur — ni de l'attente, ni de la
                 * relance.
                 *
                 * C'est voulu, et ce n'est pas un manque. Ce qu'on attendrait
                 * d'une inversion (« quelqu'un est reparti », « la télé s'est
                 * rallumée ») est un état du monde, et un état du monde se dit
                 * par une LIGNE DE CONDITION, relue au moment d'agir — après
                 * l'attente, à chaque relance. Une attente à heure fixe va
                 * donc toujours à son terme, puis la règle regarde : si la
                 * maison s'est vidée entre-temps, c'est « Présence du foyer ==
                 * 1 » qui dit non, et le journal dit laquelle.
                 *
                 * Ce qui met fin à une relance à heure fixe, outre sa durée,
                 * c'est l'heure SUIVANTE de la liste quand elle tombe pendant
                 * la série : voir presencium::traiterAttentes().
                 */
                return false;
        }
        return false;
    }

    /*
     * La signature d'une liste d'heures : ce qui dit au moteur que la liste a
     * changé depuis qu'il l'a mémorisée. Les heures sont déjà triées et
     * dédoublonnées par heuresFixes(), donc deux listes équivalentes ont la
     * même signature.
     */
    public static function signatureHeures($_regle) {
        $heures = (is_array($_regle) && isset($_regle['heures_fixes'])) ? self::heuresFixes($_regle['heures_fixes']) : array();
        return implode(',', $heures);
    }

    /*
     * La mémoire d'une règle « À heure fixe », prête à servir.
     *
     * Elle tient en trois clés, rangées par le moteur dans la section
     * `temporel` de l'état du foyer, sous l'identifiant de la règle — la même
     * section et la même clé que l'épisode de vide_depuis, qui joue le même
     * rôle (« ceci a déjà été présenté ») :
     *
     *   - `echeance`  : l'instant de la dernière heure présentée — celle de
     *                   21:30 aujourd'hui, par exemple, en horodatage. C'est
     *                   ce qui fait qu'une heure ne tombe qu'UNE fois : le
     *                   passage suivant du cron la trouve déjà jouée ;
     *   - `vue`       : l'instant, arrondi à la minute, où le moteur a vu cette
     *                   liste d'heures pour la première fois. Aucune heure
     *                   antérieure n'est jouée : c'est l'absence de
     *                   rétroactivité ;
     *   - `signature` : la liste telle qu'elle était alors (signatureHeures()).
     *
     * Une mémoire absente, illisible — un entier laissé par un ancien
     * déclencheur vide_depuis de la même règle — ou dont la signature ne
     * correspond plus est REFAITE, avec `vue` à la minute courante. Sans cela,
     * une règle créée à 22:02, ou à laquelle on vient d'ajouter 22:00, jouerait
     * 22:00 dans la foulée grâce au rattrapage : la règle agirait pour une
     * heure qui était déjà passée quand on l'a écrite, ce que personne ne lit
     * dans « À 22:00 ». Le prix, assumé : une règle enregistrée à 21:29:50 que
     * le cron ne voit qu'à 21:31 manque 21:30 — elle n'existait pas pour lui à
     * 21:30.
     *
     * L'arrondi à la minute est ce qui laisse passer le cas ordinaire : la
     * règle enregistrée à 21:29, vue par le passage de 21:30:02, a `vue` =
     * 21:30:00 et joue bien 21:30.
     *
     * Rend la mémoire telle qu'elle doit être ; au moteur de l'écrire si elle
     * a changé. Pure : elle ne lit ni n'écrit rien.
     */
    public static function memoireHeureFixe($_regle, $_maintenant, $_memoire) {
        $signature = self::signatureHeures($_regle);
        if (is_array($_memoire) && isset($_memoire['vue'], $_memoire['signature'])
            && (string) $_memoire['signature'] === $signature) {
            return array(
                'echeance'  => isset($_memoire['echeance']) ? (int) $_memoire['echeance'] : 0,
                'vue'       => (int) $_memoire['vue'],
                'signature' => $signature,
            );
        }
        $maintenant = (int) $_maintenant;
        return array(
            'echeance'  => 0,
            'vue'       => $maintenant - ($maintenant % 60),
            'signature' => $signature,
        );
    }

    /*
     * L'heure fixe qui tombe maintenant, ou null.
     *
     * Rend array('heure' => 'HH:MM', 'echeance' => horodatage) pour l'heure de
     * la liste à jouer à cet instant. Une heure est à jouer quand, à la fois :
     *
     *   - elle est passée, mais depuis moins de RATTRAPAGE_HEURE minutes : voir
     *     la constante pour la borne ;
     *   - elle n'est pas antérieure à `vue` : pas de rétroactivité ;
     *   - elle est postérieure à `echeance` : une fois par jour et par heure.
     *
     * L'heure est toujours datée du JOUR COURANT, et c'est ce qui règle le
     * passage de minuit. 00:30 est calculé sur la date du jour qui commence :
     * le 30 à 00:31, c'est « le 30 à 00:30 », une échéance distincte de celle
     * de la veille, qui tombe donc bien chaque nuit. Et une heure de la veille
     * n'est JAMAIS rattrapée : le 30 à 00:02, 23:59 est calculé sur le 30,
     * c'est-à-dire dans presque vingt-quatre heures, pas il y a trois minutes.
     * Le cas est rare (le cron en retard de plusieurs minutes pile à minuit),
     * et le perdre vaut mieux que l'alternative : pour le rattraper, il
     * faudrait dater chaque heure de la veille OU du jour selon l'instant, et
     * c'est exactement dans cette arithmétique-là qu'une heure finit jouée deux
     * fois — une fois comme « hier 23:59 », une fois comme « aujourd'hui
     * 23:59 ».
     *
     * L'heure est posée par mktime(), dans le fuseau de Jeedom, comme
     * horaireOk() lit les siennes : aux changements d'heure, 02:30 qui n'existe
     * pas tombe à 03:30, et 02:30 qui existe deux fois ne tombe qu'une fois —
     * la seconde est à une heure de l'échéance mémorisée, hors rattrapage.
     *
     * Deux heures de la liste dans la même fenêtre de rattrapage (22:00 et
     * 22:02, un cron qui reprend à 22:03) ne donnent qu'UN déclenchement, celui
     * de la plus récente : jouer deux fois la même règle dans la même minute,
     * sur le même état de la maison, ne dit rien de plus et agit deux fois.
     *
     * Pure, comme le reste de cette classe : c'est ce qui permet d'éprouver
     * minuit, le rattrapage et la rétroactivité hors ligne, en fixant l'heure.
     */
    public static function heureFixeDue($_regle, $_maintenant, $_memoire) {
        if (!is_array($_regle) || !isset($_regle['declencheur']) || (string) $_regle['declencheur'] !== 'heure') {
            return null;
        }
        /* Une mémoire que memoireHeureFixe() n'a pas encore posée : le moteur
         * n'a pas vu la liste, rien n'est à jouer. */
        if (!is_array($_memoire) || !isset($_memoire['vue'], $_memoire['signature'])
            || (string) $_memoire['signature'] !== self::signatureHeures($_regle)) {
            return null;
        }
        $maintenant = (int) $_maintenant;
        $vue = (int) $_memoire['vue'];
        $derniere = isset($_memoire['echeance']) ? (int) $_memoire['echeance'] : 0;
        $annee = (int) date('Y', $maintenant);
        $mois = (int) date('n', $maintenant);
        $jour = (int) date('j', $maintenant);

        $retenue = null;
        foreach (self::heuresFixes($_regle['heures_fixes']) as $heure) {
            list($h, $m) = explode(':', $heure);
            $echeance = mktime((int) $h, (int) $m, 0, $mois, $jour, $annee);
            if ($echeance === false || $echeance > $maintenant) {
                continue;
            }
            if ($maintenant - $echeance >= self::RATTRAPAGE_HEURE * 60) {
                continue;
            }
            if ($echeance < $vue || $echeance <= $derniere) {
                continue;
            }
            if ($retenue === null || $echeance > $retenue['echeance']) {
                $retenue = array('heure' => $heure, 'echeance' => $echeance);
            }
        }
        return $retenue;
    }

    /*
     * Compare la valeur d'une commande à celle attendue par une condition.
     *
     * Ne lève jamais, quoi qu'on lui donne : elle est appelée en pleine
     * évaluation d'un foyer, et une exception ici priverait de décision toutes
     * les règles suivantes.
     *
     * Deux pièges valent le détour :
     *
     *   - la comparaison doit être numérique dès que les deux côtés le sont,
     *     sinon '10' > '9' serait faux — PHP 8 compare deux chaînes non
     *     numériques caractère par caractère — et un seuil de luminosité ou de
     *     température se tromperait une fois sur dix sans jamais lever ;
     *   - l'égalité numérique passe par un epsilon : les valeurs viennent de
     *     capteurs et transitent par du JSON, où 21.3 revient parfois
     *     21.299999999999997, et une égalité stricte sur des flottants ne serait
     *     jamais vraie.
     *
     * Hors nombres, la comparaison est textuelle et insensible à la casse : la
     * même passerelle publie « on » ou « ON » selon la version de son firmware.
     * Un opérateur inconnu rend false et non true : une règle corrompue ne doit
     * pas se croire satisfaite.
     *
     * Une valeur VIDE (commande jamais collectée, null) est inconnue : elle ne
     * satisfait aucun opérateur, sauf `==` contre une valeur attendue vide.
     * En particulier « inconnu != on » est faux — inconnu n'est pas différent,
     * et une condition « porte != ouverte » ne doit pas autoriser une action
     * sur un capteur muet.
     */
    public static function comparer($_valeur, $_operateur, $_attendu) {
        if (!is_string($_operateur) || !in_array($_operateur, self::OPERATEURS, true)) {
            return false;
        }
        $valeur = self::scalaire($_valeur);
        $attendu = self::scalaire($_attendu);
        if ($valeur === null || $attendu === null) {
            return false;
        }
        if ($valeur === '') {
            return ($_operateur === '==' && $attendu === '');
        }

        if (is_numeric($valeur) && is_numeric($attendu)) {
            $a = (float) $valeur;
            $b = (float) $attendu;
            switch ($_operateur) {
                case '==': return (abs($a - $b) < 0.000001);
                case '!=': return (abs($a - $b) >= 0.000001);
                case '>':  return ($a > $b);
                case '>=': return ($a >= $b);
                case '<':  return ($a < $b);
                case '<=': return ($a <= $b);
            }
            return false;
        }

        $comparaison = strcasecmp($valeur, $attendu);
        switch ($_operateur) {
            case '==': return ($comparaison === 0);
            case '!=': return ($comparaison !== 0);
            case '>':  return ($comparaison > 0);
            case '>=': return ($comparaison >= 0);
            case '<':  return ($comparaison < 0);
            case '<=': return ($comparaison <= 0);
        }
        return false;
    }

    /* Ramène ce qui arrive d'une commande à une chaîne comparable, ou null si
     * ce n'est pas comparable du tout. null devient '' et non '0' : une
     * commande jamais collectée n'est pas zéro. */
    private static function scalaire($_valeur) {
        if ($_valeur === null) {
            return '';
        }
        if (is_bool($_valeur)) {
            return $_valeur ? '1' : '0';
        }
        if (is_array($_valeur) || is_object($_valeur)) {
            return null;
        }
        return trim((string) $_valeur);
    }

    /*
     * La règle a-t-elle le droit d'agir à cet instant ?
     *
     * Jours et heures ensemble : les deux forment la même condition temporelle
     * et produisent le même verdict `hors_horaire` dans le journal.
     *
     * Le cas qui compte est la plage qui franchit minuit, 22:00 -> 06:00. Deux
     * erreurs y sont classiques. La première est de tester `de <= maintenant <=
     * a`, qui pour 22:00 -> 06:00 n'est jamais vrai : la règle ne partirait
     * jamais, la nuit, c'est-à-dire précisément quand on l'a écrite. La seconde
     * est plus sournoise : à 01:00, la plage nocturne appartient à la nuit de la
     * veille, et c'est le jour de la veille qu'il faut confronter aux cases
     * cochées. « Du lundi au vendredi, 22:00 -> 06:00 » doit couvrir le samedi
     * à 5 h du matin, qui est encore le vendredi soir pour celui qui a coché
     * les cases — sinon la règle s'arrête au milieu de la nuit.
     */
    public static function horaireOk($_regle, $_maintenant) {
        if (!is_array($_regle)) {
            return false;
        }
        $conditions = (isset($_regle['conditions']) && is_array($_regle['conditions']))
            ? $_regle['conditions'] : array();

        $maintenant = (int) $_maintenant;
        $jour = (int) date('N', $maintenant);
        $minute = ((int) date('G', $maintenant)) * 60 + ((int) date('i', $maintenant));

        $heures = (isset($conditions['heures']) && is_array($conditions['heures']))
            ? $conditions['heures'] : array();
        $actif = isset($heures['actif']) ? self::caseCochee($heures['actif']) : 0;

        if ($actif === 1) {
            $de = self::minutesDuJour(self::heure(isset($heures['de']) ? $heures['de'] : '', '00:00'));
            $a = self::minutesDuJour(self::heure(isset($heures['a']) ? $heures['a'] : '', '23:59'));
            if ($de === $a) {
                /* Début et fin confondus : toute la journée, pas une minute.
                 * Une plage d'une minute ne partirait presque jamais, sans que
                 * rien ne dise pourquoi. */
            } elseif ($de < $a) {
                if ($minute < $de || $minute > $a) {
                    return false;
                }
            } else {
                if ($minute > $a && $minute < $de) {
                    return false;
                }
                /* Deuxième moitié d'une plage nocturne : on est après minuit,
                 * donc rattaché au jour de la veille. */
                if ($minute <= $a) {
                    $jour = ($jour === 1) ? 7 : $jour - 1;
                }
            }
        }

        if (isset($conditions['jours']) && is_array($conditions['jours']) && count($conditions['jours']) > 0) {
            $jours = array();
            foreach ($conditions['jours'] as $coche) {
                $jours[] = (int) $coche;
            }
            if (!in_array($jour, $jours, true)) {
                return false;
            }
        }
        return true;
    }

    /*
     * Le déclencheur d'une règle, en français, pour le journal et la liste.
     *
     * $_noms = array(idEqLogic => nom). Un identifiant inconnu — personne
     * supprimée, foyer réorganisé — se rend lisible plutôt que de vider la
     * phrase : « Arrivée de la personne #42 » se cherche, « Arrivée de » ne se
     * comprend pas.
     *
     * $_transition, facultative, est celle qui a déclenché : seul `heure` s'en
     * sert, pour dire laquelle de ses heures est tombée.
     */
    public static function libelleDeclencheur($_regle, $_noms, $_transition = null) {
        $declencheur = (is_array($_regle) && isset($_regle['declencheur']))
            ? (string) $_regle['declencheur'] : '';
        $personne = (is_array($_regle) && isset($_regle['personne'])) ? (int) $_regle['personne'] : 0;
        $minutes = (is_array($_regle) && isset($_regle['minutes'])) ? (int) $_regle['minutes'] : 0;

        switch ($declencheur) {
            case 'arrivee_premier':
                return self::traduire('La maison n\'est plus vide');
            case 'depart_dernier':
                return self::traduire('La maison devient vide');
            case 'arrivee_tous':
                return self::traduire('Tout le monde est là');
            case 'arrivee':
                if ($personne <= 0) {
                    return self::traduire('Quelqu\'un arrive');
                }
                return self::traduire('Arrivée de') . ' ' . self::nomPersonne($personne, $_noms);
            case 'depart':
                if ($personne <= 0) {
                    return self::traduire('Quelqu\'un part');
                }
                return self::traduire('Départ de') . ' ' . self::nomPersonne($personne, $_noms);
            case 'vide_depuis':
                return self::traduire('Vide depuis') . ' ' . $minutes . ' min';
            case 'occupee_depuis':
                return self::traduire('Occupée depuis') . ' ' . $minutes . ' min';
            case 'heure':
                /* L'heure QUI a déclenché quand on la connaît — « À 21:30 » —,
                 * parce que c'est la question qu'on pose au journal : laquelle
                 * des six heures a armé l'alarme ? Sans transition (liste des
                 * règles, bouton Tester, suite d'actions différée), la liste
                 * entière, qui est alors la seule chose vraie à dire. */
                if (is_array($_transition) && isset($_transition['heure'])
                    && preg_match('/^\d{2}:\d{2}$/', (string) $_transition['heure'])) {
                    return sprintf(self::traduire('À %s'), (string) $_transition['heure']);
                }
                $heures = (is_array($_regle) && isset($_regle['heures_fixes'])) ? self::heuresFixes($_regle['heures_fixes']) : array();
                if (count($heures) === 0) {
                    return self::traduire('À heure fixe (aucune heure)');
                }
                return sprintf(self::traduire('À %s'), implode(', ', $heures));
        }
        return self::traduire('Déclencheur inconnu');
    }

    private static function nomPersonne($_id, $_noms) {
        if (is_array($_noms) && isset($_noms[$_id]) && trim((string) $_noms[$_id]) !== '') {
            return trim((string) $_noms[$_id]);
        }
        return self::traduire('la personne') . ' #' . (int) $_id;
    }

    /* Une case à cocher, dans tous ses habits : '1', 1, true, 'on'. */
    private static function caseCochee($_valeur) {
        if ($_valeur === true || $_valeur === 1 || $_valeur === '1' || $_valeur === 'on') {
            return 1;
        }
        return (is_numeric($_valeur) && (int) $_valeur === 1) ? 1 : 0;
    }

    /* Un entier borné, ou le minimum si ce n'en est pas un — ou $_defaut s'il
     * est donné : quand le minimum n'est pas un « non » raisonnable (la durée
     * de relance, dont le plancher d'une minute la rendrait inopérante), une
     * saisie illisible doit retomber sur le défaut, pas sur la borne. */
    private static function entierBorne($_valeur, $_min, $_max, $_defaut = null) {
        if (is_bool($_valeur) || is_array($_valeur) || $_valeur === null || !is_numeric($_valeur)) {
            return ($_defaut === null) ? $_min : $_defaut;
        }
        return max($_min, min($_max, (int) $_valeur));
    }

    /* « 7:5 », « 07h05 », « 0705 » — ce qu'un humain tape pour une heure,
     * ramené à HH:MM, ou le défaut si ce n'en est pas une. Avec séparateur,
     * les minutes ont un ou deux chiffres (« 7:5 » = 07:05) ; sans, deux
     * exactement (« 705 » = 07:05). Contrairement à un
     * garde-fou facultatif, une borne d'horaire vide ne doit pas rester vide :
     * elle serait lue comme minuit et fermerait la plage. */
    private static function heure($_valeur, $_defaut) {
        $valeur = is_array($_valeur) ? '' : trim((string) $_valeur);
        if ($valeur === '' || !preg_match('/^(\d{1,2})(?:[:hH.](\d{1,2})|(\d{2}))$/', $valeur, $morceaux)) {
            return $_defaut;
        }
        $heures = (int) $morceaux[1];
        $minutes = (int) ((isset($morceaux[2]) && $morceaux[2] !== '') ? $morceaux[2] : $morceaux[3]);
        if ($heures > 23 || $minutes > 59) {
            return $_defaut;
        }
        return sprintf('%02d:%02d', $heures, $minutes);
    }

    private static function minutesDuJour($_heure) {
        $morceaux = explode(':', (string) $_heure);
        if (count($morceaux) !== 2) {
            return 0;
        }
        return ((int) $morceaux[0]) * 60 + ((int) $morceaux[1]);
    }

    /*
     * Traduction facultative : la classe tourne aussi hors de Jeedom, où __()
     * n'existe pas et où l'appeler ferait tomber le jeu d'essai sur un « Call to
     * undefined function » avant le premier contrôle.
     */
    private static function traduire($_texte) {
        if (function_exists('__')) {
            return __($_texte, __FILE__);
        }
        return $_texte;
    }
}
