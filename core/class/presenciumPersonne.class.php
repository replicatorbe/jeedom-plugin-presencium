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
 * La présence d'une personne, décidée à partir d'un signal qui ment.
 *
 * Cette classe ne connaît ni Jeedom, ni base de données, ni commande : on lui
 * donne une valeur brute, la date de son dernier changement et quatre réglages,
 * elle rend un verdict. C'est délibéré, et c'est la raison d'être de la
 * séparation.
 *
 * Une balise Bluetooth, un détecteur Wi-Fi ou un ping de téléphone ne disent pas
 * « parti » : ils disent « je ne l'ai pas vu cette fois-ci ». Le seul moyen de
 * savoir si le plugin distingue correctement un trou de couverture d'un départ
 * est de rejouer des heures d'historique en quelques millisecondes, sans base et
 * sans cron — ce que tests/run.php fait avec l'épisode de rebond réellement
 * mesuré. Dès que la décision dépendrait d'un eqLogic, d'un cache ou d'une
 * commande, cette preuve deviendrait impossible et l'anti-rebond redeviendrait
 * ce qu'il est dans la plupart des installations : une intention non vérifiée.
 *
 * Corollaire assumé : aucun état n'est mémorisé ici. Le verdict se recalcule
 * entièrement à partir du signal et de sa date de changement, donc il est juste
 * dès le premier passage du cron après un redémarrage ou un vidage de cache,
 * là où une machine à états reprendrait à zéro et se tromperait un quart
 * d'heure durant.
 */
class presenciumPersonne {

    /* Le réglage qui fait tout le plugin. Quinze minutes : au-dessus des cinq
     * fausses absences mesurées sur la balise (la plus longue faisait 13,6 min)
     * et bien au-dessous de la plus courte des vraies absences de l'historique
     * (92 min). Entre les deux, il y a de la place. */
    const DELAI_DEPART_DEFAUT = 15;   // minutes

    /* Zéro par défaut, et c'est voulu : voir l'asymétrie commentée dans
     * evaluer(). Une arrivée se croit sur parole. */
    const DELAI_ARRIVEE_DEFAUT = 0;   // secondes

    /* Douze heures de confirmation de départ, une heure de confirmation
     * d'arrivée : au-delà, le réglage ne protège plus de rien, il masque
     * simplement la présence. Les bornes évitent qu'un « 1500 » tapé pour des
     * secondes dans un champ qui attend des minutes ne rende la personne
     * éternellement présente sans que rien ne le signale. */
    const DELAI_DEPART_MAX = 720;
    const DELAI_ARRIVEE_MAX = 3600;

    /*
     * Nettoie et borne les réglages venus du formulaire.
     *
     * Tout ce qui sort d'une page web est une chaîne, y compris « 0 » et « ».
     * Un simple (int) suffirait à transformer un champ vide ou mal saisi en
     * zéro, c'est-à-dire à supprimer l'anti-rebond sans un mot : la personne
     * serait déclarée absente au premier trou de couverture et l'alarme
     * s'armerait sur quelqu'un assis dans son salon. Une valeur non numérique
     * retombe donc sur le défaut, jamais sur zéro ; seul un « 0 » réellement
     * tapé vaut zéro.
     *
     * La fonction est idempotente : on peut la rappeler sur son propre
     * résultat, ce dont evaluer() profite pour accepter aussi bien une
     * configuration brute qu'une configuration déjà normalisée.
     */
    public static function normaliserReglages($_configuration) {
        $reglages = array(
            'source'          => 0,
            'valeur_presente' => '1',
            'delai_arrivee'   => self::DELAI_ARRIVEE_DEFAUT,
            'delai_depart'    => self::DELAI_DEPART_DEFAUT,
        );
        if (!is_array($_configuration)) {
            return $reglages;
        }

        if (isset($_configuration['source']) && is_numeric($_configuration['source'])) {
            $reglages['source'] = max(0, (int) $_configuration['source']);
        }

        if (isset($_configuration['valeur_presente'])) {
            $valeur = trim((string) $_configuration['valeur_presente']);
            /* Un champ laissé vide veut dire « la valeur habituelle », pas
             * « la chaîne vide » : sans cela, une personne enregistrée sans y
             * toucher ne serait jamais présente. */
            if ($valeur !== '') {
                $reglages['valeur_presente'] = $valeur;
            }
        }

        $reglages['delai_arrivee'] = self::borner(
            isset($_configuration['delai_arrivee']) ? $_configuration['delai_arrivee'] : null,
            self::DELAI_ARRIVEE_DEFAUT, self::DELAI_ARRIVEE_MAX);
        $reglages['delai_depart'] = self::borner(
            isset($_configuration['delai_depart']) ? $_configuration['delai_depart'] : null,
            self::DELAI_DEPART_DEFAUT, self::DELAI_DEPART_MAX);

        return $reglages;
    }

    /* Un entier entre 0 et $_max, ou le défaut si ce n'en est pas un. */
    private static function borner($_valeur, $_defaut, $_max) {
        if (is_bool($_valeur) || is_array($_valeur) || $_valeur === null) {
            return $_defaut;
        }
        $valeur = trim((string) $_valeur);
        if ($valeur === '' || !is_numeric($valeur)) {
            return $_defaut;
        }
        $entier = (int) $valeur;
        /*
         * Un négatif retombe sur le DÉFAUT, jamais sur zéro.
         *
         * Le ramener à zéro supprimerait l'anti-rebond en silence : un « -5 »
         * tapé par erreur, ou recopié d'une configuration exportée, déclarerait
         * la personne absente au premier trou de couverture de sa balise et
         * armerait l'alarme sur quelqu'un assis dans son salon. Un signe moins
         * n'est pas une demande de zéro, c'est une saisie qui n'a pas de sens :
         * on la traite comme les autres saisies qui n'en ont pas.
         */
        if ($entier < 0) {
            return $_defaut;
        }
        return min($_max, $entier);
    }

    /*
     * Le signal brut vaut-il « présent » ?
     *
     * Les sources ne s'accordent sur rien : une commande binaire du coeur rend
     * l'entier 1, un retour MQTT rend la chaîne « on », un script rend true, et
     * un plugin de géolocalisation rend « present ». Une comparaison stricte
     * contre '1' les déclarerait tous absents ; un simple test de vérité PHP
     * déclarerait présente la chaîne '0'... et la chaîne 'off', qui est vraie.
     * D'où cette liste explicite, appliquée quand la valeur attendue est la
     * valeur par défaut.
     *
     * Dès que l'utilisateur a saisi autre chose (« home », « 2 », le nom d'une
     * pièce), on ne devine plus : comparaison numérique si les deux côtés le
     * sont — sinon '2' ne vaudrait pas 2 — et textuelle insensible à la casse
     * sinon, parce que « Home » et « home » sortent de la même passerelle selon
     * la version du firmware.
     *
     * Limite assumée : c'est une ÉGALITÉ, jamais un seuil. Un RSSI (« -70 »)
     * ne dit présent qu'à -70 exactement ; pour un seuil, il faut une source
     * qui publie déjà un binaire (un virtuel, un autre plugin).
     */
    public static function estPresentBrut($_valeur, $_valeurPresente) {
        $attendu = is_array($_valeurPresente) ? '' : trim((string) $_valeurPresente);
        if ($attendu === '') {
            $attendu = '1';
        }
        if ($_valeur === null || is_array($_valeur)) {
            return false;
        }
        if (is_bool($_valeur)) {
            $valeur = $_valeur ? '1' : '0';
        } else {
            $valeur = trim((string) $_valeur);
        }

        if ($attendu === '1') {
            /* Un nombre vaut présent s'il vaut 1, à l'epsilon près : « 1.0 »
             * oui, « 2 » non. Sans cela, une passerelle qui publie des
             * flottants rendrait la personne éternellement absente. */
            if (is_numeric($valeur)) {
                return (abs((float) $valeur - 1) < 0.000001);
            }
            $minuscule = function_exists('mb_strtolower') ? mb_strtolower($valeur, 'UTF-8') : strtolower($valeur);
            return in_array($minuscule,
                array('on', 'true', 'present', 'presente', 'oui', 'yes',
                      'home', 'detected', 'detecte', 'détecté'), true);
        }
        if (is_numeric($valeur) && is_numeric($attendu)) {
            /* Epsilon, exactement comme presenciumRegles::comparer() : les
             * valeurs transitent par du JSON et par le cache du coeur, où
             * « 21.3 » revient parfois 21.299999999999997. Une égalité stricte
             * de flottants ne serait alors jamais vraie, et la personne serait
             * éternellement absente sans qu'aucune erreur ne le dise. Les deux
             * comparaisons du plugin doivent répondre la même chose à la même
             * question, sinon la page de réglage et la décision divergent. */
            return (abs((float) $valeur - (float) $attendu) < 0.000001);
        }
        return (strcasecmp($valeur, $attendu) === 0);
    }

    /*
     * LE COEUR : la présence stabilisée.
     *
     * $_brut       : valeur brute courante, telle que la source l'a publiée
     * $_depuis     : horodatage unix du dernier changement du signal brut
     * $_maintenant : horodatage unix
     * $_reglages   : sortie de normaliserReglages() (une configuration brute
     *                est acceptée telle quelle, la normalisation est idempotente)
     * $_precedent  : dernière présence stabilisée connue (true, false, ou null
     *                si on l'ignore) — voir le retour pendant un départ.
     *
     * La règle est délibérément asymétrique, et c'est tout le sujet :
     *
     *   - le signal dit « présent » : on le croit presque tout de suite
     *     (delai_arrivee vaut zéro par défaut) ;
     *   - le signal dit « absent » : on ne le croit qu'après delai_depart
     *     minutes de silence ininterrompu.
     *
     * Les deux erreurs ne coûtent pas la même chose. Une arrivée manquée, c'est
     * une lampe qui ne s'allume pas et une porte qui ne s'ouvre pas : on le voit
     * immédiatement, on rentre quand même, et la minute suivante corrige. Un
     * départ inventé, c'est une alarme qui s'arme sur quelqu'un assis dans son
     * salon, le chauffage coupé et la maison mise en veille — et ce n'est même
     * pas la faute de l'utilisateur, c'est la balise qui a cligné des yeux
     * pendant quatre minutes. On accepte donc d'être en retard sur les départs,
     * jamais en avance.
     *
     * Rien n'est mémorisé : le verdict ne dépend que du signal et de la date de
     * son dernier changement, deux informations que le coeur conserve de
     * lui-même sur la commande info. Un redémarrage ne fait donc pas repartir le
     * compteur, contrairement à une machine à états — qui déclarerait la
     * personne « départ en cours » un quart d'heure après chaque reboot.
     */
    public static function evaluer($_brut, $_depuis, $_maintenant, $_reglages, $_precedent = null) {
        $reglages = self::normaliserReglages($_reglages);
        $brut = self::estPresentBrut($_brut, $reglages['valeur_presente']);

        $maintenant = (int) $_maintenant;
        $depuis = (int) $_depuis;
        /* Date inconnue (commande jamais collectée) ou dans l'avenir (horloge
         * remise à l'heure, historique importé) : on repart de maintenant. Le
         * délai de départ recommence alors, ce qui laisse la personne présente
         * un quart d'heure de plus — du bon côté de l'asymétrie. */
        if ($depuis <= 0 || $depuis > $maintenant) {
            $depuis = $maintenant;
        }
        $ecoule = $maintenant - $depuis;

        if ($brut) {
            $delai = (int) $reglages['delai_arrivee'];   // secondes
            /* Le signal revient pendant un « départ en cours » : la personne
             * n'a jamais cessé d'être présente, ce n'est pas une arrivée. Lui
             * imposer le délai d'arrivée la rendrait absente le temps de ce
             * délai — un faux départ, l'alarme armée. Sans précédent connu
             * (null), on retombe sur le délai : du côté prudent pour une
             * arrivée. */
            if ($ecoule >= $delai || $_precedent === true) {
                return array('present' => true, 'brut' => true, 'transitoire' => false,
                             'restant' => 0, 'raison' => 'presente');
            }
            return array('present' => false, 'brut' => true, 'transitoire' => true,
                         'restant' => $delai - $ecoule, 'raison' => 'arrivee_en_cours');
        }

        $delai = (int) $reglages['delai_depart'] * 60;   // minutes -> secondes
        if ($ecoule >= $delai) {
            return array('present' => false, 'brut' => false, 'transitoire' => false,
                         'restant' => 0, 'raison' => 'absente');
        }
        return array('present' => true, 'brut' => false, 'transitoire' => true,
                     'restant' => $delai - $ecoule, 'raison' => 'depart_en_cours');
    }

    /*
     * Libellé de la commande `etat`.
     *
     * Le compte à rebours affiché est ce qui rend l'anti-rebond compréhensible :
     * sans lui, « Présent » alors que la balise est partie passe pour un bug du
     * plugin, et l'utilisateur descend le délai à zéro — exactement ce qu'il ne
     * faut pas faire. Arrondi au supérieur, et jamais « 0 min » : une seconde
     * restante s'annonce « 1 min », sans quoi l'état afficherait une attente
     * terminée qui ne l'est pas.
     */
    public static function libelleEtat($_verdict) {
        $raison = (is_array($_verdict) && isset($_verdict['raison'])) ? $_verdict['raison'] : '';
        switch ($raison) {
            case 'presente':
                return self::traduire('Présent');
            case 'arrivee_en_cours':
                return self::traduire('Arrivée en cours');
            case 'depart_en_cours':
                $restant = isset($_verdict['restant']) ? (int) $_verdict['restant'] : 0;
                $minutes = max(1, (int) ceil($restant / 60));
                return self::traduire('Départ en cours') . ' (' . $minutes . ' min)';
        }
        return self::traduire('Absent');
    }

    /*
     * Traduction facultative.
     *
     * La classe tourne aussi hors de Jeedom — c'est tout son intérêt — où __()
     * n'existe pas : l'appeler directement ferait tomber le jeu d'essai sur un
     * « Call to undefined function » avant le premier contrôle. On passe donc
     * par le coeur quand il est chargé, et par le texte français sinon, qui est
     * de toute façon ce que __() rend en l'absence de traduction.
     */
    private static function traduire($_texte) {
        if (function_exists('__')) {
            return __($_texte, __FILE__);
        }
        return $_texte;
    }
}
