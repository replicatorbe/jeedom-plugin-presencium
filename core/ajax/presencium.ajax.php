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
        /* Le type est revérifié pour la même raison : le journal et les règles
         * n'existent que sur un foyer, et les demander à une personne rendrait
         * une erreur PHP là où une phrase claire est attendue. */
        if ($_type !== null && $eqLogic->getConfiguration('type') != $_type) {
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
            throw new Exception(__('Type d\'équipement inconnu :', __FILE__) . ' ' . $type);
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
            $eqLogic->setConfiguration('personnes', array());
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
            if ($eqLogic->getConfiguration('type') != presencium::TYPE_PERSONNE) {
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
    if (init('action') == 'journal') {
        $eqLogic = $getEqLogic(init('id'), presencium::TYPE_FOYER);
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
        $entrees = $eqLogic->journalLire($limite, (string) init('genre'));
        if (!is_array($entrees)) {
            $entrees = array();
        }
        ajax::success($entrees);
    }

    if (init('action') == 'viderJournal') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'), presencium::TYPE_FOYER);
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
        ajax::success($eqLogic->executerRegle($regle, $maintenant,
            array('instantane' => $eqLogic->instantane($maintenant)), true));
    }

    /* Réévalue tout de suite, sans attendre la minute suivante du cron. */
    if (init('action') == 'evaluer') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        $maintenant = time();
        if ($eqLogic->getConfiguration('type') == presencium::TYPE_PERSONNE) {
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
        if ($eqLogic->getConfiguration('type') == presencium::TYPE_PERSONNE) {
            ajax::success($eqLogic->verdictPersonne($maintenant));
        }
        $instantane = $eqLogic->instantane($maintenant);
        ajax::success(array(
            'presents'   => isset($instantane['presents']) ? $instantane['presents'] : array(),
            'total'      => isset($instantane['total']) ? (int) $instantane['total'] : 0,
            'simulation' => $eqLogic->enSimulation() ? 1 : 0,
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
