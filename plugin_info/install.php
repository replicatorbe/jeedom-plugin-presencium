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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe, et presencium_remove()
 * tomberait sur « Class not found » au moment précis où il doit nettoyer. */
require_once __DIR__ . '/../core/class/presencium.class.php';

function presencium_install() {
    /*
     * Rien à créer : ni table, ni démon, ni dépendance.
     *
     * Le seul contrôle utile est celui de la simulation globale. Une
     * installation restaurée depuis une sauvegarde peut arriver avec le mode
     * actif, et un plugin en simulation n'exécute rien tout en ayant l'air de
     * fonctionner parfaitement : c'est le diagnostic le plus coûteux du plugin,
     * et le seul moyen de l'éviter est de l'écrire là où on le lira.
     *
     * Le contrôle vit dans la classe : cronDaily() le refait, et le message
     * disparaît le jour où l'on quitte la simulation.
     */
    presencium::checkSimulation();
}

function presencium_update() {
    presencium_install();

    /*
     * Les équipements déjà créés n'ont ni les commandes ajoutées depuis, ni
     * forcément leur écouteur.
     *
     * Les commandes : sans ce passage, une commande ajoutée par une mise à jour
     * n'existerait que sur les équipements créés APRÈS, et l'utilisateur
     * conclurait que la nouveauté annoncée n'est pas là. createCommands() ne
     * touche ni au nom ni à la visibilité de ce qui existe déjà.
     *
     * L'écouteur : c'est la réparation qui compte le plus ici. listener::clean()
     * passe régulièrement et supprime tout écouteur dont plus aucun événement ne
     * désigne une commande existante — changer de plugin de balises suffit à le
     * faire disparaître. Un plugin sans écouteur continue de marcher, une minute
     * de retard à chaque fois, et rien ne le signale. Le rejouer à chaque mise à
     * jour le repose.
     *
     * Un try/catch (Throwable) PAR équipement : une personne dont la source a
     * disparu ne doit pas interrompre la mise à jour et laisser les équipements
     * suivants sans leurs commandes — ce qui produirait une installation à
     * moitié migrée, état dont on ne sort pas sans comprendre.
     */
    foreach (eqLogic::byType('presencium') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('presencium', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        try {
            $eqLogic->syncListener();
        } catch (Throwable $e) {
            log::add('presencium', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        /* Journal en JSON Lines et état du foyer en fichier : conversion
         * d'avance, sinon faite au premier passage. */
        try {
            $eqLogic->migrerDonnees();
        } catch (Throwable $e) {
            log::add('presencium', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function presencium_remove() {
    /*
     * Les équipements sont nettoyés un par un par presencium::preRemove() —
     * écouteur, cache et journal compris. Restent les traces que personne ne
     * porte : les écouteurs devenus orphelins si un équipement a été supprimé
     * en base sans passer par le cœur, et les messages du centre de messages.
     *
     * Un écouteur orphelin n'est pas anodin : il relance jeeListener.php à
     * chaque battement de la balise, sur une classe qui n'existe plus.
     */
    foreach (eqLogic::byType('presencium') as $eqLogic) {
        try {
            $eqLogic->removeListener();
            $eqLogic->purgerCache();
        } catch (Throwable $e) {
            log::add('presencium', 'debug', __('Nettoyage impossible :', __FILE__) . ' ' . $e->getMessage());
        }
    }
    /* Le battement du cron n'appartient à aucun équipement : personne ne le
     * nettoierait. Laissé en place, il ferait dire à la page Santé d'une
     * réinstallation que la dernière évaluation date d'avant la désinstallation. */
    cache::delete(presencium::CACHE_CRON);

    foreach (listener::byClass('presencium') as $listener) {
        try {
            $listener->remove();
        } catch (Throwable $e) {
            log::add('presencium', 'debug', __('Écouteur non retiré :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /*
     * Les fichiers de data/ : les journaux, les états de foyer, leurs verrous
     * et ceux des foyers.
     *
     * preRemove() les efface équipement par équipement, mais il n'est appelé
     * que sur les équipements que le cœur supprime. Une désinstallation laisse
     * les équipements en base — le plugin peut être réinstallé — et data/ n'est
     * jamais nettoyé par une mise à jour : les journaux d'une installation
     * abandonnée resteraient sur le disque pour toujours, avec l'historique de
     * présence de la maison dedans. Un journal de présence n'est pas un fichier
     * anodin qu'on oublie dans un coin.
     *
     * L'effacement est borné au motif exact que le plugin écrit : rien d'autre
     * de ce qui traînerait dans data/ n'est touché.
     */
    try {
        $dossier = presencium::dossierDonnees();
        foreach (ls($dossier, '*', false, array('files', 'quiet')) as $fichier) {
            if (!preg_match('/^(?:journal|foyer|etat)-\d+\./', $fichier)) {
                continue;
            }
            @unlink($dossier . '/' . $fichier);
        }
    } catch (Throwable $e) {
        log::add('presencium', 'debug', __('Journaux non supprimés :', __FILE__) . ' ' . $e->getMessage());
    }

    message::removeAll('presencium');
}
