<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Une modale de plugin est incluse par index.php, qui n'a vérifié que la
 * connexion : le profil, lui, se contrôle ici, comme sur la page du plugin. */
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

/* La charpente seulement. La règle ouverte vit dans la variable de module
 * presenciumRegles du JS, jamais dans le DOM de la page : elle dépasse trois
 * niveaux de clés et le cœur ne la ramasserait pas. Rien ici n'est donc un
 * eqLogicAttr, et rien n'est enregistré avant le « Sauvegarder » de
 * l'équipement.
 *
 * Les jours sont écrits en PHP. Les déclencheurs, eux, sont posés par le JS
 * depuis la table unique de desktop/php/presencium.php, pour que le tableau
 * des règles et cette fenêtre disent la même chose. Tout ce qui vient de
 * l'installation — personnes, commandes — est posé par le JS, en texte.
 */
$presenciumJours = array(1 => '{{Lun}}', 2 => '{{Mar}}', 3 => '{{Mer}}', 4 => '{{Jeu}}',
                         5 => '{{Ven}}', 6 => '{{Sam}}', 7 => '{{Dim}}');
?>
<div id="div_presenciumRegleEditeur">
    <!-- Ce qu'une règle neuve fait sans le dire : elle naît en simulation. La
         décision est la bonne — une règle jamais relue n'arme pas une alarme —
         mais muette, elle ressemble à une panne. Le JS remplit cette zone à la
         création, et la laisse vide pour une règle existante. -->
    <div id="div_presenciumRegleNeuve"></div>

    <form class="form-horizontal">

        <!-- ========================================= DÉCLENCHEUR ========================================= -->
        <fieldset>
            <legend><i class="fas fa-bolt"></i> {{Déclencheur}}</legend>

            <div class="form-group">
                <label class="col-sm-3 control-label">{{Nom de la règle}}</label>
                <div class="col-sm-6">
                    <input type="text" class="form-control" id="in_presenciumRegleNom" placeholder="{{J'arme en partant}}">
                </div>
                <div class="col-sm-3">
                    <label class="checkbox-inline" style="padding-left:20px;">
                        <input type="checkbox" id="in_presenciumRegleActif"> {{Règle active}}
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Quand}}
                    <sup><i class="fas fa-question-circle" title="{{Le moment qui déclenche la règle. « Le premier arrive » et « Le dernier part » ne se produisent qu'au passage de la maison de vide à occupée et inversement, alors que « Quelqu'un arrive » vaut pour chaque personne. Les deux derniers ne sont pas des passages mais des durées : ils se vérifient chaque minute.}}"></i></sup>
                </label>
                <div class="col-sm-9">
                    <select class="form-control" id="sel_presenciumRegleDeclencheur"></select>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Qui}}
                    <sup><i class="fas fa-question-circle" title="{{Pour « Quelqu'un arrive » et « Quelqu'un part » : la personne visée, ou n'importe qui. Les autres déclencheurs portent sur le foyer entier et ignorent ce champ.}}"></i></sup>
                </label>
                <div class="col-sm-9">
                    <!-- Rempli par le JS avec les personnes cochées dans ce
                         foyer : une règle qui viserait une personne qui n'y
                         habite pas ne se déclencherait jamais. -->
                    <select class="form-control" id="sel_presenciumReglePersonne">
                        <option value="0">{{N'importe qui}}</option>
                    </select>
                </div>
            </div>

            <!-- Visible seulement pour « vide depuis » et « occupée depuis » :
                 pour les autres déclencheurs, une durée n'aurait aucun sens et
                 se lirait comme un délai d'attente, qui existe plus bas. -->
            <div class="form-group" id="div_presenciumRegleMinutes" style="display:none;">
                <label class="col-sm-3 control-label">
                    {{Depuis}}
                    <sup><i class="fas fa-question-circle" title="{{La durée qui doit s'être écoulée dans cet état avant que la règle ne se déclenche. Elle est vérifiée chaque minute, et la règle ne part qu'une fois : le délai de repos, plus bas, décide quand elle peut repartir.}}"></i></sup>
                </label>
                <div class="col-sm-4">
                    <!-- Bornes alignées sur celles que le serveur applique
                         réellement (la constante MINUTES_MAX de presenciumRegles,
                         soit 10080, une
                         semaine) et sur celles du JS : trois plafonds
                         différents — 1440 ici, 100000 dans le JS, 10080 sur le
                         serveur — faisaient accepter une durée que le serveur
                         rabotait ensuite en silence, et la règle partait à un
                         moment que personne n'avait choisi. Le pas est de 1 :
                         avec min=1, un pas de 5 aurait refusé 30. -->
                    <div class="input-group">
                        <input type="number" min="1" max="10080" step="1" class="form-control roundedLeft" id="in_presenciumRegleMinutes" placeholder="30">
                        <span class="input-group-addon roundedRight">{{min}}</span>
                    </div>
                </div>
                <div class="col-sm-5">
                    <span class="help-block" style="margin:0;">{{De 1 minute à 10080 (une semaine). Un champ vide est refusé à la validation : « vide depuis 0 min » serait vrai en permanence.}}</span>
                </div>
            </div>
        </fieldset>

        <!-- ========================================== CONDITIONS ========================================= -->
        <fieldset>
            <legend><i class="fas fa-filter"></i> {{Conditions}}</legend>

            <div class="form-group">
                <div class="col-sm-12">
                    <span class="help-block" style="margin:0 0 8px 0;">{{Toutes les conditions doivent être vraies au moment du déclenchement, sans quoi la règle est écartée — et le journal dit laquelle a bloqué. Sans aucune condition, la règle se déclenche toujours.}}</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Plage horaire}}
                    <sup><i class="fas fa-question-circle" title="{{Limite la règle à une tranche de la journée. Une plage qui passe minuit — de 22:00 à 06:00 — est acceptée telle quelle. « De » égal à « À » couvre toute la journée.}}"></i></sup>
                </label>
                <div class="col-sm-2">
                    <label class="checkbox-inline" style="padding-left:20px;">
                        <input type="checkbox" id="in_presenciumRegleHeuresActif"> {{Activer}}
                    </label>
                </div>
                <div class="col-sm-3">
                    <div class="input-group">
                        <span class="input-group-addon roundedLeft">{{De}}</span>
                        <input type="time" class="form-control roundedRight" id="in_presenciumRegleHeureDe">
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="input-group">
                        <span class="input-group-addon roundedLeft">{{À}}</span>
                        <input type="time" class="form-control roundedRight" id="in_presenciumRegleHeureA">
                    </div>
                </div>
                <div class="col-sm-offset-5 col-sm-7 help-block" id="div_presenciumRegleHeuresNote" style="margin:4px 0 0 0;"></div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Jours}}
                    <sup><i class="fas fa-question-circle" title="{{Les jours où la règle peut se déclencher. Tout décocher revient à tout cocher : une règle sans jour choisi n'est pas une règle bridée, c'est une règle valable tous les jours. Pour suspendre une règle, décochez « Active ».}}"></i></sup>
                </label>
                <div class="col-sm-9" id="div_presenciumRegleJours">
                    <?php
                    foreach ($presenciumJours as $presenciumNumero => $presenciumLibelle) {
                        echo '<label class="checkbox-inline" style="padding-left:20px;">';
                        echo '<input type="checkbox" class="presenciumJour" data-jour="' . $presenciumNumero . '"> ' . $presenciumLibelle;
                        echo '</label>';
                    }
                    ?>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Commandes}}
                    <sup><i class="fas fa-question-circle" title="{{Compare la valeur d'une commande info à une valeur attendue : l'alarme en service, la nuit tombée, un mode vacances. La commande est retenue par son identifiant, elle survit donc à son renommage.}}"></i></sup>
                </label>
                <div class="col-sm-9">
                    <!-- thead figé, tbody posé par le JS : chaque ligne porte une
                         commande choisie dans l'installation, donc du texte qui
                         n'a rien à faire dans du balisage écrit à la main. -->
                    <table class="table table-condensed table-bordered" id="table_presenciumConditions" style="margin-bottom:5px;">
                        <thead>
                            <tr>
                                <th>{{Commande}}</th>
                                <th style="width:90px;">{{Opérateur}}</th>
                                <th style="width:140px;">{{Valeur}}</th>
                                <th style="width:50px;">{{Action}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <a class="btn btn-sm btn-default" id="bt_presenciumAjouterCondition"><i class="fas fa-plus"></i> {{Ajouter une condition}}</a>
                </div>
            </div>
        </fieldset>

        <!-- ============================================ ACTIONS ========================================== -->
        <fieldset>
            <legend><i class="fas fa-play-circle"></i> {{Actions}}</legend>

            <div class="form-group">
                <div class="col-sm-12">
                    <span class="help-block" style="margin:0 0 8px 0;">{{Ce que la règle fait quand elle part : les mêmes actions que dans un scénario, options comprises. En simulation, elles sont listées au journal sans être envoyées.}}</span>
                    <!-- Les blocs d'action sont rendus par le cœur
                         (jeedom.cmd.displayActionsOption) et contiennent des
                         <script> : le JS les pose avec Element.html(), jamais
                         avec innerHTML, sinon les options ne s'initialisent pas. -->
                    <div id="div_presenciumActions"></div>
                    <a class="btn btn-sm btn-default" id="bt_presenciumAjouterAction" style="margin-top:5px;"><i class="fas fa-plus"></i> {{Ajouter une action}}</a>
                </div>
            </div>
        </fieldset>

        <!-- =========================================== RÉGLAGES =========================================== -->
        <fieldset>
            <legend><i class="fas fa-sliders-h"></i> {{Réglages}}</legend>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Attendre avant d'agir}}
                    <sup><i class="fas fa-question-circle" title="{{La règle patiente ce nombre de minutes, puis agit — sauf si le déclencheur s'inverse entre-temps : quelqu'un rentre pendant l'attente, et le départ est annulé. C'est le garde-fou qui évite d'armer l'alarme sur un aller-retour à la boîte aux lettres. Zéro pour agir tout de suite.}}"></i></sup>
                </label>
                <div class="col-sm-3">
                    <div class="input-group">
                        <input type="number" min="0" max="720" step="1" class="form-control roundedLeft" id="in_presenciumRegleAttente" placeholder="0">
                        <span class="input-group-addon roundedRight">{{min}}</span>
                    </div>
                </div>
                <div class="col-sm-6">
                    <span class="help-block" style="margin:0;">{{L'annulation est écrite au journal : on voit combien de fois la règle a failli partir pour rien.}}</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Repos entre deux fois}}
                    <sup><i class="fas fa-question-circle" title="{{Une fois partie, la règle ne peut plus repartir avant ce délai. C'est l'anti-répétition : un détecteur qui hésite ne fera pas envoyer dix notifications en dix minutes. Zéro pour autoriser chaque déclenchement.}}"></i></sup>
                </label>
                <div class="col-sm-3">
                    <div class="input-group">
                        <input type="number" min="0" max="1440" step="1" class="form-control roundedLeft" id="in_presenciumRegleRepos" placeholder="0">
                        <span class="input-group-addon roundedRight">{{min}}</span>
                    </div>
                </div>
                <div class="col-sm-6">
                    <span class="help-block" style="margin:0;">{{Les déclenchements écartés pour cause de repos apparaissent au journal, ils ne disparaissent pas en silence.}}</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">
                    {{Simuler cette règle}}
                    <sup><i class="fas fa-question-circle" title="{{Cette règle est évaluée et journalisée, mais ses actions ne sont jamais envoyées. Utile pour essayer une nouvelle règle pendant que les autres travaillent pour de vrai. La simulation du foyer, ou celle du plugin entier, s'impose de toute façon à toutes les règles.}}"></i></sup>
                </label>
                <div class="col-sm-9">
                    <label class="checkbox-inline" style="padding-left:20px;">
                        <input type="checkbox" id="in_presenciumRegleSimulation"> <b>{{Journaliser sans exécuter}}</b>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">&nbsp;</label>
                <div class="col-sm-9">
                    <!-- L'état EFFECTIF, posé par le JS : la simulation se
                         décide à trois endroits et il suffit d'un seul pour
                         qu'elle s'impose. Cette charpente ne connaît ni le
                         foyer ouvert ni la configuration du plugin ; sans cette
                         zone, une case ci-dessus décochée laisse croire que la
                         règle agira alors que rien ne s'exécutera — et c'est
                         exactement ce qu'on se demande avant « Tester ». -->
                    <div id="div_presenciumRegleSimulationEtat"></div>
                </div>
            </div>
        </fieldset>
    </form>

    <!-- ============================================== PIED ============================================= -->
    <!-- Le compte-rendu d'essai est au-dessus des boutons : c'est là que le
         regard est quand on vient de cliquer sur « Tester ». -->
    <div id="div_presenciumRegleTest"></div>

    <div style="border-top:1px solid rgba(128,128,128,0.3);margin-top:10px;padding-top:10px;">
        <span class="help-block pull-left" style="margin:0;max-width:60%;">{{« Tester » joue la règle maintenant, conditions comprises, sur la version enregistrée du foyer : sauvegardez d'abord si vous venez de la modifier. L'essai est simulé si une simulation est cochée à l'écran ; sinon il commande réellement, après confirmation.}}</span>
        <span class="pull-right">
            <!-- Le title est posé sur l'enveloppe : un bouton désactivé ne
                 reçoit plus la souris. -->
            <span id="span_presenciumTesterRegle" style="display:inline-block;"><a class="btn btn-default" id="bt_presenciumTesterRegle"><i class="fas fa-vial"></i> {{Tester}}</a></span>
            <a class="btn btn-success" id="bt_presenciumValiderRegle"><i class="fas fa-check"></i> {{Valider}}</a>
        </span>
        <div style="clear:both;"></div>
    </div>
</div>
