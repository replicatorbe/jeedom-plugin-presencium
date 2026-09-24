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
 * L'analyse de l'historique d'une source, pour choisir le délai de départ.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumAnalyse {

    /* ============================================================== ANALYSE */

    /*
     * Relit l'historique d'une commande de présence et rejoue la décision pour
     * plusieurs délais de départ. C'est l'analyse qu'on ferait à la main, en
     * relevant les absences une à une dans les graphiques — sauf qu'elle se
     * fait sur les données de CETTE balise, et pas sur une moyenne.
     *
     * Le raisonnement tient en une phrase : une absence brute courte est
     * presque toujours un décrochage, une absence longue est presque toujours
     * un vrai départ, et le bon délai est le plus petit qui sépare les deux.
     * Le seuil qui définit « longue » est un réglage, pas une vérité : sur une
     * maison où l'on sort faire une course de vingt minutes, il doit descendre.
     *
     * La décision est rejouée par presenciumPersonne::evaluer(), la même
     * fonction que le cron appelle. L'analyse ne peut donc pas diverger de ce
     * que le plugin fera vraiment.
     */
    public static function analyserSource($_cmdId, $_jours = 7, $_valeurPresente = '1', $_seuilVrai = 60) {
        $cmd = cmd::byId((int) $_cmdId);
        if (!is_object($cmd)) {
            throw new Exception(__('Commande introuvable.', __FILE__));
        }
        if ($cmd->getType() !== 'info') {
            throw new Exception(__('Ce n\'est pas une commande d\'information.', __FILE__));
        }
        if ($cmd->getIsHistorized() != 1) {
            throw new Exception(__('Cette commande n\'est pas historisée : sans historique, il n\'y a rien à analyser. Activez l\'historisation, puis revenez dans quelques jours.', __FILE__));
        }

        $jours = max(1, min(90, (int) $_jours));
        $seuilVrai = max(5, min(720, (int) $_seuilVrai));
        $fin = time();
        $debut = $fin - ($jours * 86400);

        $points = $cmd->getHistory(date('Y-m-d H:i:s', $debut), date('Y-m-d H:i:s', $fin));
        if (!is_array($points) || count($points) === 0) {
            throw new Exception(__('Aucun historique sur cette période.', __FILE__));
        }

        /* Les transitions, et elles seules : l'historique d'une balise répète
         * la même valeur toutes les cinq minutes, et compter ces répétitions
         * comme des événements fausserait tout. */
        $transitions = array();
        $precedent = null;
        foreach ($points as $point) {
            $ts = strtotime($point->getDatetime());
            $brut = presenciumPersonne::estPresentBrut($point->getValue(), $_valeurPresente) ? 1 : 0;
            if ($ts <= 0) {
                continue;
            }
            if ($precedent === null || $brut !== $precedent) {
                $transitions[] = array('ts' => $ts, 'brut' => $brut);
                $precedent = $brut;
            }
        }
        if (count($transitions) === 0) {
            throw new Exception(__('Aucun changement d\'état sur cette période.', __FILE__));
        }

        /* Les épisodes d'absence brute, fermés par le retour du signal ou par
         * la fin de la période. Un épisode encore ouvert est écarté : sa durée
         * n'est pas connue, et l'inclure fausserait le compte dans le sens le
         * plus dangereux — celui qui fait croire à un vrai départ. */
        $episodes = array();
        for ($i = 0; $i < count($transitions); $i++) {
            if ($transitions[$i]['brut'] !== 0) {
                continue;
            }
            if (!isset($transitions[$i + 1])) {
                continue;
            }
            /*
             * LA SECONDE FAIT FOI, LA MINUTE N'EST QUE POUR L'AFFICHAGE.
             *
             * Le moteur décide en secondes et avec un « >= »
             * (presenciumPersonne::evaluer), et l'analyse doit dire la même
             * chose que lui. En minutes arrondies, un creux de 15 min 12 s
             * deviendrait « 15 », et le tableau annoncerait « aucune fausse
             * absence » pour un délai de 15 minutes que le plugin, lui,
             * franchit à la 900e seconde — l'analyse recommanderait le délai
             * qui laisse passer exactement ce creux-là. C'est le seul chiffre
             * que cet écran sert à choisir : il doit être décidé par la règle
             * du moteur, et par aucune autre.
             */
            $secondes = (int) ($transitions[$i + 1]['ts'] - $transitions[$i]['ts']);
            $episodes[] = array(
                'debut'    => $transitions[$i]['ts'],
                'secondes' => $secondes,
                'minutes'  => (int) round($secondes / 60),
            );
        }

        $courtes = array();
        $longues = array();
        foreach ($episodes as $episode) {
            if ($episode['secondes'] >= $seuilVrai * 60) {
                $longues[] = $episode;
            } else {
                $courtes[] = $episode;
            }
        }

        $lignes = array();
        foreach (array(0, 5, 10, 15, 20, 30, 45, 60) as $delai) {
            $declares = 0;
            $courtesPassees = 0;
            $longuesVues = 0;
            $tenuPresent = 0;
            foreach ($episodes as $episode) {
                /* La comparaison du moteur, mot pour mot : « écoulé >= délai »,
                 * en secondes. Le temps « tenu présent à tort » est ce que le
                 * délai a mangé, épisode par épisode. */
                $tenuPresent += min($episode['secondes'], $delai * 60);
                if ($episode['secondes'] >= $delai * 60) {
                    $declares++;
                    if ($episode['secondes'] >= $seuilVrai * 60) {
                        $longuesVues++;
                    } else {
                        $courtesPassees++;
                    }
                }
            }
            $lignes[] = array(
                'delai'        => $delai,
                'departs'      => $declares,
                'faux'         => $courtesPassees,
                'vrais'        => $longuesVues,
                'vrais_total'  => count($longues),
                'tenu_present' => (int) round($tenuPresent / 60),
            );
        }

        /* Le délai recommandé : le plus petit de la liste qui ne laisse passer
         * aucune absence courte sans perdre une seule absence longue. Aucun ne
         * convient — c'est le cas d'une balise dont les décrochages durent plus
         * longtemps qu'une vraie sortie — et on le dit plutôt que d'en désigner
         * un au hasard. */
        $recommande = null;
        foreach ($lignes as $ligne) {
            if ($ligne['faux'] === 0 && $ligne['vrais'] === count($longues)) {
                $recommande = $ligne['delai'];
                break;
            }
        }

        return array(
            'commande'    => $cmd->getHumanName(),
            'jours'       => $jours,
            'seuil_vrai'  => $seuilVrai,
            'debut'       => date('Y-m-d H:i', $debut),
            'fin'         => date('Y-m-d H:i', $fin),
            'heures'      => (int) round(($fin - $debut) / 3600),
            'points'      => count($points),
            'transitions' => count($transitions),
            'episodes'    => count($episodes),
            'courtes'     => count($courtes),
            'longues'     => count($longues),
            /* Arrondis dans le sens qui ne ment pas : le plus long creux vers le
             * haut, la plus courte vraie absence vers le bas. Un creux de
             * 15 min 12 s affiché « 15 min » à côté d'un délai recommandé de
             * 20 min ferait passer la recommandation pour de la prudence
             * gratuite, alors qu'elle est le premier délai qui le couvre. */
            'plus_longue_courte' => (count($courtes) > 0) ? (int) ceil(max(array_column($courtes, 'secondes')) / 60) : 0,
            'plus_courte_longue' => (count($longues) > 0) ? (int) floor(min(array_column($longues, 'secondes')) / 60) : 0,
            'lignes'      => $lignes,
            'recommande'  => $recommande,
        );
    }
}
