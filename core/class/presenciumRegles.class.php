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

    const DECLENCHEURS = array('arrivee_premier', 'depart_dernier', 'arrivee_tous',
                               'arrivee', 'depart', 'vide_depuis', 'occupee_depuis');
    const OPERATEURS = array('==', '!=', '>', '>=', '<', '<=');

    /* Douze heures d'attente, vingt-quatre heures de repos : au-delà, la règle
     * ne se comprend plus et surtout ne se vérifie plus — personne ne se
     * souvient d'une attente commencée avant-hier. */
    const ATTENTE_MAX = 720;
    const REPOS_MAX = 1440;

    /* Borne du `minutes` de vide_depuis / occupee_depuis : une semaine. Un
     * réglage plus grand ne se déclencherait jamais, ce qui ressemble
     * exactement à un plugin en panne. */
    const MINUTES_MAX = 10080;

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
        $regle['attente'] = self::entierBorne(isset($_regle['attente']) ? $_regle['attente'] : 0, 0, self::ATTENTE_MAX);
        $regle['repos'] = self::entierBorne(isset($_regle['repos']) ? $_regle['repos'] : 0, 0, self::REPOS_MAX);
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
     * $_avant / $_apres = array('presents' => array(idEqLogic => nom), 'total' => int)
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
        $totalAvant = isset($_avant['total']) ? max(0, (int) $_avant['total']) : count($presentsAvant);
        $totalApres = isset($_apres['total']) ? max(0, (int) $_apres['total']) : count($presentsApres);

        $arrivees = array_values(array_diff($presentsApres, $presentsAvant));
        $departs = array_values(array_diff($presentsAvant, $presentsApres));
        sort($arrivees);
        sort($departs);

        /*
         * La composition du foyer a changé : ce passage ne déclenche rien du
         * côté qui vient de bouger. Le total est la seule trace disponible d'un
         * changement de composition, et la garde est symétrique parce que les
         * deux sens produisent la même illusion.
         *
         * Le total DIMINUE : une personne retirée du foyer disparaît de
         * l'instantané exactement comme si elle venait de partir. Elle n'est pas
         * partie — on vient de décocher sa case — et déclencher là-dessus
         * armerait l'alarme au beau milieu d'une séance de réglage, le pire
         * moment puisque l'utilisateur a la page ouverte et les mains dans le
         * plugin.
         *
         * Le total AUGMENTE : une personne ajoutée au foyer apparaît dans
         * l'instantané exactement comme si elle venait d'arriver. Cocher une
         * troisième personne déjà chez elle et enregistrer produirait sinon
         * `arrivee`, et `arrivee_premier` ou `arrivee_tous` avec — donc une
         * règle « la maison n'est plus vide → désarmer » qui part toute seule,
         * pendant le réglage, sur une maison dont l'occupation n'a pas bougé
         * d'un cheveu.
         *
         * Dans les deux cas, un vrai front survenu dans la même minute est
         * perdu ; il sera vu au passage suivant, la maison ne bouge pas si vite.
         */
        if ($totalApres < $totalAvant) {
            $departs = array();
        }
        if ($totalApres > $totalAvant) {
            /* Vider les arrivées suffit à neutraliser `arrivee_premier` et
             * `arrivee_tous` : l'un comme l'autre exigent une arrivée dans ce
             * passage. */
            $arrivees = array();
        }

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
        /* La maison ne devient vide que si quelqu'un en est sorti : sans cette
         * condition, un foyer vidé de ses personnes dans la configuration
         * passerait pour une maison qui se vide. */
        if (count($departs) > 0 && count($presentsApres) === 0) {
            $sortie[] = array('type' => 'depart_dernier', 'personne' => 0);
        }

        return $sortie;
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
            if ($de <= $a) {
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
     */
    public static function libelleDeclencheur($_regle, $_noms) {
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

    /* Un entier borné, ou le minimum si ce n'en est pas un. */
    private static function entierBorne($_valeur, $_min, $_max) {
        if (is_bool($_valeur) || is_array($_valeur) || $_valeur === null || !is_numeric($_valeur)) {
            return $_min;
        }
        return max($_min, min($_max, (int) $_valeur));
    }

    /* « 7:5 », « 07h05 », « 0705 » — ce qu'un humain tape pour une heure,
     * ramené à HH:MM, ou le défaut si ce n'en est pas une. Contrairement à un
     * garde-fou facultatif, une borne d'horaire vide ne doit pas rester vide :
     * elle serait lue comme minuit et fermerait la plage. */
    private static function heure($_valeur, $_defaut) {
        $valeur = is_array($_valeur) ? '' : trim((string) $_valeur);
        if ($valeur === '' || !preg_match('/^(\d{1,2})[:hH.]?(\d{2})$/', $valeur, $morceaux)) {
            return $_defaut;
        }
        $heures = (int) $morceaux[1];
        $minutes = (int) $morceaux[2];
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
