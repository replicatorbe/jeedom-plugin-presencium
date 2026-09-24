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

/* La page de configuration d'un plugin est incluse par index.php, qui n'a
 * vérifié que la connexion : le profil, lui, se contrôle ici. */
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

/* Ici, et seulement ici, les champs sont des configKey avec un data-l1key
 * seul : le cœur écrit ces clés dans la configuration du plugin, pas dans un
 * équipement, et n'y descend pas de niveau. */
$presenciumSimulationGlobale = (config::byKey('simulation', 'presencium', 0) == 1);
?>
<form class="form-horizontal">
    <?php
    if ($presenciumSimulationGlobale) {
        /* Rendu par le PHP, en tête de page : c'est le premier écran qu'on
           ouvre quand on se demande pourquoi « rien ne se passe ». */
        echo '<div class="alert alert-warning" style="margin:0 0 10px 0;font-size:1.05em;">';
        echo '<i class="fas fa-flask fa-lg"></i> <b>{{La simulation globale est active : le plugin n\'exécute aucune action.}}</b>';
        echo '<br>{{Tout le reste continue — présences suivies, règles évaluées, journal tenu — mais rien n\'est commandé nulle part, dans aucun foyer.}}';
        echo '</div>';
    }
    ?>

    <fieldset>
        <legend><i class="fas fa-flask"></i> {{Simulation}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Tout simuler}}
                <sup><i class="fas fa-question-circle" title="{{Force la simulation sur tous les foyers et toutes les règles, quels que soient leurs propres réglages. Les présences sont suivies, les états publiés, les règles évaluées et leurs décisions écrites au journal — mais aucune action n'est envoyée, et l'alarme n'est ni armée ni mise en service. C'est la façon d'essayer une installation entière sans rien risquer.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input type="checkbox" class="configKey" data-l1key="simulation">
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{À laisser cochée les premiers jours : on relit ensuite le journal de chaque foyer, on y voit ce qui se serait déclenché et pourquoi, et on décoche quand plus rien ne surprend. La simulation se règle aussi foyer par foyer et règle par règle ; il suffit d'un des trois pour qu'elle s'applique.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-hourglass-half"></i> {{Valeurs par défaut des personnes}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Délai de départ}}
                <sup><i class="fas fa-question-circle" title="{{C'est ce délai qui sépare le rebond d'une balise d'un vrai départ : une personne n'est déclarée absente que si son signal reste absent pendant toute cette durée, et un retour entre-temps n'en produit aucun. 15 minutes est la valeur mesurée sur de vraies balises Tile, dont les fausses absences vont de 2 secondes à 13 minutes, alors que les vraies dépassent l'heure.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <div class="input-group">
                    <input type="number" min="0" max="720" step="1" class="configKey form-control roundedLeft" data-l1key="delai_depart" placeholder="15">
                    <span class="input-group-addon roundedRight">{{min}}</span>
                </div>
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{Proposé aux personnes nouvellement créées. Chaque personne garde ensuite le sien : changer cette valeur ne touche pas à celles qui existent déjà.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Délai d'arrivée}}
                <sup><i class="fas fa-question-circle" title="{{Secondes de signal continu avant de déclarer une arrivée. Zéro dans presque tous les cas : une arrivée manquée, c'est une porte qui ne s'ouvre pas. L'asymétrie avec le délai de départ est voulue — on confirme lentement un départ, vite une arrivée.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <div class="input-group">
                    <input type="number" min="0" max="3600" step="10" class="configKey form-control roundedLeft" data-l1key="delai_arrivee" placeholder="0">
                    <span class="input-group-addon roundedRight">{{s}}</span>
                </div>
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{Ne le montez que si votre détecteur signale des présences fugaces — un téléphone qui accroche le wifi en passant devant la maison.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-clipboard-list"></i> {{Journal}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Entrées conservées}}
                <sup><i class="fas fa-question-circle" title="{{Nombre d'entrées gardées par équipement. Le journal est un fichier qui ne grandit pas : au-delà de cette limite, les plus anciennes disparaissent. Un millier tient une dizaine de jours sur une installation ordinaire ; une heure de rebonds en consomme une vingtaine, et une campagne de simulation se relit sur plusieurs jours.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <!-- Le plancher est celui que le serveur applique réellement
                     (presencium::JOURNAL_TAILLE_MIN = 10), pas un chiffre
                     choisi ici : annoncer 20 alors que 10 passe fait croire
                     qu'une valeur plus basse est refusée, et surtout la saisie
                     et le comportement racontent deux histoires différentes. -->
                <input type="number" min="10" max="5000" step="1" class="configKey form-control" data-l1key="journal_taille" placeholder="1000">
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{De 10 à 5000 entrées : au-delà de ces bornes, le plugin applique la plus proche sans le dire. Le journal garde aussi les mouvements de présence, dont les rebonds absorbés : c'est là que se lisent les faux positifs de vos détecteurs.}}</span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Seuil d'une vraie absence}}
                <sup><i class="fas fa-question-circle" title="{{Au-delà de cette durée, une absence est tenue pour une vraie sortie ; en deçà, pour un décrochage de la balise. Le bouton « Analyser » s'en sert pour séparer les deux familles, et le journal pour signaler qu'un départ confirmé, suivi d'un retour bien plus tôt que ce seuil, était probablement faux.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <!-- Mêmes bornes que celles que l'analyse applique réellement
                     (presencium::analyserSource borne le seuil entre 5 et
                     720) : une valeur hors de ces bornes y serait ramenée
                     sans le dire. -->
                <div class="input-group">
                    <input type="number" min="5" max="720" step="1" class="configKey form-control roundedLeft" data-l1key="seuil_vrai" placeholder="60">
                    <span class="input-group-addon roundedRight">{{min}}</span>
                </div>
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{Une heure convient à presque toutes les maisons. Descendez-le si l'on sort régulièrement pour vingt minutes — sans quoi ces sorties-là seraient comptées comme des faux départs.}}</span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Silence maximal d'une balise}}
                <sup><i class="fas fa-question-circle" title="{{Une balise qui se dit présente mais n'émet plus depuis ce temps est signalée en page Santé. Le cas arrive quand une pile meurt pendant que la personne est chez elle : la présence se fige sur « présent » et la maison ne devient plus jamais vide.}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input type="number" min="0" max="10080" step="1" class="configKey form-control" data-l1key="silence_max" placeholder="120">
            </div>
            <div class="col-md-5">
                <span class="help-block" style="margin:0;">{{En minutes, 0 pour ne rien contrôler. Le plugin ne bascule jamais la présence de lui-même sur ce motif : déclarer absent quelqu'un dont on n'a plus de nouvelles reviendrait à armer l'alarme sur une personne assise dans son salon. Il le signale, vous tranchez.}}</span>
            </div>
        </div>
    </fieldset>
</form>
