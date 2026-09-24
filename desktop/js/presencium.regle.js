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
 * Les règles d'un foyer : le tableau de l'onglet Règles et l'éditeur
 * (desktop/modal/regle.editor.php), qui n'embarque aucun script — il vit de
 * celui-ci, chargé avec la page.
 *
 * Chargé avant presencium.js, dont il emploie le socle (presenciumAjax,
 * presenciumText…) et l'état partagé (presenciumRegles…) : rien n'est appelé
 * ici au chargement, seulement à l'usage.
 */

/* ============================================================== VARIABLES */

/* La règle en cours d'édition dans la modale : son rang dans presenciumRegles,
   -1 pour une règle neuve qui n'y est pas encore. */
var presenciumRegleIndex = -1

/* La règle en cours d'édition, telle que la modale la manipule. Conditions et
   actions y sont relues à chaque geste : ajouter une ligne redessine le
   tableau, et ce qui n'aurait pas été relu avant serait perdu. */
var presenciumRegleCourante = null

/* Les déclencheurs, dans l'ordre où ils sont proposés : { cle, texte }.
   Source unique, posée par desktop/php/presencium.php (sendVarToJS) : le
   tableau et la modale affichent ainsi le même libellé. */
var presenciumDeclencheurs = (typeof presenciumDeclencheursTextes !== 'undefined' && Array.isArray(presenciumDeclencheursTextes))
  ? presenciumDeclencheursTextes : []

/* Vrai dès qu'un geste touche la règle ouverte dans la modale. */
var presenciumRegleModifiee = false

/* Mêmes valeurs que presenciumRegles::OPERATEURS. */
var presenciumOperateurs = ['==', '!=', '>', '>=', '<', '<=']

/* 1 = lundi … 7 = dimanche, comme dans le schéma d'une règle. */
var presenciumJours = [
  { jour: 1, texte: '{{Lun}}' }, { jour: 2, texte: '{{Mar}}' },
  { jour: 3, texte: '{{Mer}}' }, { jour: 4, texte: '{{Jeu}}' },
  { jour: 5, texte: '{{Ven}}' }, { jour: 6, texte: '{{Sam}}' },
  { jour: 7, texte: '{{Dim}}' }
]

/* ============================================================ LES RÈGLES */

/* Le libellé d'un déclencheur, personne et minutes comprises : « Quelqu'un
   part » sans dire qui n'aide personne à relire son tableau. */
function presenciumLibelleDeclencheur(_regle) {
  var cle = String(init(_regle.declencheur, ''))
  var texte = cle
  for (var i = 0; i < presenciumDeclencheurs.length; i++) {
    if (presenciumDeclencheurs[i].cle === cle) { texte = presenciumDeclencheurs[i].texte }
  }
  if (cle === 'arrivee' || cle === 'depart') {
    var id = presenciumEntier(_regle.personne, 0, 0, 99999999)
    var nom = '{{n\'importe qui}}'
    for (var p = 0; p < presenciumPersonnes.length; p++) {
      if (parseInt(presenciumPersonnes[p].id, 10) === id) { nom = presenciumPersonnes[p].nom }
    }
    texte += ' (' + ((id === 0) ? '{{n\'importe qui}}' : nom) + ')'
  }
  if (cle === 'vide_depuis' || cle === 'occupee_depuis') {
    /* Borné comme le serveur (presenciumRegles::MINUTES_MAX), mais le plancher
       reste à zéro POUR L'AFFICHAGE : une règle enregistrée jadis avec 0
       doit se lire « 0 min » dans le tableau — c'est ce qui la dénonce. La
       fenêtre d'édition, elle, refuse de la réenregistrer ainsi. */
    texte += ' ' + presenciumEntier(_regle.minutes, 0, 0, 10080) + ' {{min}}'
  }
  return texte
}

/*
 * Le tableau des règles.
 *
 * Cinq colonnes : Nom, Déclencheur, Conditions, Actions, boutons. Les lignes
 * sont construites en DOM et non par insertAdjacentHTML : sur un <table>,
 * chaque insertion crée un <tbody> de plus.
 */
function presenciumRenderRegles() {
  var table = document.getElementById('table_presenciumRegles')
  if (table === null) { return }
  var tbody = table.querySelector('tbody')
  if (tbody === null) { return }
  tbody.innerHTML = ''
  presenciumBandeauRegles()

  /* « Aucune règle » et « je n'ai pas lu les règles » sont deux choses
     différentes, et les confondre est la pire des réponses : elle fait croire
     à une perte de saisie alors que tout est en base. */
  var ouvert = presenciumCurrentId(true)
  if (ouvert !== null && String(presenciumChargePour) !== String(ouvert)) {
    var ligneInconnue = document.createElement('tr')
    var celluleInconnue = document.createElement('td')
    celluleInconnue.setAttribute('colspan', '5')
    var avertissement = presenciumText('div', 'alert alert-warning',
      '{{Les règles de cet équipement n\'ont pas été lues — elles ne sont pas perdues, elles sont en base. Rechargez pour les afficher.}} ')
    var bouton = document.createElement('a')
    bouton.className = 'btn btn-default btn-sm'
    bouton.id = 'bt_presenciumRechargerRegles'
    bouton.innerHTML = '<i class="fas fa-sync"></i> {{Recharger}}'
    avertissement.appendChild(bouton)
    celluleInconnue.appendChild(avertissement)
    ligneInconnue.appendChild(celluleInconnue)
    tbody.appendChild(ligneInconnue)
    return
  }

  if (presenciumRegles.length === 0) {
    var ligneVide = document.createElement('tr')
    var celluleVide = document.createElement('td')
    celluleVide.setAttribute('colspan', '5')
    celluleVide.appendChild(presenciumText('div', 'alert alert-info',
      '{{Aucune règle. Sans règle, ce foyer publie la présence mais ne déclenche rien. Commencez par « Ajouter une règle », laissez-la en simulation quelques jours, puis relisez le journal.}}'))
    ligneVide.appendChild(celluleVide)
    tbody.appendChild(ligneVide)
    return
  }

  for (var i = 0; i < presenciumRegles.length; i++) {
    tbody.appendChild(presenciumLigneRegle(presenciumRegles[i], i))
  }
}

function presenciumLigneRegle(_regle, _index) {
  var tr = document.createElement('tr')
  tr.setAttribute('data-index', String(_index))

  /* Nom et état. Une règle désactivée ou en simulation doit se voir depuis le
     tableau : c'est la première question posée quand « rien ne se passe ». */
  var cellNom = document.createElement('td')
  cellNom.appendChild(presenciumText('b', '', init(_regle.nom, '{{Règle sans nom}}')))
  if (init(_regle.actif, 1) != 1) { cellNom.appendChild(presenciumBadge('default', '{{désactivée}}')) }
  if (init(_regle.simulation, 0) == 1) { cellNom.appendChild(presenciumBadge('warning', '{{simulation}}')) }
  var attente = presenciumEntier(_regle.attente, 0, 0, 720)
  if (attente > 0) { cellNom.appendChild(presenciumBadge('info', '{{attente}} ' + attente + ' {{min}}')) }
  var repos = presenciumEntier(_regle.repos, 0, 0, 1440)
  if (repos > 0) { cellNom.appendChild(presenciumBadge('default', '{{repos}} ' + repos + ' {{min}}')) }
  tr.appendChild(cellNom)

  var cellDeclencheur = document.createElement('td')
  cellDeclencheur.appendChild(presenciumText('span', '', presenciumLibelleDeclencheur(_regle)))
  tr.appendChild(cellDeclencheur)

  /* Conditions : l'horaire, les jours, puis les lignes. */
  var conditions = isset(_regle.conditions) ? _regle.conditions : {}
  var cellConditions = document.createElement('td')
  var heures = isset(conditions.heures) ? conditions.heures : {}
  if (init(heures.actif, 0) == 1) {
    cellConditions.appendChild(presenciumText('div', 'text-muted',
      presenciumPlageJourneeEntiere(heures.de, heures.a)
        ? '{{toute la journée}}'
        : init(heures.de, '00:00') + ' → ' + init(heures.a, '23:59')))
  }
  var jours = (isset(conditions.jours) && Array.isArray(conditions.jours)) ? conditions.jours : []
  if (jours.length > 0 && jours.length < 7) {
    var noms = []
    for (var j = 0; j < presenciumJours.length; j++) {
      if (jours.indexOf(presenciumJours[j].jour) !== -1) { noms.push(presenciumJours[j].texte) }
    }
    cellConditions.appendChild(presenciumText('div', 'text-muted', noms.join(' ')))
  }
  var lignes = (isset(conditions.lignes) && Array.isArray(conditions.lignes)) ? conditions.lignes : []
  for (var c = 0; c < lignes.length; c++) {
    var libelle = init(lignes[c].nom, '')
    if (libelle === '') { libelle = '#' + init(lignes[c].cmd, '?') + '#' }
    cellConditions.appendChild(presenciumText('div', '',
      libelle + ' ' + init(lignes[c].operateur, '==') + ' ' + init(lignes[c].valeur, '')))
  }
  if (cellConditions.childNodes.length === 0) {
    cellConditions.appendChild(presenciumText('span', 'text-muted', '{{aucune}}'))
  }
  tr.appendChild(cellConditions)

  var cellActions = document.createElement('td')
  var actions = (isset(_regle.actions) && Array.isArray(_regle.actions)) ? _regle.actions : []
  if (actions.length === 0) {
    cellActions.appendChild(presenciumText('span', 'text-danger',
      '{{aucune action : cette règle ne fera jamais rien}}'))
  }
  for (var a = 0; a < actions.length; a++) {
    cellActions.appendChild(presenciumText('div', '', init(actions[a].cmd, '')))
  }
  tr.appendChild(cellActions)

  var cellBoutons = document.createElement('td')
  cellBoutons.style.whiteSpace = 'nowrap'
  cellBoutons.style.textAlign = 'right'
  cellBoutons.innerHTML =
    '<a class="btn btn-xs btn-default presenciumMonterRegle" title="{{Monter : les règles sont évaluées dans l\'ordre}}"><i class="fas fa-arrow-up"></i></a> '
    + '<a class="btn btn-xs btn-default presenciumDescendreRegle" title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a> '
    + '<a class="btn btn-xs btn-default presenciumBasculerRegle" title="{{Activer ou désactiver}}"><i class="fas fa-power-off"></i></a> '
    + '<a class="btn btn-xs btn-default presenciumDupliquerRegle" title="{{Dupliquer}}"><i class="fas fa-clone"></i></a> '
    + '<a class="btn btn-xs btn-default presenciumEditerRegle" title="{{Modifier}}"><i class="fas fa-cogs"></i></a> '
    + '<a class="btn btn-xs btn-danger presenciumSupprimerRegle" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a>'
  tr.appendChild(cellBoutons)

  return tr
}

/* Le bandeau « non enregistré » de l'onglet Règles : visible tant que la liste
   diffère de celle chargée. */
function presenciumBandeauRegles() {
  var bandeau = document.getElementById('div_presenciumReglesNonEnregistrees')
  if (bandeau === null) { return }
  var ouvert = presenciumCurrentId(true)
  var charge = (ouvert === null || String(presenciumChargePour) === String(ouvert))
  bandeau.style.display = (charge && JSON.stringify(presenciumRegles) !== presenciumReglesChargees) ? '' : 'none'
}

/* De == À : le serveur lit la plage comme la journée entière. */
function presenciumPlageJourneeEntiere(_de, _a) {
  return String(init(_de, '00:00')) === String(init(_a, '23:59'))
}

/* Une règle vierge, avec les valeurs que le serveur poserait de toute façon :
   un formulaire vide ferait croire qu'une règle neuve ne s'applique aucun
   jour, et l'erreur ne se verrait qu'au bout d'une semaine. */
function presenciumRegleVierge() {
  return {
    id: presenciumNouvelIdentifiant(),
    nom: '',
    actif: 1,
    declencheur: 'depart_dernier',
    personne: 0,
    minutes: 30,
    attente: 0,
    repos: 10,
    /* En simulation par défaut : une règle neuve n'a encore jamais été relue
       dans le journal, et la première chose qu'on lui demande est de montrer
       ce qu'elle ferait — pas de le faire. */
    simulation: 1,
    conditions: {
      heures: { actif: 0, de: '00:00', a: '23:59' },
      jours: [1, 2, 3, 4, 5, 6, 7],
      lignes: []
    },
    actions: []
  }
}

/* ================================================== MODALE D'ÉDITION D'UNE RÈGLE */

/*
 * Ouvre l'éditeur sur la règle de rang _index, ou -1 pour une règle neuve.
 *
 * Attention en relisant : jeeDialog ne mémorise les options d'une fenêtre
 * qu'au PREMIER appel de la session (core/dom/dom.ui.js : au second passage,
 * seul un _options local est recalculé, dialogContainer._jeeDialog.options
 * n'est plus réécrit). Le callback posé ici est donc rejoué pour TOUTE autre
 * modale ouverte ensuite sur #jee_modal — « Configuration avancée », la
 * configuration du plugin — qui, elles, n'en passent aucun. C'est
 * presenciumRegleEditeurDemarrer qui s'en protège, en ne faisant rien quand le
 * contenu chargé n'est pas celui de l'éditeur.
 */
function presenciumOuvrirRegle(_index) {
  if (typeof jeeDialog === 'undefined') {
    jeedomUtils.showAlert({ message: '{{Cette version de Jeedom ne sait pas ouvrir l\'éditeur.}}', level: 'danger' })
    return
  }
  var index = _index
  jeeDialog.dialog({
    id: 'jee_modal',
    title: '{{Règle}}',
    contentUrl: 'index.php?v=d&plugin=presencium&modal=regle.editor',
    callback: function () { presenciumRegleEditeurDemarrer(index) }
  })
}

/*
 * La racine de l'éditeur : le conteneur que porte sa charpente, et rien
 * d'autre — jamais #jee_modal en repli.
 *
 * #jee_modal est créé une fois par le coeur et n'est JAMAIS retiré du DOM : sa
 * fermeture vide seulement .jeeDialogContent (core/dom/dom.ui.js, close()).
 * Des écouteurs posés dessus survivraient à la session et s'ajouteraient à
 * ceux de l'ouverture suivante : chaque clic compterait double, jusqu'à
 * « Tester » exécutant deux fois des actions réelles. Sans charpente
 * d'éditeur, il n'y a pas d'éditeur : on ne branche rien.
 */
function presenciumRacineModale() {
  return document.getElementById('div_presenciumRegleEditeur')
}

function presenciumFermerModale() {
  if (typeof jeeDialog === 'undefined') { return }
  /* La fenêtre qui porte l'éditeur, et non « la » modale : le coeur sait en
     empiler plusieurs, et fermer la mauvaise emporterait le travail d'à côté. */
  var dialog = jeeDialog.get('#div_presenciumRegleEditeur') || jeeDialog.get('#jee_modal')
  if (dialog !== null && typeof dialog.close === 'function') { dialog.close() }
}

/*
 * La fenêtre existe : on la remplit et on branche ses écouteurs.
 *
 * Le premier geste est de vérifier que le contenu chargé est bien celui de
 * l'éditeur, et ce n'est pas une précaution de principe : le coeur rejoue ce
 * callback pour toute modale ouverte ensuite sur #jee_modal (voir
 * presenciumOuvrirRegle). Ouvrir l'éditeur puis « Configuration » suffisait à
 * relancer ce démarrage sur la page de configuration du plugin.
 */
function presenciumRegleEditeurDemarrer(_index) {
  var racine = presenciumRacineModale()
  if (racine === null) { return }

  presenciumRegleIndex = isset(_index) ? parseInt(_index, 10) : -1
  if (isNaN(presenciumRegleIndex)) { presenciumRegleIndex = -1 }

  /* Une copie, pas la règle elle-même : fermer la fenêtre sans valider ne doit
     rien changer au tableau. */
  presenciumRegleCourante = (presenciumRegleIndex >= 0 && isset(presenciumRegles[presenciumRegleIndex]))
    ? JSON.parse(JSON.stringify(presenciumRegles[presenciumRegleIndex]))
    : presenciumRegleVierge()

  /* Une règle venue d'une configuration ancienne, importée ou éditée à la main
     peut n'avoir ni conditions ni actions. Sans ce filet, l'éditeur s'ouvrait
     sur une erreur JS et restait vide : ni formulaire, ni message, et la règle
     paraissait perdue. */
  if (!isset(presenciumRegleCourante.conditions) || presenciumRegleCourante.conditions === null) {
    presenciumRegleCourante.conditions = {}
  }
  if (!Array.isArray(presenciumRegleCourante.conditions.lignes)) {
    presenciumRegleCourante.conditions.lignes = []
  }
  if (!Array.isArray(presenciumRegleCourante.actions)) {
    presenciumRegleCourante.actions = []
  }

  presenciumRegleEditeurBrancher(racine)
  presenciumRegleEditeurPoser()
  presenciumRegleModifiee = false
}

/*
 * Branche les écouteurs de l'éditeur sur sa charpente.
 *
 * Trois protections contre le double comptage des clics — donc « Tester »
 * exécutant deux fois des actions réelles :
 *  - l'attribut, qui dit que cette fenêtre-ci est déjà branchée ;
 *  - des fonctions NOMMÉES de portée module plutôt que des fermetures
 *    anonymes : addEventListener ignore un doublon exact — même cible, même
 *    fonction, mêmes options — ce qu'il ne peut pas faire d'une fonction
 *    recréée à chaque appel ;
 *  - la racine (presenciumRacineModale) qui ne peut plus désigner un élément
 *    survivant à la fermeture.
 */
function presenciumRegleEditeurBrancher(_racine) {
  if (_racine.getAttribute('data-presencium-branche') === '1') { return }
  _racine.setAttribute('data-presencium-branche', '1')
  _racine.addEventListener('click', presenciumRegleEditeurClic)
  _racine.addEventListener('change', presenciumRegleEditeurChangement)
  _racine.addEventListener('focusout', presenciumRegleEditeurSortieChamp)
  _racine.addEventListener('input', presenciumRegleEditeurSaisie)

  /* La croix de la fenêtre. beforeClose du coeur ne sait pas annuler une
     fermeture (dom.ui.js, close() ignore son retour) : on intercepte donc le
     clic en phase de capture, sur le conteneur, avant l'écouteur du bouton.
     Le conteneur survit à la fenêtre : la fonction nommée évite l'empilement,
     et elle ne fait rien quand l'éditeur n'y est plus. */
  var dialogue = _racine.closest('div.jeeDialog')
  if (dialogue !== null) {
    dialogue.addEventListener('click', presenciumRegleEditeurCroix, true)
  }
}

function presenciumRegleEditeurSaisie() {
  if (presenciumRegleCourante !== null) { presenciumRegleModifiee = true }
}

function presenciumRegleEditeurCroix(event) {
  var croix = event.target.closest('button.btClose')
  if (croix === null || croix.closest('div.jeeDialog') !== event.currentTarget) { return }
  var racine = presenciumRacineModale()
  if (racine === null || !event.currentTarget.contains(racine)) { return }
  if (!presenciumRegleModifiee || presenciumRegleCourante === null) { return }
  event.stopPropagation()
  event.preventDefault()
  presenciumConfirmer('{{Fermer sans valider ? Les modifications faites à cette règle seront perdues.}}', function () {
    presenciumRegleModifiee = false
    presenciumFermerModale()
  })
}

/* Une confirmation, par jeeDialog, bootbox à défaut, sinon le navigateur. */
function presenciumConfirmer(_message, _oui) {
  if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.confirm === 'function') {
    jeeDialog.confirm(_message, function (reponse) { if (reponse === true) { _oui() } })
    return
  }
  if (typeof bootbox !== 'undefined' && typeof bootbox.confirm === 'function') {
    bootbox.confirm(_message, function (reponse) { if (reponse === true) { _oui() } })
    return
  }
  if (window.confirm(_message)) { _oui() }
}

function presenciumRegleEditeurClic(event) {
  var cible = null
  /* Une fenêtre sans règle courante n'a rien à modifier : le geste ne peut
     venir que d'un contenu qui n'est plus celui de l'éditeur. */
  if (presenciumRegleCourante === null) { return }

  if (event.target.closest('#bt_presenciumAjouterCondition, .presenciumRetirerCondition, #bt_presenciumAjouterAction, .presenciumRetirerAction')) {
    presenciumRegleModifiee = true
  }

  if (event.target.closest('#bt_presenciumAjouterCondition')) {
    presenciumRegleEditeurLireConditions()
    presenciumRegleCourante.conditions.lignes.push({ cmd: 0, operateur: '==', valeur: '1', nom: '' })
    presenciumRegleEditeurRendreConditions()
    return
  }

  if (cible = event.target.closest('.presenciumRetirerCondition')) {
    presenciumRegleEditeurLireConditions()
    var rang = parseInt(cible.closest('tr').getAttribute('data-index'), 10)
    presenciumRegleCourante.conditions.lignes.splice(rang, 1)
    presenciumRegleEditeurRendreConditions()
    return
  }

  if (cible = event.target.closest('.presenciumChoisirCondition')) {
    var ligne = cible.closest('tr')
    var champ = ligne.querySelector('.presenciumConditionNom')
    /* Le sélecteur du coeur, filtré sur les commandes d'information : une
       condition porte sur un état, jamais sur un bouton. Son callback n'est
       PAS appelé si l'utilisateur valide sans rien choisir — il n'y a donc
       rien à remettre en place dans ce cas. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(result) || !isset(result.cmd) || !isset(result.cmd.id)) { return }
      /* L'identifiant, et non la forme lisible : « #[Salon][Alarme][État]# »
         cesse d'être valable dès qu'on renomme une pièce, et la condition
         deviendrait fausse sans que rien ne le dise. Les ACTIONS, elles,
         gardent le nom lisible : c'est ce qu'attendent displayActionsOption
         et scenarioExpression::createAndExec. La différence est voulue. */
      presenciumRegleModifiee = true
      ligne.setAttribute('data-cmd', String(result.cmd.id))
      presenciumAfficherNomCommande(champ, result.cmd.id)
      if (isset(result.human) && result.human !== '') {
        champ.setAttribute('data-nom', result.human)
      }
      presenciumRegleEditeurLireConditions()
    })
    return
  }

  if (event.target.closest('#bt_presenciumAjouterAction')) {
    presenciumRegleEditeurAjouterAction({ cmd: '', cmd_id: 0, options: {} })
    return
  }

  if (cible = event.target.closest('.presenciumRetirerAction')) {
    cible.closest('.presenciumAction').remove()
    return
  }

  if (cible = event.target.closest('.presenciumListerCmd')) {
    var ligneCmd = cible.closest('.presenciumAction')
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      if (!isset(result) || !isset(result.human) || String(result.human).trim() === '') { return }
      presenciumRegleModifiee = true
      ligneCmd.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      /* L'identifiant est connu ici et nulle part ailleurs : le garder permet
         à health() de dire qu'une action pointe vers une commande morte,
         même si le nom lisible, lui, reste plausible. */
      var champId = ligneCmd.querySelector('.expressionAttr[data-l1key="cmd_id"]')
      if (champId !== null) {
        champId.jeeValue(isset(result.cmd) && isset(result.cmd.id) ? String(result.cmd.id) : '0')
      }
      presenciumRafraichirOptionsActions(false)
      presenciumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('.presenciumListerBloc')) {
    var ligneBloc = cible.closest('.presenciumAction')
    jeedom.getSelectActionModal({}, function (result) {
      if (!isset(result) || !isset(result.human) || String(result.human).trim() === '') { return }
      presenciumRegleModifiee = true
      ligneBloc.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      var champIdBloc = ligneBloc.querySelector('.expressionAttr[data-l1key="cmd_id"]')
      if (champIdBloc !== null) { champIdBloc.jeeValue('0') }
      presenciumRafraichirOptionsActions(false)
      presenciumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('#bt_presenciumTesterRegle')) {
    /* Désactivé : règle absente de la base, ou essai déjà en cours. */
    if (cible.classList.contains('disabled')) { return }
    presenciumTesterRegle(cible)
    return
  }

  if (event.target.closest('#bt_presenciumValiderRegle')) {
    presenciumRegleValider()
    return
  }
}

function presenciumRegleEditeurChangement(event) {
  if (presenciumRegleCourante === null) { return }
  presenciumRegleModifiee = true
  if (event.target.closest('#in_presenciumRegleHeuresActif, #in_presenciumRegleHeureDe, #in_presenciumRegleHeureA')) {
    presenciumRegleEditeurHeures()
    return
  }
  if (event.target.closest('#sel_presenciumRegleDeclencheur')) {
    presenciumRegleEditeurSynchroniser()
    return
  }
  /* La case de la règle ne décide pas seule : l'état effectif se recalcule à
     chaque geste, sinon la fenêtre annoncerait une simulation qu'on vient de
     décocher, ou l'inverse. */
  if (event.target.closest('#in_presenciumRegleSimulation')) {
    presenciumRegleEditeurSimulationEtat()
    return
  }
  if (event.target.closest('#table_presenciumConditions')) {
    presenciumRegleEditeurLireConditions()
  }
}

/*
 * Les options d'une action sont redessinées à la perte du focus du champ de
 * commande. Le rendu DÉTRUIT et reconstruit les champs : le rejouer à chaque
 * focusout effacerait le message que l'utilisateur vient de taper. D'où
 * l'attribut prevalue, qui ne laisse passer qu'un vrai changement.
 */
function presenciumRegleEditeurSortieChamp(event) {
  if (event.target.closest('.presenciumAction .expressionAttr[data-l1key="cmd"]') === null) { return }
  presenciumRafraichirOptionsActions(false)
}

/* La liste des personnes, pour « quelqu'un arrive » / « quelqu'un part ».
   Zéro en tête : c'est le cas le plus fréquent et le plus robuste — il survit
   à la suppression d'une personne. */
function presenciumRemplirSelectPersonne() {
  var select = document.getElementById('sel_presenciumReglePersonne')
  if (select === null) { return }
  var courant = (presenciumRegleCourante !== null)
    ? String(presenciumEntier(presenciumRegleCourante.personne, 0, 0, 99999999))
    : String(select.value || '0')

  select.innerHTML = ''
  var nimporte = document.createElement('option')
  nimporte.value = '0'
  nimporte.textContent = '{{N\'importe qui}}'
  select.appendChild(nimporte)

  /* Seulement les habitants cochés : viser quelqu'un d'autre donne une règle
     qui ne part jamais. La personne enregistrée hors foyer reste proposée,
     marquée, pour ne pas basculer en douce sur « n'importe qui ». */
  for (var i = 0; i < presenciumPersonnes.length; i++) {
    var id = parseInt(presenciumPersonnes[i].id, 10)
    var habitant = (presenciumSelectionPersonnes.indexOf(id) !== -1)
    if (!habitant && String(id) !== courant) { continue }
    var option = document.createElement('option')
    option.value = String(id)
    option.textContent = presenciumPersonnes[i].nom + (habitant ? '' : ' {{(hors foyer)}}')
    select.appendChild(option)
  }
  select.value = courant
  /* La personne enregistrée a pu être supprimée : la liste retomberait
     silencieusement sur « n'importe qui », c'est-à-dire sur une règle qui se
     déclencherait bien plus souvent. Le dire — mais SEULEMENT si la liste est
     réellement arrivée : tant qu'elle ne l'est pas, toute personne paraît
     supprimée, et l'éditeur annonçait la mort d'un équipement bien vivant. */
  if (select.value !== courant) {
    var perdue = document.createElement('option')
    perdue.value = courant
    perdue.textContent = (presenciumPersonnesEtat === 'ok')
      ? ('{{Personne supprimée}} (#' + courant + '#)')
      : ('{{Personne}} #' + courant + '# — {{liste pas encore chargée}}')
    select.appendChild(perdue)
    select.value = courant
  }
}

/* Pose la règle courante dans la fenêtre. */
function presenciumRegleEditeurPoser() {
  var regle = presenciumRegleCourante
  if (regle === null) { return }

  presenciumRemplirSelectDeclencheur()
  presenciumRemplirSelectPersonne()

  presenciumPoserValeur('in_presenciumRegleNom', init(regle.nom, ''))
  presenciumPoserCoche('in_presenciumRegleActif', init(regle.actif, 1) == 1)
  presenciumPoserValeur('sel_presenciumRegleDeclencheur', init(regle.declencheur, 'depart_dernier'))
  presenciumPoserValeur('in_presenciumRegleMinutes', presenciumEntier(regle.minutes, 30, 1, 10080))
  presenciumPoserValeur('in_presenciumRegleAttente', presenciumEntier(regle.attente, 0, 0, 720))
  presenciumPoserValeur('in_presenciumRegleRepos', presenciumEntier(regle.repos, 0, 0, 1440))
  presenciumPoserCoche('in_presenciumRegleSimulation', init(regle.simulation, 0) == 1)

  var conditions = isset(regle.conditions) ? regle.conditions : {}
  var heures = isset(conditions.heures) ? conditions.heures : {}
  presenciumPoserCoche('in_presenciumRegleHeuresActif', init(heures.actif, 0) == 1)
  presenciumPoserValeur('in_presenciumRegleHeureDe', init(heures.de, '00:00'))
  presenciumPoserValeur('in_presenciumRegleHeureA', init(heures.a, '23:59'))

  var jours = (isset(conditions.jours) && Array.isArray(conditions.jours)) ? conditions.jours : [1, 2, 3, 4, 5, 6, 7]
  var cases = document.querySelectorAll('#div_presenciumRegleJours .presenciumJour')
  for (var i = 0; i < cases.length; i++) {
    cases[i].checked = (jours.indexOf(parseInt(cases[i].getAttribute('data-jour'), 10)) !== -1)
  }

  presenciumRegleEditeurRendreConditions()

  var conteneurActions = document.getElementById('div_presenciumActions')
  if (conteneurActions !== null) { conteneurActions.innerHTML = '' }
  var actions = (isset(regle.actions) && Array.isArray(regle.actions)) ? regle.actions : []
  for (var a = 0; a < actions.length; a++) {
    presenciumRegleEditeurAjouterAction(actions[a])
  }
  /* Un seul aller-retour pour toute la liste : la variante unitaire figerait la
     fenêtre une fois par action. */
  presenciumRafraichirOptionsActions(true)

  var test = document.getElementById('div_presenciumRegleTest')
  if (test !== null) { test.innerHTML = '' }

  presenciumRegleEditeurNeuve()
  presenciumRegleEditeurSimulationEtat()
  presenciumRegleEditeurSynchroniser()
  presenciumRegleEditeurHeures()
  presenciumRegleEditeurBoutonTester()
}

/* Les déclencheurs, depuis la table unique envoyée par la page. */
function presenciumRemplirSelectDeclencheur() {
  var select = document.getElementById('sel_presenciumRegleDeclencheur')
  if (select === null) { return }
  select.innerHTML = ''
  for (var i = 0; i < presenciumDeclencheurs.length; i++) {
    var option = document.createElement('option')
    option.value = String(presenciumDeclencheurs[i].cle)
    option.textContent = String(presenciumDeclencheurs[i].texte)
    select.appendChild(option)
  }
}

/* De == À : dire que la plage couvre toute la journée, comme le serveur. */
function presenciumRegleEditeurHeures() {
  var note = document.getElementById('div_presenciumRegleHeuresNote')
  if (note === null) { return }
  var actif = document.getElementById('in_presenciumRegleHeuresActif')
  var de = document.getElementById('in_presenciumRegleHeureDe')
  var a = document.getElementById('in_presenciumRegleHeureA')
  var entiere = (actif !== null && actif.checked && de !== null && a !== null
    && de.value !== '' && presenciumPlageJourneeEntiere(de.value, a.value))
  note.textContent = entiere ? '{{« De » et « À » identiques : la plage couvre toute la journée.}}' : ''
}

/* « Tester » joue la version enregistrée : sans elle, rien à jouer. */
function presenciumRegleEditeurBoutonTester() {
  var bouton = document.getElementById('bt_presenciumTesterRegle')
  if (bouton === null || presenciumRegleCourante === null) { return }
  var enBase = presenciumRegleEnBase(String(init(presenciumRegleCourante.id, '')))
  if (enBase) {
    bouton.removeAttribute('disabled')
    bouton.classList.remove('disabled')
  } else {
    bouton.setAttribute('disabled', 'disabled')
    bouton.classList.add('disabled')
  }
  /* Le title va sur l'enveloppe : un a.btn.disabled ne reçoit plus la souris. */
  var enveloppe = bouton.parentNode
  if (enveloppe !== null && enveloppe.id === 'span_presenciumTesterRegle') {
    enveloppe.setAttribute('title', enBase ? ''
      : '{{Règle pas encore enregistrée : validez-la, sauvegardez l\'équipement, puis rouvrez-la pour la tester.}}')
  }
}

/*
 * La simulation telle qu'elle est À L'ÉCRAN : plugin, foyer, règle — un seul
 * suffit (contrat §6). « Tester » envoie `simuler` d'après ces mêmes cases,
 * et le serveur simule aussi si la version enregistrée l'est.
 */
function presenciumSimulationEcran() {
  /* Le bandeau n'est rendu par le PHP que si la simulation globale est
     active. */
  var caseFoyer = document.getElementById('in_presenciumSimulation')
  var caseRegle = document.getElementById('in_presenciumRegleSimulation')
  return {
    globale: (document.getElementById('div_presenciumBandeauSimulation') !== null),
    foyer: (caseFoyer !== null && caseFoyer.checked),
    regle: (caseRegle !== null && caseRegle.checked)
  }
}

/* Ce qui s'appliquera à cette règle, et à « Tester ». */
function presenciumRegleEditeurSimulationEtat() {
  var zone = document.getElementById('div_presenciumRegleSimulationEtat')
  if (zone === null) { return }
  zone.innerHTML = ''

  var ecran = presenciumSimulationEcran()
  var globale = ecran.globale
  var foyer = ecran.foyer
  var regle = ecran.regle

  if (!globale && !foyer && !regle) {
    zone.appendChild(presenciumText('div', 'alert alert-info',
      '{{Rien ne simule cette règle : ses actions seront réellement exécutées. « Tester » les exécutera pour de vrai — armement compris — après confirmation.}}'))
    return
  }

  var raisons = []
  if (globale) { raisons.push('{{le plugin entier (« Tout simuler »)}}') }
  if (foyer) { raisons.push('{{ce foyer}}') }
  if (regle) { raisons.push('{{cette règle}}') }
  zone.appendChild(presenciumText('div', 'alert alert-warning',
    '{{Cette règle est en simulation : elle sera évaluée et journalisée, mais aucune action ne sera envoyée — « Tester » compris. Simulation imposée par :}} ' + raisons.join(', ')))
  if (!regle) {
    /* Le cas qui trompe : la case ci-dessous est décochée et pourtant rien ne
       s'exécutera. Dire où lever la simulation, sinon on décoche en boucle une
       case qui n'y peut rien. */
    zone.appendChild(presenciumText('div', 'help-block',
      '{{La case « Journaliser sans exécuter » de cette règle est décochée : c\'est ailleurs qu\'il faut lever la simulation — dans la configuration du plugin, ou dans l\'onglet « Équipement » du foyer.}}'))
  }
}

/*
 * Une règle neuve naît en simulation (presenciumRegleVierge).
 *
 * C'est la bonne décision — on ne laisse pas une règle jamais relue armer une
 * alarme — mais une décision muette ressemble à une panne : on croit avoir
 * créé une règle qui ne fait rien. On l'annonce, et on dit comment en sortir.
 */
function presenciumRegleEditeurNeuve() {
  var zone = document.getElementById('div_presenciumRegleNeuve')
  if (zone === null) { return }
  zone.innerHTML = ''
  if (presenciumRegleIndex >= 0) { return }
  zone.appendChild(presenciumText('div', 'alert alert-info',
    '{{Nouvelle règle : elle naît en simulation. Elle sera évaluée et écrite au journal, mais n\'exécutera rien tant que « Journaliser sans exécuter » (section Réglages, en bas de cette fenêtre) restera coché. Laissez-la tourner quelques jours, relisez le journal, puis décochez cette case pour qu\'elle agisse.}}'))
}

function presenciumPoserValeur(_id, _valeur) {
  var champ = document.getElementById(_id)
  if (champ === null) { return }
  champ.value = String(_valeur)
}

function presenciumPoserCoche(_id, _coche) {
  var champ = document.getElementById(_id)
  if (champ === null) { return }
  champ.checked = (_coche === true)
}

/* Montre les champs qui servent au déclencheur choisi. « 30 minutes » n'a de
   sens que pour vide_depuis / occupee_depuis, et « qui ? » que pour une
   arrivée ou un départ nominatifs. */
function presenciumRegleEditeurSynchroniser() {
  var select = document.getElementById('sel_presenciumRegleDeclencheur')
  var cle = (select === null) ? '' : String(select.value)

  var blocMinutes = document.getElementById('div_presenciumRegleMinutes')
  if (blocMinutes !== null) {
    blocMinutes.style.display = (cle === 'vide_depuis' || cle === 'occupee_depuis') ? '' : 'none'
  }
  var selectPersonne = document.getElementById('sel_presenciumReglePersonne')
  if (selectPersonne !== null) {
    var bloc = selectPersonne.closest('.form-group') || selectPersonne
    bloc.style.display = (cle === 'arrivee' || cle === 'depart') ? '' : 'none'
  }
}

/* --------------------------------------------------------- CONDITIONS */

/* Le tableau des conditions : Commande, Opérateur, Valeur, bouton. */
function presenciumRegleEditeurRendreConditions() {
  var table = document.getElementById('table_presenciumConditions')
  if (table === null || presenciumRegleCourante === null) { return }
  var tbody = table.querySelector('tbody')
  if (tbody === null) { return }
  tbody.innerHTML = ''

  var lignes = presenciumRegleCourante.conditions.lignes
  for (var i = 0; i < lignes.length; i++) {
    var condition = lignes[i]
    var tr = document.createElement('tr')
    tr.setAttribute('data-index', String(i))
    tr.setAttribute('data-cmd', String(presenciumEntier(condition.cmd, 0, 0, 99999999)))

    var cellNom = document.createElement('td')
    cellNom.innerHTML = '<div class="input-group">'
      + '<input class="form-control input-sm roundedLeft presenciumConditionNom" readonly placeholder="{{Aucune commande choisie}}">'
      + '<span class="input-group-btn">'
      + '<a class="btn btn-default btn-sm presenciumChoisirCondition roundedRight" title="{{Choisir la commande à surveiller}}"><i class="fas fa-search"></i></a>'
      + '</span></div>'
    tr.appendChild(cellNom)

    var cellOperateur = document.createElement('td')
    var selectOperateur = document.createElement('select')
    selectOperateur.className = 'form-control input-sm presenciumConditionOperateur'
    for (var o = 0; o < presenciumOperateurs.length; o++) {
      var option = document.createElement('option')
      option.value = presenciumOperateurs[o]
      option.textContent = presenciumOperateurs[o]
      if (String(init(condition.operateur, '==')) === presenciumOperateurs[o]) { option.selected = true }
      selectOperateur.appendChild(option)
    }
    cellOperateur.appendChild(selectOperateur)
    tr.appendChild(cellOperateur)

    var cellValeur = document.createElement('td')
    var inputValeur = document.createElement('input')
    inputValeur.className = 'form-control input-sm presenciumConditionValeur'
    inputValeur.value = String(init(condition.valeur, ''))
    cellValeur.appendChild(inputValeur)
    tr.appendChild(cellValeur)

    var cellBouton = document.createElement('td')
    cellBouton.style.textAlign = 'right'
    cellBouton.innerHTML = '<a class="btn btn-xs btn-danger presenciumRetirerCondition" title="{{Retirer cette condition}}"><i class="fas fa-minus-circle"></i></a>'
    tr.appendChild(cellBouton)

    tbody.appendChild(tr)

    var champNom = tr.querySelector('.presenciumConditionNom')
    /* Le nom enregistré n'est qu'un libellé de repli : on redemande le nom
       courant au coeur, pour qu'un renommage se voie ici sans casser la règle,
       et pour qu'une commande supprimée se dénonce. */
    champNom.setAttribute('data-nom', String(init(condition.nom, '')))
    presenciumAfficherNomCommande(champNom, condition.cmd)
  }
}

/* Relève les conditions de l'écran dans la règle courante. Appelée avant tout
   geste qui redessine le tableau : sans cela, ajouter une condition effacerait
   la valeur tout juste tapée dans la précédente. */
function presenciumRegleEditeurLireConditions() {
  if (presenciumRegleCourante === null) { return }
  var table = document.getElementById('table_presenciumConditions')
  if (table === null) { return }
  var tbody = table.querySelector('tbody')
  if (tbody === null) { return }

  var lignes = []
  var rangs = tbody.querySelectorAll('tr')
  for (var i = 0; i < rangs.length; i++) {
    var champNom = rangs[i].querySelector('.presenciumConditionNom')
    var champOperateur = rangs[i].querySelector('.presenciumConditionOperateur')
    var champValeur = rangs[i].querySelector('.presenciumConditionValeur')
    if (champNom === null || champOperateur === null || champValeur === null) { continue }
    lignes.push({
      cmd: presenciumEntier(rangs[i].getAttribute('data-cmd'), 0, 0, 99999999),
      operateur: String(champOperateur.value),
      valeur: String(champValeur.value),
      /* Le libellé et non ce qui est affiché : « #42# — Commande introuvable »
         n'a rien à faire dans la configuration. */
      nom: String(champNom.getAttribute('data-nom') || '')
    })
  }
  presenciumRegleCourante.conditions.lignes = lignes
}

/* ------------------------------------------------------------ ACTIONS */

/*
 * Une ligne d'action, calquée sur le sélecteur d'action des scénarios.
 *
 * L'ordre html() → setJeeValues → appendChild est celui du coeur : le HTML des
 * options contient des <script> que seul Element.prototype.html() exécute —
 * innerHTML les poserait inertes, et les curseurs et listes des blocs du coeur
 * resteraient morts.
 */
function presenciumRegleEditeurAjouterAction(_action) {
  var conteneur = document.getElementById('div_presenciumActions')
  if (conteneur === null) { return null }
  var action = _action || {}
  if (!isset(action.options)) { action.options = {} }

  var idOptions = jeedomUtils.uniqId()
  var div = '<div class="presenciumAction expression" style="margin-bottom:6px;">'
  div += '<input class="expressionAttr" data-l1key="cmd_id" style="display:none;">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<div class="col-sm-5">'
  div += '<div class="input-group">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm presenciumRetirerAction roundedLeft" title="{{Retirer cette action}}"><i class="fas fa-minus-circle"></i></a>'
  div += '</span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd" placeholder="{{Commande ou bloc à exécuter}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm presenciumListerBloc" title="{{Choisir un bloc (message, scénario, variable…)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm presenciumListerCmd roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="col-sm-7 actionOptions" id="' + idOptions + '"></div>'
  div += '</div>'
  div += '</div>'

  var enveloppe = document.createElement('div')
  enveloppe.html(div)
  enveloppe.setJeeValues(action, '.expressionAttr')
  conteneur.appendChild(enveloppe)
  var noeuds = Array.prototype.slice.call(enveloppe.childNodes)
  enveloppe.replaceWith(...noeuds)

  if (noeuds.length === 0) { return null }
  /* Les options connues restent sur la ligne tant qu'un rendu ne les a pas
     remplacées : sans elles, un enregistrement fait avant que le coeur ait
     répondu écrirait du vide à la place d'un message rédigé. */
  noeuds[0].presenciumOptionsEnAttente = action.options
  return noeuds[0]
}

/*
 * Redessine les options de toutes les actions, en un seul aller-retour.
 *
 * Ce que rend réellement la variante batch, vérifié dans le coeur
 * (core/ajax/scenario.ajax.php, action 'actionToHtml') : le contrôleur écarte
 * en silence — `continue` — toute ligne dont le rendu est vide. Le seul retour
 * dégradé qui arrive ici est donc l'ABSENCE de la ligne dans la réponse.
 * 'Unsupported' n'est rendu que par la variante unitaire, côté client
 * (core/js/cmd.class.js) : il ne peut pas arriver ici.
 *
 * Dans tous les cas on CONSERVE les options existantes : enregistrer du vide à
 * la place d'un message que l'utilisateur avait rédigé est une perte muette,
 * et c'est exactement ce qu'il croirait avoir fait lui-même.
 */
function presenciumRafraichirOptionsActions(_force) {
  var lignes = document.querySelectorAll('#div_presenciumActions .presenciumAction')
  var params = []
  var attendus = {}

  for (var i = 0; i < lignes.length; i++) {
    var ligne = lignes[i]
    var champ = ligne.querySelector('.expressionAttr[data-l1key="cmd"]')
    var cible = ligne.querySelector('.actionOptions')
    if (champ === null || cible === null) { continue }

    var expression = String(champ.value || '')
    /* Le rendu détruit et reconstruit les champs : ne redessiner que si la
       commande visée a réellement changé, sinon le focusout effacerait le texte
       en cours de frappe. */
    if (_force !== true && champ.getAttribute('prevalue') === expression) { continue }
    champ.setAttribute('prevalue', expression)

    /* Ce que la ligne sait de ses options avant que le coeur ne réponde. */
    var options = ligne.getJeeValues('.expressionAttr')[0]
    options = (isset(options) && isset(options.options)) ? options.options : {}
    var enAttente = ligne.presenciumOptionsEnAttente
    if (isset(enAttente) && enAttente !== null) { options = Object.assign({}, enAttente, options) }
    ligne.presenciumOptionsEnAttente = options

    if (expression === '') {
      /* Pas de commande : pas d'options à montrer, et rien à conserver. */
      cible.innerHTML = ''
      ligne.presenciumOptionsEnAttente = null
      continue
    }
    attendus[cible.id] = true
    params.push({ expression: expression, options: options, id: cible.id })
  }

  if (params.length === 0) { return }

  jeedom.cmd.displayActionsOption({
    params: params,
    error: function (error) {
      /* La requête a échoué : les options connues restent sur les lignes, on ne
         touche à rien d'autre que le message. */
      jeedomUtils.showAlert({
        message: '{{Les options des actions n\'ont pas pu être affichées. Ce qui est enregistré est conservé.}} ' + ((error && error.message) ? error.message : ''),
        level: 'warning'
      })
    },
    success: function (data) {
      var liste = Array.isArray(data) ? data : []
      for (var i = 0; i < liste.length; i++) {
        var cible = document.getElementById(liste[i].id)
        if (cible === null) { continue }

        var html = liste[i].html
        if (isset(html) && html !== null && typeof html === 'object' && isset(html.html)) { html = html.html }
        html = String(isset(html) && html !== null ? html : '')

        var ligne = cible.closest('.presenciumAction')
        /* Rendu vide : traité plus bas comme une ligne non rendue. */
        if (html === '') { continue }
        delete attendus[liste[i].id]
        /* html() et jamais innerHTML : le rendu du coeur contient des <script>. */
        cible.html(html)
        if (ligne !== null) { ligne.presenciumOptionsEnAttente = null }
      }

      /* Ce que le coeur n'a pas rendu du tout : même traitement, mêmes options
         conservées. */
      for (var id in attendus) {
        var oublie = document.getElementById(id)
        if (oublie === null) { continue }
        oublie.textContent = '{{Cette action n\'a pas d\'options, ou la commande visée a disparu. Ce qui est enregistré est conservé.}}'
      }
      jeedomUtils.taAutosize()
    }
  })
}

/* Relève les actions de l'écran. */
function presenciumRegleEditeurLireActions() {
  var actions = []
  var lignes = document.querySelectorAll('#div_presenciumActions .presenciumAction')
  for (var i = 0; i < lignes.length; i++) {
    var action = lignes[i].getJeeValues('.expressionAttr')[0]
    if (!isset(action) || !isset(action.cmd) || String(action.cmd).trim() === '') { continue }
    if (!isset(action.options) || action.options === null) { action.options = {} }
    /* Les champs du coeur ne sont pas à l'écran (bloc non supporté, commande
       disparue, rendu jamais arrivé) : on repose les options connues sous
       celles, bien présentes, que la ligne porte. */
    var enAttente = lignes[i].presenciumOptionsEnAttente
    if (isset(enAttente) && enAttente !== null) {
      action.options = Object.assign({}, enAttente, action.options)
    }
    actions.push({
      /* Le nom lisible, et non l'identifiant : c'est cette forme qu'attendent
         displayActionsOption et scenarioExpression::createAndExec. Les
         CONDITIONS, elles, gardent l'identifiant numérique, qui survit à un
         renommage. La différence est voulue. */
      cmd: String(action.cmd).trim(),
      cmd_id: presenciumEntier(action.cmd_id, 0, 0, 99999999),
      options: action.options
    })
  }
  return actions
}

/* ------------------------------------------------------- VALIDER / TESTER */

/* Relève toute la fenêtre dans la règle courante. */
function presenciumRegleEditeurLire() {
  if (presenciumRegleCourante === null) { return null }
  var regle = presenciumRegleCourante

  var nom = document.getElementById('in_presenciumRegleNom')
  regle.nom = (nom === null) ? '' : String(nom.value).trim()
  var actif = document.getElementById('in_presenciumRegleActif')
  regle.actif = (actif !== null && actif.checked) ? 1 : 0
  var declencheur = document.getElementById('sel_presenciumRegleDeclencheur')
  if (declencheur !== null) { regle.declencheur = String(declencheur.value) }
  var personne = document.getElementById('sel_presenciumReglePersonne')
  regle.personne = (personne === null) ? 0 : presenciumEntier(personne.value, 0, 0, 99999999)
  if (regle.declencheur !== 'arrivee' && regle.declencheur !== 'depart') { regle.personne = 0 }

  /* Bornes alignées sur celles du serveur (presenciumRegles::MINUTES_MAX) et
     sur celles du champ : de 1 à 10080 minutes, soit une semaine. Le plancher
     à zéro acceptait « la maison est vide depuis 0 min », c'est-à-dire une
     règle qui part en permanence, sans un mot. La saisie vide, elle, est
     refusée avant d'arriver ici, dans presenciumRegleValider. */
  var minutes = document.getElementById('in_presenciumRegleMinutes')
  regle.minutes = (minutes === null) ? 30 : presenciumEntier(minutes.value, 30, 1, 10080)
  var attente = document.getElementById('in_presenciumRegleAttente')
  regle.attente = (attente === null) ? 0 : presenciumEntier(attente.value, 0, 0, 720)
  var repos = document.getElementById('in_presenciumRegleRepos')
  regle.repos = (repos === null) ? 0 : presenciumEntier(repos.value, 0, 0, 1440)
  var simulation = document.getElementById('in_presenciumRegleSimulation')
  regle.simulation = (simulation !== null && simulation.checked) ? 1 : 0

  if (!isset(regle.conditions) || regle.conditions === null) { regle.conditions = {} }
  var heuresActif = document.getElementById('in_presenciumRegleHeuresActif')
  var heureDe = document.getElementById('in_presenciumRegleHeureDe')
  var heureA = document.getElementById('in_presenciumRegleHeureA')
  regle.conditions.heures = {
    actif: (heuresActif !== null && heuresActif.checked) ? 1 : 0,
    de: (heureDe === null || heureDe.value === '') ? '00:00' : String(heureDe.value),
    a: (heureA === null || heureA.value === '') ? '23:59' : String(heureA.value)
  }

  var jours = []
  var cases = document.querySelectorAll('#div_presenciumRegleJours .presenciumJour')
  for (var i = 0; i < cases.length; i++) {
    if (cases[i].checked) { jours.push(parseInt(cases[i].getAttribute('data-jour'), 10)) }
  }
  /* Aucun jour coché, c'est une règle qui ne se déclenchera jamais et dont
     personne ne comprendrait le silence : on la lit comme « tous les jours »,
     ce que le serveur fait aussi. */
  regle.conditions.jours = (jours.length === 0) ? [1, 2, 3, 4, 5, 6, 7] : jours

  presenciumRegleEditeurLireConditions()
  regle.actions = presenciumRegleEditeurLireActions()
  return regle
}

function presenciumRegleValider() {
  var regle = presenciumRegleEditeurLire()
  if (regle === null) { return }

  if (regle.nom === '') {
    jeedomUtils.showAlert({ message: '{{Donnez un nom à cette règle : c\'est lui qui apparaît dans le journal.}}', level: 'warning' })
    return
  }

  /* Une durée vide ou hors bornes n'est pas corrigée en douce : remplacer par
     une valeur inventée la durée qu'on croyait avoir tapée ferait partir la
     règle à un moment qu'on n'a pas choisi — et zéro la fait partir tout le
     temps. On refuse, on le dit, et on rend la main sur le champ. */
  if (regle.declencheur === 'vide_depuis' || regle.declencheur === 'occupee_depuis') {
    var champMinutes = document.getElementById('in_presenciumRegleMinutes')
    var saisie = (champMinutes === null) ? '' : String(champMinutes.value).trim()
    var duree = parseInt(saisie, 10)
    if (saisie === '' || isNaN(duree) || duree < 1 || duree > 10080) {
      jeedomUtils.showAlert({
        message: '{{Indiquez une durée de 1 à 10080 minutes (une semaine) pour ce déclencheur : une durée vide ou nulle ferait partir la règle en permanence.}}',
        level: 'warning'
      })
      if (champMinutes !== null) { champMinutes.focus() }
      return
    }
    regle.minutes = duree
  }

  /* Une condition sans commande est fausse à chaque évaluation : la règle ne
     partirait jamais. */
  var lignesConditions = document.querySelectorAll('#table_presenciumConditions tbody tr')
  for (var c = 0; c < lignesConditions.length; c++) {
    if (presenciumEntier(lignesConditions[c].getAttribute('data-cmd'), 0, 0, 99999999) > 0) { continue }
    jeedomUtils.showAlert({
      message: '{{Une condition n\'a pas de commande : choisissez-la avec la loupe, ou retirez la ligne.}}',
      level: 'warning'
    })
    var loupe = lignesConditions[c].querySelector('.presenciumChoisirCondition')
    if (loupe !== null) { loupe.focus() }
    return
  }

  /* Une ligne d'action vide disparaissait en silence à la relecture. */
  var champsActions = document.querySelectorAll('#div_presenciumActions .presenciumAction .expressionAttr[data-l1key="cmd"]')
  for (var a = 0; a < champsActions.length; a++) {
    if (String(champsActions[a].value || '').trim() !== '') { continue }
    jeedomUtils.showAlert({
      message: '{{Une action n\'a pas de commande : choisissez-la, ou retirez la ligne.}}',
      level: 'warning'
    })
    champsActions[a].focus()
    return
  }

  if ((regle.declencheur === 'arrivee' || regle.declencheur === 'depart') && regle.personne > 0
      && presenciumSelectionPersonnes.indexOf(regle.personne) === -1) {
    jeedomUtils.showAlert({
      message: '{{La personne visée n\'est pas cochée dans ce foyer : tant qu\'elle n\'y habite pas, cette règle ne se déclenchera pas.}}',
      level: 'warning', timeOut: 8000
    })
  }

  if (regle.actions.length === 0) {
    jeedomUtils.showAlert({
      message: '{{Cette règle n\'a aucune action : elle sera évaluée et journalisée, mais ne fera jamais rien.}}',
      level: 'warning', timeOut: 8000
    })
  }

  if (presenciumRegleIndex >= 0 && isset(presenciumRegles[presenciumRegleIndex])) {
    presenciumRegles[presenciumRegleIndex] = regle
  } else {
    presenciumRegles.push(regle)
  }
  presenciumRenderRegles()
  presenciumMarkModified()
  presenciumRegleModifiee = false
  presenciumFermerModale()
  jeedomUtils.showAlert({ message: '{{Règle enregistrée dans le foyer. Pensez à sauvegarder l\'équipement.}}', level: 'success' })
}

/* Cette règle existe-t-elle dans la configuration ENREGISTRÉE du foyer ? Les
   identifiants ont été relevés à l'ouverture de l'équipement : la question se
   tranche sans rien demander au serveur. */
function presenciumRegleEnBase(_id) {
  return (presenciumReglesEnregistrees.indexOf(String(_id)) !== -1)
}

/*
 * Le bouton « Tester ».
 *
 * Le test joue la règle TELLE QU'ELLE EST EN BASE : une modification en cours
 * de saisie ne serait pas celle qui est exécutée, et croire le contraire est
 * la meilleure façon de déclarer bon un réglage qu'on n'a jamais essayé.
 */
function presenciumTesterRegle(_bouton) {
  var id = presenciumCurrentId()
  if (id === null) { return }
  var sortie = document.getElementById('div_presenciumRegleTest')
  var dire = function (_classe, _texte) {
    if (sortie === null) { return }
    sortie.innerHTML = ''
    sortie.appendChild(presenciumText('div', _classe, _texte))
  }

  /*
   * L'IDENTIFIANT de la règle, jamais son rang.
   *
   * Le rang est celui du tableau à l'écran : monter, descendre ou supprimer
   * une règle sans enregistrer le décale de la liste du serveur, qui jouerait
   * — réellement — une autre règle que celle affichée. Le serveur retrouve la
   * règle par son identifiant, et répond une erreur explicite s'il ne la
   * connaît pas.
   */
  var identifiant = (presenciumRegleCourante !== null) ? String(init(presenciumRegleCourante.id, '')) : ''
  if (identifiant === '') {
    dire('alert alert-warning',
      '{{Cette règle n\'a pas encore d\'identifiant : validez-la, sauvegardez le foyer, puis rouvrez-la pour la tester.}}')
    return
  }

  /* Une règle neuve, ou validée mais jamais sauvegardée, n'existe pas côté
     serveur : le dire tout de suite plutôt que de lancer un test qui ne peut
     rien jouer. La liste des identifiants a été relevée à l'ouverture de
     l'équipement, elle dit exactement ce qui est en base. */
  if (!presenciumRegleEnBase(identifiant)) {
    dire('alert alert-warning',
      '{{Cette règle n\'est pas encore enregistrée dans le foyer : validez-la, sauvegardez l\'équipement, puis rouvrez-la pour la tester. « Tester » joue toujours la version enregistrée, jamais la saisie en cours.}}')
    return
  }

  /* Une case cochée à l'écran suffit à simuler l'essai, même si la version
     enregistrée ne l'est pas : le serveur force alors la simulation. */
  var ecran = presenciumSimulationEcran()
  var simuler = (ecran.globale || ecran.foyer || ecran.regle)
  if (simuler) {
    presenciumLancerTest(_bouton, id, identifiant, true, dire)
    return
  }
  presenciumConfirmer('{{Ceci exécutera réellement les actions de la règle enregistrée — armement de l\'alarme compris. Continuer ?}}', function () {
    presenciumLancerTest(_bouton, id, identifiant, false, dire)
  })
}

function presenciumLancerTest(_bouton, _id, _regle, _simuler, _dire) {
  var sortie = document.getElementById('div_presenciumRegleTest')
  _dire('text-muted', '{{Test en cours…}}')

  var donnees = { id: _id, regle: _regle }
  if (_simuler) { donnees.simuler = 1 }
  presenciumAjax('testerRegle', donnees, function (result) {
    if (sortie === null) { return }
    sortie.innerHTML = ''
    sortie.appendChild(presenciumText('div', 'text-muted',
      '{{La règle vient d\'être jouée telle qu\'elle est enregistrée, conditions comprises.}}'))
    sortie.appendChild(presenciumEntreeJournal(result))
  }, {
    button: _bouton,
    /* Le message du serveur tel quel : c'est lui qui sait que l'identifiant
       est inconnu et qu'il faut d'abord enregistrer. Le réécrire ici
       masquerait la seule information utile. */
    failure: function (message) {
      _dire('alert alert-danger', message)
    }
  })
}
