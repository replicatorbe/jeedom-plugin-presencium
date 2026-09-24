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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
     * les deux classes hors ligne se chargent par elle, et une action qui
     * commencerait par l'une d'elles mourrait sur « Class not found ». */
    require_once __DIR__ . '/../class/presencium.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /*
     * Récupère un équipement du plugin, et seulement du plugin.
     *
     * eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans cette revérification, un identifiant étranger venu du
     * navigateur ferait agir le plugin sur l'équipement d'un autre — vider son
     * « journal », jouer ses « règles ». Un identifiant reçu n'est jamais utilisé
     * sans contrôle, même derrière isConnect('admin') : l'interface d'un
     * administrateur est aussi ce qu'une page tierce peut lui faire ouvrir.
     */
    $getEqLogic = function ($_id, $_type = null) {
        $eqLogic = presencium::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'presencium') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        /* Le type est revérifié pour la même raison : les règles n'existent
         * que sur un foyer, et les demander à une personne rendrait une erreur
         * PHP là où une phrase claire est attendue.
         *
         * Par type() et jamais par getConfiguration('type') : un équipement
         * dont le champ a été perdu — copie, restauration partielle,
         * configuration éditée à la main — est une personne pour tout le reste
         * du plugin, et était ici un foyer. La page refusait alors de lui
         * parler, ou pire, lui appliquait le traitement d'un foyer. */
        if ($_type !== null && $eqLogic->type() !== $_type) {
            if ($_type == presencium::TYPE_FOYER) {
                throw new Exception(__('Cet équipement n\'est pas un foyer.', __FILE__));
            }
            throw new Exception(__('Cet équipement n\'est pas une personne.', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Crée une personne ou un foyer.
     *
     * Le plugin a deux types d'équipement et le bouton « Ajouter » du coeur n'en
     * connaît qu'un : sans ce point d'entrée, tout équipement neuf naîtrait sans
     * type et n'afficherait ni l'un ni l'autre des deux panneaux.
     */
    if (init('action') == 'ajouter') {
        unautorizedInDemo();
        $type = init('type');
        if ($type != presencium::TYPE_PERSONNE && $type != presencium::TYPE_FOYER) {
            /* La valeur reçue n'est pas renvoyée : elle serait réinjectée
             * telle quelle dans la page. */
            throw new Exception(__('Type d\'équipement inconnu.', __FILE__));
        }
        $nom = trim(init('nom'));
        if ($nom === '') {
            throw new Exception(__('Le nom est obligatoire.', __FILE__));
        }

        $eqLogic = new presencium();
        $eqLogic->setName($nom);
        $eqLogic->setEqType_name('presencium');
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setConfiguration('type', $type);
        if ($type == presencium::TYPE_PERSONNE) {
            /* Les délais proposés viennent de la configuration du plugin : c'est
             * là que l'utilisateur règle une bonne fois ce que valent ses
             * balises, au lieu de le retaper sur chaque personne. */
            $eqLogic->setConfiguration('delai_depart', config::byKey('delai_depart', 'presencium', 15));
            $eqLogic->setConfiguration('delai_arrivee', config::byKey('delai_arrivee', 'presencium', 0));
            $eqLogic->setConfiguration('valeur_presente', '1');
            $eqLogic->setConfiguration('source', 0);
        } else {
            /* Un foyer neuf rassemble les personnes déjà déclarées. Le partir
             * vide paraît neutre et ne l'est pas : on écrit ses règles, on
             * enregistre, et rien ne se déclenche jamais — sans erreur, sans
             * message, parce qu'un foyer sans habitant est vide en permanence.
             * Décocher quelqu'un prend un clic ; comprendre pourquoi rien ne
             * part prend une soirée. */
            $membres = array();
            foreach (presencium::byType('presencium') as $candidat) {
                if ($candidat->type() === presencium::TYPE_PERSONNE) {
                    $membres[] = (int) $candidat->getId();
                }
            }
            $eqLogic->setConfiguration('personnes', $membres);
            $eqLogic->setConfiguration('regles', array());
            $eqLogic->setConfiguration('simulation', 0);
        }
        $eqLogic->save();
        ajax::success(utils::o2a($eqLogic));
    }

    /* Les personnes déclarées, pour la composition d'un foyer et pour les règles
     * nominatives. L'état courant part avec, pour qu'on reconnaisse la bonne
     * personne sans quitter la page. */
    if (init('action') == 'personnes') {
        $retour = array();
        foreach (eqLogic::byType('presencium') as $eqLogic) {
            if ($eqLogic->type() !== presencium::TYPE_PERSONNE) {
                continue;
            }
            $presence = null;
            $cmd = $eqLogic->getCmd('info', 'presence');
            if (is_object($cmd)) {
                $presence = $cmd->execCmd();
            }
            $objet = $eqLogic->getObject();
            $retour[] = array(
                'id'       => (int) $eqLogic->getId(),
                'nom'      => $eqLogic->getName(),
                'objet'    => is_object($objet) ? $objet->getName() : '',
                'presence' => $presence,
            );
        }
        ajax::success($retour);
    }

    /* Les commandes qui ressemblent à une source de présence. Le sélecteur du
     * coeur ouvre TOUTES les commandes de l'installation : y retrouver la bonne
     * balise demande d'en connaître le nom exact, ce que personne n'a en tête. */
    if (init('action') == 'sources') {
        ajax::success(presencium::sourcesCandidates());
    }

    /* Le journal d'un foyer, du plus récent au plus ancien, filtré par genre.
     * C'est l'outil de détection des faux positifs : il se lit après coup, et
     * le filtre est ce qui permet d'isoler les rebonds de présence du reste. */
    /* Le journal n'est plus réservé au foyer : une personne tient le sien, et
     * c'est le seul endroit où se lisent ses rebonds tant qu'elle n'appartient
     * à aucun foyer. */
    if (init('action') == 'journal') {
        /* Sans type imposé : la 1.1 donne un journal aux deux familles, mais
         * ce point d'entrée exigeait toujours un foyer. L'onglet Journal d'une
         * personne s'ouvrait donc sur « Cet équipement n'est pas un foyer » —
         * et ses rebonds absorbés, seule trace d'une balise qui hoquette
         * quand elle n'entre dans aucun foyer, restaient illisibles. */
        $eqLogic = $getEqLogic(init('id'));
        $limite = (int) init('limite', 200);
        if ($limite < 1) {
            $limite = 1;
        }
        if ($limite > 1000) {
            $limite = 1000;
        }
        /*
         * Le genre est passé à journalLire(), qui filtre AVANT de tronquer.
         *
         * Filtrer après coup, sur les N dernières entrées seulement, faisait
         * afficher trois lignes au filtre « Présence » dès que les règles
         * avaient rempli le journal — et laissait croire que les balises ne
         * rebondissaient pas. Sur l'écran même qui sert à détecter les faux
         * positifs, c'est le pire mensonge possible : on en conclut que le
         * délai de départ peut être raccourci.
         */
        /*
         * Tout lire, compter, puis tronquer — et dire les deux nombres.
         *
         * La page n'affiche que les deux cents dernières entrées, mais le
         * journal en garde mille : sans le total, « 200 entrées depuis mardi »
         * laissait croire que la campagne commençait mardi, alors qu'elle
         * remontait peut-être à la semaine précédente. Et quand le journal est
         * plein, ce sont les plus anciennes qui ont disparu pour de bon : c'est
         * exactement ce qu'il faut savoir avant de conclure quoi que ce soit de
         * ce qu'on lit.
         */
        $toutes = $eqLogic->journalLire(presencium::JOURNAL_TAILLE_MAX, (string) init('genre'));
        if (!is_array($toutes)) {
            $toutes = array();
        }
        ajax::success(array(
            'entrees' => array_slice($toutes, 0, $limite),
            'total'   => count($toutes),
            'taille'  => presencium::journalTaille(),
        ));
    }

    if (init('action') == 'viderJournal') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        $eqLogic->journalVider();
        ajax::success(true);
    }

    /*
     * Joue une règle à la demande, telle qu'elle est enregistrée.
     *
     * La règle est reprise dans la configuration et non reçue du navigateur :
     * accepter une règle envoyée par la page ferait de ce point d'entrée un
     * « exécute ces actions-là », c'est-à-dire n'importe quelle commande de
     * l'installation, déguisé en bouton d'essai. C'est aussi plus honnête : le
     * test doit jouer ce qui est enregistré, pas ce qui est en cours de saisie.
     *
     * La règle est désignée par son IDENTIFIANT et non par son rang. Le rang
     * était celui du tableau tel que la page l'affichait : réordonner la liste
     * sans enregistrer — l'éditeur travaille sur une copie — faisait tester, et
     * donc exécuter pour de vrai, une autre règle que celle qu'on avait sous
     * les yeux. Un bouton « Tester » qui arme l'alarme quand on croyait
     * allumer une lampe.
     */
    if (init('action') == 'testerRegle') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'), presencium::TYPE_FOYER);
        $regle = $eqLogic->regleParId(init('regle'));
        if ($regle === null) {
            /* Identifiant inconnu : c'est presque toujours une règle qui vient
             * d'être créée dans la modale et pas encore enregistrée. On le dit
             * au lieu de tester la première venue. */
            throw new Exception(__('Cette règle n\'est pas (encore) enregistrée : validez-la, enregistrez le foyer, puis testez.', __FILE__));
        }
        $maintenant = time();
        /*
         * Le contexte est un tableau de clés nommées — executerRegle() y lit
         * `instantane`, `transition` et `attente_terminee`. L'instantané y
         * était passé NU : la clé `instantane` n'existait pas, l'essai
         * recalculait donc les présences pour son compte et pouvait expliquer
         * son verdict par un état différent de celui qu'on lui avait tendu.
         */
        /* `simuler` (case cochée à l'écran) force la simulation de l'essai ;
         * il ne peut que l'ajouter, jamais retirer celle de la configuration. */
        ajax::success($eqLogic->executerRegle($regle, $maintenant, array(
            'instantane' => $eqLogic->instantane($maintenant),
            'simuler'    => ((int) init('simuler', 0) === 1),
        ), true));
    }

    /* Réévalue tout de suite, sans attendre la minute suivante du cron. */
    if (init('action') == 'evaluer') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        $maintenant = time();
        if ($eqLogic->type() === presencium::TYPE_PERSONNE) {
            ajax::success($eqLogic->rafraichirPersonne($maintenant));
        }
        ajax::success($eqLogic->rafraichirFoyer($maintenant));
    }

    /* L'état courant, en lecture seule : rien n'est écrit, aucune commande n'est
     * publiée, aucune règle n'est jouée. C'est ce qui permet de rafraîchir
     * l'affichage sans que regarder une page change quoi que ce soit. */
    if (init('action') == 'verdict') {
        $eqLogic = $getEqLogic(init('id'));
        $maintenant = time();
        if ($eqLogic->type() === presencium::TYPE_PERSONNE) {
            ajax::success($eqLogic->verdictPersonne($maintenant));
        }
        $instantane = $eqLogic->instantane($maintenant);
        ajax::success(array(
            'presents'   => isset($instantane['presents']) ? $instantane['presents'] : array(),
            'total'      => isset($instantane['total']) ? (int) $instantane['total'] : 0,
            'simulation' => $eqLogic->enSimulation() ? 1 : 0,
        ));
    }

    /* Forcer une présence depuis la fiche. Les commandes existent déjà, mais
     * invisibles : pour éprouver une règle il fallait sortir de chez soi. */
    if (init('action') == 'forcer') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'), presencium::TYPE_PERSONNE);
        $mode = init('mode');
        if (!in_array($mode, array('present', 'absent', 'auto'), true)) {
            throw new Exception(__('Mode inconnu.', __FILE__));
        }
        ajax::success($eqLogic->forcerPersonne($mode));
    }

    /* Le journal en CSV. Une semaine de campagne se relit dans un tableur, pas
     * dans une page web : c'est là qu'on trie par verdict et qu'on compte. */
    if (init('action') == 'journalCsv') {
        $eqLogic = $getEqLogic(init('id'));
        $lignes = array(array('date', 'genre', 'verdict', 'simulation', 'regle', 'detail', 'actions'));
        foreach ($eqLogic->journalLire(5000) as $entree) {
            $actions = array();
            if (isset($entree['actions']) && is_array($entree['actions'])) {
                foreach ($entree['actions'] as $action) {
                    $actions[] = (isset($action['cmd']) ? $action['cmd'] : '') . ' -> '
                               . (isset($action['resultat']) ? $action['resultat'] : '');
                }
            }
            $lignes[] = array(
                isset($entree['date']) ? $entree['date'] : '',
                isset($entree['genre']) ? $entree['genre'] : '',
                isset($entree['verdict']) ? $entree['verdict'] : '',
                empty($entree['simulation']) ? '0' : '1',
                isset($entree['nom']) ? $entree['nom'] : '',
                isset($entree['detail']) ? $entree['detail'] : '',
                implode(' | ', $actions),
            );
        }
        $csv = '';
        foreach ($lignes as $ligne) {
            $champs = array();
            foreach ($ligne as $champ) {
                $champs[] = '"' . str_replace('"', '""', (string) $champ) . '"';
            }
            $csv .= implode(';', $champs) . "\r\n";
        }
        ajax::success($csv);
    }

    /* Analyse l'historique de la balise d'une personne et propose un délai. */
    if (init('action') == 'analyser') {
        $eqLogic = $getEqLogic(init('id'), presencium::TYPE_PERSONNE);
        $source = (int) $eqLogic->getConfiguration('source', 0);
        if ($source <= 0) {
            throw new Exception(__('Cette personne ne suit aucune commande : il n\'y a rien à analyser.', __FILE__));
        }
        ajax::success(presencium::analyserSource(
            $source,
            init('jours', 7),
            $eqLogic->getConfiguration('valeur_presente', '1'),
            init('seuil', presencium::reglageGlobal('seuil_vrai', presencium::SEUIL_VRAI_DEFAUT))
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . htmlspecialchars((string) init('action'), ENT_QUOTES, 'UTF-8'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
