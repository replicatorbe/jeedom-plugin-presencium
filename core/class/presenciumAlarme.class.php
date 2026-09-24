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
 * L'alarme portée par le foyer — armement, mise en service — et la
 * simulation, avec l'écriture sur l'objet relu que partagent les bascules.
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
