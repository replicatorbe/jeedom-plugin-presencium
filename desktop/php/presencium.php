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

/* La page d'un plugin est incluse par index.php, qui n'a vérifié que la
 * connexion : le profil, lui, se contrôle ici. Sans ce test, un utilisateur
 * « user » atteint la page et voit toute la configuration du foyer. */
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('presencium');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Les deux familles sont séparées dès le PHP : elles ne se règlent pas de la
   même façon, elles ne se créent pas dans le même ordre, et une grille unique
   où personnes et foyers se mélangent oblige à lire chaque carte pour savoir
   ce qu'on a sous les yeux. */
$presenciumPersonnes = array();
$presenciumFoyers = array();
foreach ($eqLogics as $eqLogic) {
    if ($eqLogic->type() === presencium::TYPE_FOYER) {
        $presenciumFoyers[] = $eqLogic;
    } else {
        $presenciumPersonnes[] = $eqLogic;
    }
}

/* La simulation globale est lue ici, et le bandeau est rendu par le PHP : si
   son affichage dépendait du JS, la seule seconde où l'utilisateur regarde la
   page avant que le script ne s'exécute lui montrerait une installation qui
   a l'air d'agir pour de vrai. */
$presenciumSimulationGlobale = (config::byKey('simulation', 'presencium', 0) == 1);

/* Les libellés des déclencheurs, source unique du tableau des règles et de la
   modale. Traduits par __() avant l'envoi : une chaîne entre doubles
   accolades, traduite APRÈS json_encode, casserait la chaîne JS au premier
   guillemet de la traduction. Les clés sont
   celles de presenciumRegles::DECLENCHEURS. */
$presenciumDeclencheursTextes = array();
foreach (array(
    'arrivee_premier' => __('Le premier arrive — la maison était vide', __FILE__),
    'depart_dernier'  => __('Le dernier part — la maison devient vide', __FILE__),
    'arrivee_tous'    => __('Tout le monde est là', __FILE__),
    'arrivee'         => __('Quelqu\'un arrive', __FILE__),
    'depart'          => __('Quelqu\'un part', __FILE__),
    'vide_depuis'     => __('La maison est vide depuis…', __FILE__),
    'occupee_depuis'  => __('La maison est occupée depuis…', __FILE__),
) as $presenciumCle => $presenciumTexte) {
    $presenciumDeclencheursTextes[] = array('cle' => $presenciumCle, 'texte' => $presenciumTexte);
}
sendVarToJS('presenciumDeclencheursTextes', $presenciumDeclencheursTextes);

/* Le seuil d'une vraie absence, pré-rempli depuis la configuration du plugin
   et borné comme presencium::analyserSource (5 à 720 min). */
$presenciumSeuilVrai = max(5, min(720, (int) presencium::reglageGlobal('seuil_vrai', presencium::SEUIL_VRAI_DEFAUT)));

/* Une vignette. Le nom est composé ici plutôt que pris à getHumanName() : le
   badge d'objet du cœur rend les cartes inégales, et affiche « Aucun » quand
   il n'y a pas d'objet. La ligne d'objet est donc toujours là, vide au besoin,
   pour que les cartes restent alignées.
   Tout tient DANS .name : en vue tableau, le cœur rend .name en position
   absolue sur toute la ligne (desktop.main.css, vers 2140), et ce qui serait
   posé à côté se retrouverait superposé au nom.
   $_etat est du texte ; $_detail et $_badges sont du HTML déjà échappé. */
function presenciumVignette($_eqLogic, $_icone, $_actif, $_etat, $_detail, $_badges) {
    $objet = $_eqLogic->getObject();
    $nom = '<span class="presenciumCarteObjet">'
         . (is_object($objet) ? htmlspecialchars($objet->getName(), ENT_QUOTES, 'UTF-8') : '&nbsp;')
         . '</span><strong>' . htmlspecialchars($_eqLogic->getName(), ENT_QUOTES, 'UTF-8') . '</strong>';
    $etat = htmlspecialchars($_etat, ENT_QUOTES, 'UTF-8');
    echo '<div class="eqLogicDisplayCard cursor presenciumCarte ' . ($_eqLogic->getIsEnable() ? '' : 'disableCard')
       . '" data-eqLogic_id="' . $_eqLogic->getId() . '">';
    echo '<i class="fas ' . $_icone . ' presenciumCarteIcone" style="'
       . ($_actif ? 'color:#5cb85c;' : 'opacity:0.55;') . '"></i>';
    echo '<span class="name">' . $nom;
    echo '<span class="presenciumCarteEtat ' . ($_actif ? 'presenciumPresent' : 'presenciumAbsent')
       . '" title="' . $etat . '">' . $etat . '</span>';
    echo $_detail . $_badges;
    echo '</span>';
    echo '<span class="hiddenAsCard displayTableRight hidden">';
    echo ($_eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
    echo '</span>';
    echo '</div>';
}
?>

<div class="row row-overflow">
    <?php
    if ($presenciumSimulationGlobale) {
        /* Hors des deux panneaux (vignettes et équipement) pour rester visible
           aussi bien dans la liste que pendant l'édition d'une règle : c'est
           pendant l'édition qu'on se demande si elle agira. */
        echo '<div class="col-xs-12" id="div_presenciumBandeauSimulation">';
        echo '<div class="alert alert-warning" style="margin:5px;font-size:1.05em;">';
        echo '<i class="fas fa-flask fa-lg"></i> <b>{{Simulation globale active : aucune action n\'est exécutée.}}</b>';
        echo '<br>{{Les personnes sont suivies, les états publiés, les règles évaluées et journalisées — mais rien n\'est commandé, ni les actions des règles, ni l\'armement de l\'alarme. Décochez « Tout simuler » dans la configuration du plugin pour agir pour de vrai.}}';
        echo '</div>';
        echo '</div>';
    }
    ?>

    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor logoPrimary" id="bt_presenciumAddPersonne">
                <i class="fas fa-user-plus"></i>
                <br>
                <span>{{Ajouter une personne}}</span>
            </div>
            <div class="cursor logoPrimary" id="bt_presenciumAddFoyer">
                <i class="fas fa-home"></i>
                <br>
                <span>{{Ajouter un foyer}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>
        <?php
        if (count($eqLogics) == 0) {
            /* L'ordre compte : un foyer créé en premier est un foyer vide, et
               rien dans l'écran ne dit pourquoi il ne détecte personne. */
            echo '<div class="alert alert-info" style="margin:5px;">';
            echo '<b>{{Rien de configuré pour le moment. L\'ordre a son importance :}}</b>';
            echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
            echo '<li><b>{{D\'abord une personne par balise}}</b> {{: cliquez sur « Ajouter une personne », donnez-lui le prénom de qui elle suit, puis désignez la commande qui porte son signal de présence — la balise Bluetooth, le téléphone sur le wifi, le détecteur de la voiture.}}</li>';
            echo '<li>{{Réglez son}} <b>{{délai de départ}}</b> {{: c\'est lui qui distingue un rebond de balise d\'un vrai départ. 15 minutes est la valeur mesurée sur de vraies balises Tile.}}</li>';
            echo '<li><b>{{Ensuite un foyer qui les rassemble}}</b> {{: cliquez sur « Ajouter un foyer », cochez les personnes qui l\'habitent, et vous obtenez « quelqu\'un est là », « tout le monde est là », « qui est là » et l\'occupation.}}</li>';
            echo '<li>{{Enfin, dans l\'onglet « Règles » du foyer, décrivez ce qui doit se passer sur une arrivée ou un départ. Laissez la simulation active quelques jours : le journal montre ce qui se serait déclenché, et pourquoi.}}</li>';
            echo '</ol>';
            echo '</div>';
        } elseif (count($presenciumFoyers) == 0) {
            echo '<div class="alert alert-info" style="margin:5px;">';
            echo '<i class="fas fa-info-circle"></i> ';
            echo '{{Vos personnes sont suivies, mais aucun foyer ne les rassemble : « quelqu\'un est là », « qui est là » et les règles d\'arrivée et de départ appartiennent au foyer. Cliquez sur « Ajouter un foyer » et cochez-y vos personnes.}}';
            echo '</div>';
        } elseif (count($presenciumPersonnes) == 0) {
            echo '<div class="alert alert-warning" style="margin:5px;">';
            echo '<i class="fas fa-exclamation-triangle"></i> ';
            echo '{{Un foyer sans personne reste vide en permanence : ses règles de départ se déclencheraient sans que personne ne soit jamais parti. Créez d\'abord une personne par balise, puis cochez-la dans le foyer.}}';
            echo '</div>';
        }
        echo '<div class="input-group" style="margin:5px;">';
        echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
        echo '<div class="input-group-btn">';
        echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
        echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
        echo '</div>';
        echo '</div>';

        echo '<legend><i class="fas fa-user"></i> {{Mes personnes}}</legend>';
        echo '<div class="eqLogicThumbnailContainer" id="div_presenciumCartesPersonnes">';
        foreach ($presenciumPersonnes as $eqLogic) {
            $cmdPresence = $eqLogic->getCmd('info', 'presence');
            $cmdEtat = $eqLogic->getCmd('info', 'etat');
            $present = (is_object($cmdPresence) && $cmdPresence->execCmd() == 1);
            $etat = is_object($cmdEtat) ? trim((string) $cmdEtat->execCmd()) : '';
            /* Repli sur la valeur globale, comme le plugin lui-même. */
            $delai = $eqLogic->getConfiguration('delai_depart', '');
            $delai = ($delai === '' || $delai === null) ? (int) config::byKey('delai_depart', 'presencium', 15) : (int) $delai;
            /* Le délai en infobulle : sur 158 px, la phrase ne tient pas. */
            $detail = '<span class="presenciumCarteDetail" title="{{Délai de confirmation d\'un départ : le signal doit rester absent aussi longtemps avant que la personne ne soit déclarée partie.}}">'
                    . '<i class="far fa-clock"></i> ' . $delai . ' {{min}}</span>';
            /* Une personne sans source ne produit aucune erreur : la vignette
               est le seul endroit où cela se voit sans l'ouvrir. */
            $badges = '';
            if ((int) $eqLogic->getConfiguration('source', 0) <= 0) {
                $badges .= '<span class="label label-danger presenciumCarteBadge" title="{{Cette personne ne suit aucune commande : elle restera absente pour toujours et les règles d\'arrivée ne partiront pas.}}">'
                         . '<i class="fas fa-exclamation-triangle"></i> {{Aucune source}}</span>';
            }
            presenciumVignette($eqLogic, $present ? 'fa-user' : 'fa-user-slash', $present,
                ($etat === '') ? __('Pas encore évaluée', __FILE__) : $etat, $detail, $badges);
        }
        echo '</div>';

        echo '<legend><i class="fas fa-home"></i> {{Mes foyers}}</legend>';
        echo '<div class="eqLogicThumbnailContainer" id="div_presenciumCartesFoyers">';
        foreach ($presenciumFoyers as $eqLogic) {
            $membres = $eqLogic->getConfiguration('personnes', array());
            $nombre = is_array($membres) ? count($membres) : 0;
            $cmdPresence = $eqLogic->getCmd('info', 'presence');
            $cmdQui = $eqLogic->getCmd('info', 'qui');
            $occupe = (is_object($cmdPresence) && $cmdPresence->execCmd() == 1);
            $qui = is_object($cmdQui) ? trim((string) $cmdQui->execCmd()) : '';
            $detail = '<span class="presenciumCarteDetail" title="{{Nombre d\'habitants cochés dans ce foyer.}}">'
                    . '<i class="fas fa-users"></i> ' . $nombre . '</span>';
            $badges = '';
            /* Aucun habitant coché alors qu'il y a des personnes : foyer vide
               en permanence, que rien d'autre ne signale. */
            if ($nombre == 0 && count($presenciumPersonnes) > 0) {
                $badges .= '<span class="label label-warning presenciumCarteBadge" title="{{Cochez les habitants de ce foyer : sans eux, il reste vide en permanence.}}">'
                         . '<i class="fas fa-exclamation-triangle"></i> {{Aucun habitant coché}}</span>';
            }
            if ($presenciumSimulationGlobale || ($eqLogic->getConfiguration('simulation', 0) == 1)) {
                $badges .= '<span class="label label-warning presenciumCarteBadge" title="{{Rien n\'est exécuté : les règles de ce foyer sont jouées à blanc et journalisées.}}"><i class="fas fa-flask"></i> {{Simulation}}</span>';
            }
            presenciumVignette($eqLogic, $occupe ? 'fa-home' : 'fa-door-closed', $occupe,
                ($qui === '') ? __('Personne n\'est là', __FILE__) : $qui, $detail, $badges);
        }
        echo '</div>';
        ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
                <a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tag"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
            <!-- Règles et Journal n'existent que pour un foyer : une personne
                 n'a rien à déclencher. Le JS masque ces deux <li> dès qu'il
                 sait le type, c'est pourquoi ils portent un identifiant. -->
            <li role="presentation" id="li_presenciumRegleTab"><a href="#regletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-project-diagram"></i><span class="hidden-xs"> {{Règles}}</span></a></li>
            <li role="presentation" id="li_presenciumJournalTab"><a href="#journaltab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-clipboard-list"></i><span class="hidden-xs"> {{Journal}}</span></a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
        </ul>

        <div class="tab-content">
            <!-- =========================================== ÉQUIPEMENT ========================================== -->
            <div role="tabpanel" class="tab-pane active" id="eqtab">
                <br>
                <!-- Les défauts d'un équipement inachevé — une personne sans
                     commande source, un foyer dont personne n'est coché. Ils ne
                     produisent aucune erreur et ne se voient donc nulle part
                     ailleurs : on ne les découvre que le jour où l'alarme ne
                     s'arme pas. Rempli par le JS, qui seul connaît la
                     composition en cours de saisie. -->
                <div class="col-xs-12" id="div_presenciumAvertissements" style="padding:0 15px;"></div>
                <!-- L'état courant, lu à l'ouverture de l'équipement. Sans lui, on règle
                     un délai de départ sans jamais voir ce qu'il produit : le verdict
                     dit en clair « départ en cours, encore 8 min », ce qu'aucune
                     commande ne montre pendant la temporisation. -->
                <div class="col-xs-12" style="padding:0 15px 10px 15px;">
                    <div class="input-group">
                        <div id="div_presenciumVerdict" class="form-control" style="height:auto;min-height:34px;"></div>
                        <span class="input-group-btn">
                            <a class="btn btn-default" id="bt_presenciumEvaluer" title="{{Relire les balises et rejouer les règles tout de suite, sans attendre la minute suivante}}"><i class="fas fa-sync"></i> {{Réévaluer}}</a>
                        </span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <form class="form-horizontal">
                        <fieldset>
                            <legend><i class="fas fa-tag"></i> {{Général}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom}}</label>
                                <div class="col-sm-7">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Un prénom}}">
                                    <!-- Le type est décidé à la création et ne se change plus :
                                         basculer une personne en foyer laisserait derrière lui
                                         des commandes qui n'ont plus de sens et un écouteur posé
                                         sur une source que plus rien ne lit. -->
                                    <input class="eqLogicAttr" data-l1key="configuration" data-l2key="type" id="in_presenciumType" style="display:none;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-7">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        foreach (jeeObject::buildTree(null, false) as $object) {
                                            echo '<option value="' . $object->getId() . '">'
                                               . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber'))
                                               . htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8') . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-8">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline">';
                                        echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">'
                                           . htmlspecialchars($value['name'], ENT_QUOTES, 'UTF-8');
                                        echo '</label>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Activer}}</label>
                                <div class="col-sm-2">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
                                </div>
                                <label class="col-sm-2 control-label">{{Visible}}</label>
                                <div class="col-sm-2">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
                                </div>
                            </div>
                        </fieldset>
                    </form>
                </div>

                <div class="col-lg-7">
                    <!-- ===================== PERSONNE ===================== -->
                    <div id="div_presenciumPersonne" style="display:none;">
                        <form class="form-horizontal">
                            <fieldset>
                                <legend><i class="fas fa-satellite-dish"></i> {{Le signal suivi}}</legend>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Commande de présence}}
                                        <sup><i class="fas fa-question-circle" title="{{La commande info qui porte le signal brut de cette personne : la balise Tile, le téléphone vu par le wifi, le détecteur de la voiture. C'est la seule entrée du plugin ; tout le reste en découle.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-8">
                                        <div class="input-group">
                                            <!-- Readonly : le nom affiché est reconstruit par le cœur
                                                 à partir de l'identifiant enregistré, taper dedans ne
                                                 désignerait rien. -->
                                            <input type="text" class="form-control roundedLeft" id="in_presenciumSourceNom" readonly placeholder="{{Aucune commande choisie}}">
                                            <span class="input-group-btn">
                                                <a class="btn btn-default" id="bt_presenciumChoisirSource" title="{{Choisir la commande dans la liste complète}}"><i class="fas fa-search"></i></a>
                                                <a class="btn btn-default roundedRight" id="bt_presenciumViderSource" title="{{Retirer la commande}}"><i class="fas fa-times"></i></a>
                                            </span>
                                        </div>
                                        <!-- L'identifiant seul est enregistré : il survit au
                                             renommage de l'équipement source, le nom non. -->
                                        <input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="source" style="display:none;">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Choix rapide}}
                                        <sup><i class="fas fa-question-circle" title="{{Les commandes que le plugin a reconnues comme des commandes de présence dans votre installation. Si la vôtre n'y est pas, utilisez la loupe : elle montre toutes les commandes info.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-8">
                                        <select class="form-control" id="sel_presenciumSourceRapide">
                                            <option value="">{{Commandes de présence trouvées…}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Valeur « présent »}}
                                        <sup><i class="fas fa-question-circle" title="{{La valeur que prend la commande quand la personne est là. « 1 » convient à la quasi-totalité des détecteurs ; certains publient « on » ou « present », et le plugin les accepte aussi.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-3">
                                        <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="valeur_presente" placeholder="1">
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset>
                                <legend><i class="fas fa-hourglass-half"></i> {{Anti-rebond}}</legend>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Délai de départ}}
                                        <sup><i class="fas fa-question-circle" title="{{C'est le réglage qui sépare le rebond d'une balise d'un vrai départ : le plugin ne déclare la personne absente que si le signal reste absent pendant tout ce délai, et un retour entre-temps ne produit aucun départ. 15 minutes est la valeur mesurée sur de vraies balises Tile, dont les fausses absences vont de 2 secondes à 13 minutes, alors que les vraies dépassent l'heure.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-4">
                                        <div class="input-group">
                                            <input type="number" min="0" max="720" step="1" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="delai_depart" placeholder="15">
                                            <span class="input-group-addon roundedRight">{{min}}</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <span class="help-block" style="margin:0;">{{Baisser ce délai rend les départs plus rapides et les faux départs plus fréquents : c'est une alarme qui s'arme sur quelqu'un assis dans son salon.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Délai d'arrivée}}
                                        <sup><i class="fas fa-question-circle" title="{{Secondes de signal continu avant de déclarer l'arrivée. Zéro dans presque tous les cas : une arrivée manquée, c'est une porte qui ne s'ouvre pas et une lumière qui ne s'allume pas. Ne montez cette valeur que si votre détecteur signale des présences fugaces en passant devant la maison.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-4">
                                        <div class="input-group">
                                            <input type="number" min="0" max="3600" step="10" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="delai_arrivee" placeholder="0">
                                            <span class="input-group-addon roundedRight">{{s}}</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <span class="help-block" style="margin:0;">{{L'asymétrie entre les deux délais est voulue : on confirme lentement un départ, vite une arrivée.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">&nbsp;</label>
                                    <div class="col-sm-8">
                                        <span class="help-block" style="margin:0;">{{Le résultat est publié par la commande « Présence », et « Signal brut » garde la valeur du détecteur telle quelle : comparer les deux dans l'historique montre exactement ce que le plugin a absorbé.}}</span>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset>
                                <legend><i class="fas fa-vial"></i> {{Éprouver et régler}}</legend>

                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Forcer la présence}}
                                        <sup><i class="fas fa-question-circle" title="{{Pour éprouver une règle sans sortir de chez vous. Le forçage remplace le signal de la balise jusqu'à ce que vous rendiez la main au suivi automatique ; il survit à un redémarrage.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-7">
                                        <a class="btn btn-sm btn-success" id="bt_presenciumForcerPresent"><i class="fas fa-user"></i> {{Présent}}</a>
                                        <a class="btn btn-sm btn-default" id="bt_presenciumForcerAbsent"><i class="fas fa-user-slash"></i> {{Absent}}</a>
                                        <a class="btn btn-sm btn-default" id="bt_presenciumAuto"><i class="fas fa-magic"></i> {{Rendre la main}}</a>
                                        <span class="help-block" style="margin:4px 0 0 0;">{{Tant qu'un forçage est actif, la balise n'a plus voix au chapitre. La fiche et la vignette le disent.}}</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Analyser la balise}}
                                        <sup><i class="fas fa-question-circle" title="{{Relit l'historique de la commande suivie et rejoue la décision pour plusieurs délais de départ. Le tableau dit, sur VOS données, combien de faux départs chaque délai supprime et combien de vrais départs il laisse passer.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-7">
                                        <div class="input-group">
                                            <span class="input-group-addon">{{sur}}</span>
                                            <input type="number" min="1" max="90" step="1" class="form-control input-sm" id="in_presenciumAnalyseJours" value="7">
                                            <span class="input-group-addon">{{jours, une vraie absence dure au moins}}</span>
                                            <input type="number" min="5" max="720" step="1" class="form-control input-sm" id="in_presenciumAnalyseSeuil" value="<?php echo $presenciumSeuilVrai; ?>">
                                            <span class="input-group-addon">{{min}}</span>
                                            <span class="input-group-btn">
                                                <a class="btn btn-sm btn-default" id="bt_presenciumAnalyser"><i class="fas fa-chart-line"></i> {{Analyser}}</a>
                                            </span>
                                        </div>
                                        <span class="help-block" style="margin:4px 0 0 0;">{{La commande suivie doit être historisée, sinon il n'y a rien à relire.}}</span>
                                        <div id="div_presenciumAnalyse"></div>
                                    </div>
                                </div>
                            </fieldset>
                        </form>
                    </div>

                    <!-- ====================== FOYER ====================== -->
                    <div id="div_presenciumFoyer" style="display:none;">
                        <form class="form-horizontal">
                            <fieldset>
                                <legend><i class="fas fa-users"></i> {{Les habitants}}</legend>
                                <div class="form-group">
                                    <div class="col-sm-12">
                                        <span class="help-block" style="margin:0 0 8px 0;">{{Cochez les personnes qui habitent ce foyer. L'occupation, « qui est là », le premier arrivé et le dernier parti se calculent à partir d'elles, et les règles d'arrivée et de départ n'ont de sens que sur cette liste.}}</span>
                                        <!-- Les cases sont posées par le JS : la liste des
                                             personnes dépasse trois niveaux de clés, et le cœur
                                             ne ramasse pas les eqLogicAttr au-delà de data-l3key. -->
                                        <div id="div_presenciumListePersonnes"></div>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset>
                                <legend><i class="fas fa-flask"></i> {{Simulation}}</legend>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">
                                        {{Simuler ce foyer}}
                                        <sup><i class="fas fa-question-circle" title="{{En simulation, tout fonctionne sauf l'exécution : les personnes sont suivies, les règles évaluées, les attentes tenues, et chaque décision est écrite au journal avec la mention « simulée ». Aucune action n'est envoyée, ni l'armement de l'alarme. C'est le détecteur de faux positifs : laissez tourner quelques jours et relisez le journal.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-8">
                                        <label class="checkbox-inline" style="padding-left:20px;">
                                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="simulation" id="in_presenciumSimulation">
                                            <b>{{Ne rien exécuter, seulement journaliser ce qui se serait passé}}</b>
                                        </label>
                                    </div>
                                </div>
                                <?php
                                if ($presenciumSimulationGlobale) {
                                    echo '<div class="form-group">';
                                    echo '<label class="col-sm-4 control-label">&nbsp;</label>';
                                    echo '<div class="col-sm-8">';
                                    echo '<span class="help-block text-warning" style="margin:0;"><i class="fas fa-flask"></i> ';
                                    echo '{{La simulation globale est active dans la configuration du plugin : ce foyer est simulé même si cette case est décochée.}}';
                                    echo '</span>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                ?>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">&nbsp;</label>
                                    <div class="col-sm-8">
                                        <span class="help-block" style="margin:0;">{{La simulation se règle à trois endroits, et il suffit d'un seul pour qu'elle s'applique : la configuration du plugin (tout), ce foyer, ou une règle en particulier. La commande « Mode simulation » du foyer le montre sur le tableau de bord.}}</span>
                                    </div>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ============================================= RÈGLES ============================================= -->
            <div role="tabpanel" class="tab-pane" id="regletab">
                <br>
                <div class="col-xs-12">
                    <div class="alert alert-info" style="margin:0 0 10px 0;">
                        {{Une règle relie un moment — une arrivée, un départ, une maison vide depuis un moment — à des actions, sous conditions. Elles sont examinées dans l'ordre de cette liste, chaque minute et à chaque changement de présence.}}
                        <br>{{Tant que la simulation est active, elles sont évaluées et journalisées sans rien exécuter : l'onglet « Journal » dit ce qui se serait passé.}}
                    </div>
                    <!-- Montré par le JS tant que la liste diffère de celle
                         chargée : les règles ne partent qu'au « Sauvegarder ». -->
                    <div class="alert alert-warning" id="div_presenciumReglesNonEnregistrees" style="display:none;margin:0 0 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> {{Modifications non enregistrées : les règles ci-dessous ne s'appliqueront qu'après « Sauvegarder ».}}
                    </div>
                    <legend>
                        <i class="fas fa-project-diagram"></i> {{Règles de ce foyer}}
                        <a class="btn btn-sm btn-success pull-right" id="bt_presenciumAjouterRegle"><i class="fas fa-plus"></i> {{Ajouter une règle}}</a>
                    </legend>
                    <!-- Le thead est figé ici, le tbody est rempli par le JS :
                         les règles dépassent trois niveaux de clés et ne peuvent
                         pas être écrites en eqLogicAttr. -->
                    <table class="table table-bordered table-condensed" id="table_presenciumRegles">
                        <thead>
                            <tr>
                                <th>{{Nom}}</th>
                                <th>{{Déclencheur}}</th>
                                <th>{{Conditions}}</th>
                                <th>{{Actions}}</th>
                                <th style="width:170px;">{{Action}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <span class="help-block" style="margin:0;">{{Les règles ne sont enregistrées qu'au « Sauvegarder » de l'équipement, comme le reste de la page.}}</span>
                </div>
            </div>

            <!-- ============================================= JOURNAL ============================================ -->
            <div role="tabpanel" class="tab-pane" id="journaltab">
                <br>
                <div class="col-xs-12">
                    <!-- Le texte et le titre sont repris par le JS selon le type
                         ouvert : une personne et un foyer n'écrivent pas les mêmes
                         lignes, et annoncer des règles dans le journal d'une
                         personne ferait chercher ce qui ne s'y trouve jamais. -->
                    <div class="alert alert-info" style="margin:0 0 10px 0;" id="div_presenciumJournalIntro">
                        {{Le journal garde ce que le plugin a décidé, et pourquoi : les règles déclenchées, celles écartées par leurs conditions ou par leur horaire, et les mouvements de présence — dont les rebonds absorbés, qui montrent ce que votre détecteur raconte vraiment.}}
                    </div>
                    <legend>
                        <i class="fas fa-clipboard-list"></i> <span id="span_presenciumJournalTitre">{{Journal de ce foyer}}</span>
                        <span class="pull-right">
                            <select class="form-control input-sm" id="sel_presenciumFiltreJournal" style="display:inline-block;width:auto;vertical-align:middle;">
                                <option value="tout">{{Tout}}</option>
                                <option value="regle">{{Règles}}</option>
                                <option value="presence">{{Présence}}</option>
                                <option value="alarme">{{Alarme}}</option>
                            </select>
                            <a class="btn btn-sm btn-default" id="bt_presenciumRafraichirJournal"><i class="fas fa-sync"></i> {{Rafraîchir}}</a>
                            <a class="btn btn-sm btn-default" id="bt_presenciumExporterJournal" title="{{Une semaine de campagne se relit dans un tableur : on y trie par verdict et on compte.}}"><i class="fas fa-file-csv"></i> {{Exporter}}</a>
                            <a class="btn btn-sm btn-danger" id="bt_presenciumViderJournal"><i class="fas fa-trash"></i> {{Vider}}</a>
                        </span>
                    </legend>
                    <div id="div_presenciumJournal"></div>
                </div>
            </div>

            <!-- ============================================ COMMANDES =========================================== -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br>
                <div class="alert alert-info" style="margin:5px;">
                    {{Ces commandes sont créées et tenues à jour par le plugin : elles réapparaissent à chaque enregistrement, il est donc inutile de les supprimer. Vous pouvez en revanche les renommer et changer leur visibilité, ces choix-là sont respectés.}}
                    <br>{{Sur une personne : « Présence » est la présence stabilisée, « Signal brut » la valeur du détecteur, et les commandes « Forcer » permettent de passer outre le temps d'un dépannage — « Suivi automatique » rend la main au plugin.}}
                    <br>{{Sur un foyer : « Présence », « Tout le monde est là », « Occupation » et « Qui est là » se posent sur un tableau de bord ; « Armer », « Désarmer » et la mise en service pilotent l'alarme que le plugin porte lui-même.}}
                </div>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Type}}</th>
                            <th>{{Options}}</th>
                            <th>{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'presencium', 'css', 'presencium'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'presencium', 'js', 'presencium'); ?>
