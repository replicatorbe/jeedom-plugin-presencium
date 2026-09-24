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

/* ============================================================== VARIABLES */

/* Les règles du foyer ouvert. C'est la source de vérité de l'onglet Règles :
   le tableau en découle, la modale les modifie, et saveEqLogic les recopie
   dans la configuration. Le DOM du tableau n'est qu'un affichage : une règle
   n'y est jamais relue pour décider. */
var presenciumRegles = []

/* Vrai pendant que printEqLogic repose les valeurs à l'écran. Reposer une
   valeur dans un champ émet « change » exactement comme une saisie : sans ce
   drapeau, ouvrir un équipement suffirait à le déclarer modifié, et
   l'avertissement « quitter sans enregistrer ? » tomberait sans que rien
   n'ait été touché. */
var presenciumRendering = false

/* Les personnes de l'installation, telles que le serveur les a rendues.
   Gardées ici pour que la liste du foyer et la liste déroulante de la modale
   se dessinent sans redemander à chaque ouverture. */
var presenciumPersonnes = []

/* Les commandes de présence découvertes, pour le choix rapide d'une source. */
var presenciumSources = []

/*
 * Où en est le chargement de la liste des personnes.
 *
 * Un tableau vide ne dit pas tout : « pas encore demandé », « demandé mais
 * jamais arrivé » et « le serveur a répondu, il n'y a réellement personne » se
 * ressemblent à l'écran, et seul le troisième autorise à écrire « aucune
 * personne n'est déclarée » ou « personne supprimée ». Les deux autres l'ont
 * fait sur une installation parfaitement peuplée, le temps d'un aller-retour.
 */
var presenciumPersonnesEtat = 'vierge'

/* Le message du dernier échec de chargement des personnes, pour le montrer à
   l'endroit où la liste aurait dû s'afficher plutôt que dans une bulle qui
   disparaît. */
var presenciumPersonnesErreur = ''

/* Les identifiants des règles telles qu'elles sont EN BASE, relevés à
   l'ouverture de l'équipement. « Tester » joue la règle enregistrée : sans
   cette liste, une règle tout juste validée mais jamais sauvegardée partirait
   au test et le serveur jouerait autre chose — ou rien. */
var presenciumReglesEnregistrees = []

/* L'équipement pour lequel printEqLogic a réellement chargé les règles.
   Le coeur remet printEqLogic à undefined avant de recharger une page
   (core/js/../utils.js, loadPage) et ne le rappelle qu'une fois le script du
   plugin réexécuté ET l'équipement relu. Si l'un des deux manque, le tableau
   des règles s'afficherait vide — indiscernable d'un foyer qui n'en a pas,
   alors que les règles sont bien en base. On garde donc trace de l'équipement
   chargé, et le tableau refuse de se prononcer pour un autre. */
var presenciumChargePour = null

/* Le numéro de la dernière lecture du journal demandée. Changer deux fois de
   filtre lance deux requêtes et rien ne garantit l'ordre des réponses : sans
   ce jeton, la plus lente écrase la plus récente et le journal affiché ne
   correspond plus au filtre affiché. */
var presenciumJournalJeton = 0

/*
 * Les personnes cochées dans le foyer ouvert.
 *
 * Elles vivent ici et non dans les cases à cocher : la liste est dessinée
 * APRÈS une réponse AJAX, et enregistrer avant qu'elle n'arrive relèverait un
 * conteneur vide — le foyer perdrait toutes ses personnes sans un mot.
 */
var presenciumSelectionPersonnes = []

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

/* Les règles telles que chargées, en JSON : ce qui en diffère n'est pas
   enregistré. */
var presenciumReglesChargees = '[]'

/* Vrai dès qu'un geste touche la règle ouverte dans la modale. */
var presenciumRegleModifiee = false

/* Le rafraîchissement périodique du verdict. Repris d'un chargement du script
   à l'autre pour ne jamais laisser tourner un minuteur orphelin. */
var presenciumMinuteurVerdict = (typeof presenciumMinuteurVerdict !== 'undefined') ? presenciumMinuteurVerdict : null

/* 'ok', ou 'echec' quand la découverte des sources n'a pas répondu. */
var presenciumSourcesEtat = 'ok'

/* Mêmes valeurs que presenciumRegles::OPERATEURS. */
var presenciumOperateurs = ['==', '!=', '>', '>=', '<', '<=']

/* 1 = lundi … 7 = dimanche, comme dans le schéma d'une règle. */
var presenciumJours = [
  { jour: 1, texte: '{{Lun}}' }, { jour: 2, texte: '{{Mar}}' },
  { jour: 3, texte: '{{Mer}}' }, { jour: 4, texte: '{{Jeu}}' },
  { jour: 5, texte: '{{Ven}}' }, { jour: 6, texte: '{{Sam}}' },
  { jour: 7, texte: '{{Dim}}' }
]

/*
 * Les verdicts du journal, avec leur couleur.
 *
 * Le journal est l'outil de détection des faux positifs : il n'est utile que
 * s'il se lit d'un coup d'oeil. « rebond_absorbe » est le verdict qui compte —
 * c'est lui qui dit que le délai de départ vient d'éviter une alarme sur
 * quelqu'un resté dans son salon. Il est donc traité à part, en avertissement,
 * et jamais en gris avec le reste.
 */
var presenciumVerdicts = {
  declenchee: { classe: 'success', texte: '{{Déclenchée}}' },
  conditions_non_remplies: { classe: 'default', texte: '{{Conditions non remplies}}' },
  hors_horaire: { classe: 'default', texte: '{{Hors horaire}}' },
  en_attente: { classe: 'info', texte: '{{En attente}}' },
  attente_annulee: { classe: 'info', texte: '{{Attente annulée}}' },
  repos: { classe: 'default', texte: '{{Anti-répétition}}' },
  desactivee: { classe: 'default', texte: '{{Désactivée}}' },
  echec: { classe: 'danger', texte: '{{Échec}}' },
  rebond_absorbe: { classe: 'warning', texte: '{{Rebond absorbé}}' },
  /* Le pendant malheureux du rebond absorbé : le signal est revenu APRÈS la fin
     du délai, le départ a donc été confirmé et les règles jouées pour rien.
     En danger, et non en avertissement : le rebond absorbé raconte ce que le
     plugin a évité, celui-ci ce qu'il a laissé passer. */
  faux_depart: { classe: 'danger', texte: '{{Faux départ probable}}' },
  forcage: { classe: 'warning', texte: '{{Forçage}}' },
  arrivee: { classe: 'success', texte: '{{Arrivée}}' },
  depart: { classe: 'info', texte: '{{Départ}}' },
  essai: { classe: 'info', texte: '{{Essai manuel}}' }
}

/* La couleur du liseré d'une entrée, par classe Bootstrap. Une entrée se
   repère à son bord avant d'être lue. */
var presenciumCouleurs = {
  success: '#5cb85c',
  danger: '#d9534f',
  warning: '#f0ad4e',
  info: '#5bc0de',
  default: '#bbbbbb'
}

/* ================================================================== OUTILS */

/* Abrège une réponse brute avant de la montrer : une erreur PHP rend une page
   de HTML, et ce qui explique la panne tient dans sa première ligne. */
function presenciumRaccourci(_texte) {
  var texte = String((_texte === null || typeof _texte === 'undefined') ? '' : _texte)
  texte = texte.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
  if (texte === '') { return '{{réponse vide}}' }
  return (texte.length > 300) ? (texte.substring(0, 300) + '…') : texte
}

/* Le texte d'une erreur rendue en HTML par displayException() : le message
   seul, sans balises ni trace. */
function presenciumTexteErreur(_html) {
  var brut = String((_html === null || typeof _html === 'undefined') ? '' : _html)
  var texte = brut
  if (typeof DOMParser !== 'undefined') {
    var doc = new DOMParser().parseFromString(brut, 'text/html')
    var message = doc.getElementById('span_errorMessage')
    texte = (message !== null) ? message.textContent : (doc.body ? doc.body.textContent : brut)
  } else {
    texte = brut.replace(/<[^>]*>/g, ' ')
  }
  texte = String(texte || '').replace(/\s+/g, ' ').trim()
  return (texte === '') ? '{{Le plugin a répondu une erreur sans message.}}' : texte
}

/*
 * Le message d'un échec, quelle que soit la forme sous laquelle il arrive.
 *
 * Les rappels d'erreur du coeur sont appelés tantôt avec UN seul argument —
 * l'objet de la panne, core/dom/dom.utils.js : `_params.onError(error)` —
 * tantôt avec trois à la façon de jQuery (requête, statut, message). Déclarer
 * trois paramètres et lire le troisième perd le message du serveur dans le
 * premier cas, et c'est exactement ce qui se passait ici : l'échec s'affichait
 * « undefined » ou pas du tout. On retient donc le premier des trois qui dit
 * quelque chose.
 */
function presenciumMessageErreur(_a, _b, _c) {
  var candidats = [_c, _b, _a]
  for (var i = 0; i < candidats.length; i++) {
    var candidat = candidats[i]
    if (!isset(candidat) || candidat === null) { continue }
    if (typeof candidat === 'string') {
      if (candidat.trim() !== '') { return candidat }
      continue
    }
    if (isset(candidat.message) && String(candidat.message).trim() !== '') { return String(candidat.message) }
    if (isset(candidat.statusText) && String(candidat.statusText).trim() !== '') { return String(candidat.statusText) }
  }
  return '{{Jeedom n\'a pas répondu : requête perdue, session expirée ou serveur injoignable.}}'
}

/*
 * Requête AJAX vers le contrôleur du plugin.
 *
 * _options : { button: <élément à désactiver pendant l'appel>,
 *              failure: <fonction recevant le message d'erreur>,
 *              silent: true pour ne rien afficher,
 *              delai: ms avant abandon, 60000 par défaut }
 *
 * Le transport est un fetch et non domUtils.ajax, et c'est la correction d'une
 * panne muette vérifiée dans le coeur (core/dom/dom.utils.js, ~600-650) : sur
 * une réponse HTTP en échec, domUtils.ajax lève la réponse, la rattrape, écrit
 * un console.warn — et n'appelle NI le `error:` du plugin, NI handleAjaxError.
 * Un 500 est même rejoué trois fois avant ce silence. Le plugin restait donc
 * sur « Test en cours… » ou « Lecture du journal… », bouton désactivé, jusqu'au
 * filet de 60 s, sans jamais dire que le serveur avait renvoyé une erreur.
 * Ici, TOUT chemin d'échec — HTTP, réseau, JSON illisible, état non « ok » —
 * passe par une seule sortie qui rend le bouton et affiche quelque chose.
 */
function presenciumAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var termine = false
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
  }

  /* Filet : passé le délai, la requête est ABANDONNÉE, puis le bouton rendu.
     Le rendre pendant qu'elle court permettait de la relancer : deux
     « Tester », deux exécutions. */
  var controleur = (typeof AbortController !== 'undefined') ? new AbortController() : null
  var expiree = false
  var messageExpiree = '{{Pas de réponse de Jeedom dans le délai : la requête a été abandonnée, mais le serveur a pu la traiter quand même.}}'
  var minuteur = setTimeout(function () {
    if (termine) { return }
    expiree = true
    if (controleur !== null) {
      controleur.abort()
    } else {
      echec(messageExpiree)
    }
  }, isset(options.delai) ? options.delai : 60000)
  var signalPage = (typeof domUtils !== 'undefined' && isset(domUtils.controller)) ? domUtils.controller.signal : undefined
  var relaisAbandon = function () { if (controleur !== null) { controleur.abort() } }
  var finir = function () {
    termine = true
    clearTimeout(minuteur)
    if (signalPage) { signalPage.removeEventListener('abort', relaisAbandon) }
    release()
  }

  var echec = function (_message) {
    if (termine) { return }
    finir()
    var message = String(_message || '')
    if (message === '') { message = presenciumMessageErreur(null) }
    if (isset(options.failure)) {
      options.failure(message)
      return
    }
    if (options.silent === true) { return }
    jeedomUtils.showAlert({ message: message, level: 'danger', ttl: 15000 })
  }
  var reussite = function (_resultat) {
    if (termine) { return }
    finir()
    _success(_resultat)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  var corps = new URLSearchParams()
  for (var cle in payload) {
    if (Object.prototype.hasOwnProperty.call(payload, cle)) {
      corps.append(cle, (payload[cle] === null || typeof payload[cle] === 'undefined') ? '' : String(payload[cle]))
    }
  }

  /* Le signal du coeur : il n'est abattu qu'au déchargement de la page
     (dom.utils.js, beforeunload). Une requête annulée pour cette raison n'est
     pas une panne et ne doit rien afficher — la page est déjà partie. */
  var signal = signalPage
  if (controleur !== null) {
    signal = controleur.signal
    if (signalPage) {
      if (signalPage.aborted) { controleur.abort() }
      signalPage.addEventListener('abort', relaisAbandon)
    }
  }

  fetch('plugins/presencium/core/ajax/presencium.ajax.php', {
    method: 'POST',
    body: corps,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    credentials: 'same-origin',
    signal: signal
  }).then(function (reponse) {
    return reponse.text().then(function (texte) {
      if (!reponse.ok) {
        /* Le corps de la réponse porte le message de l'erreur PHP ; le code
           seul ne dit rien de ce qu'il faut corriger. */
        throw new Error('{{Le serveur a répondu}} ' + reponse.status + ' ' + reponse.statusText
          + ' — ' + presenciumRaccourci(texte))
      }
      var data = null
      try {
        data = JSON.parse(texte)
      } catch (erreur) {
        /* Une notice PHP imprimée avant le JSON suffit à le rendre illisible,
           et c'est une panne fréquente à l'installation : montrer le début de
           la réponse est la seule façon de la voir. */
        throw new Error('{{Réponse illisible du plugin :}} ' + presenciumRaccourci(texte))
      }
      if (!isset(data) || data === null || data.state != 'ok') {
        throw new Error((isset(data) && data !== null && isset(data.result))
          ? presenciumTexteErreur(data.result)
          : '{{Le plugin a répondu une erreur sans message.}}')
      }
      return data.result
    })
  }).then(function (resultat) {
    reussite(resultat)
  }).catch(function (erreur) {
    if (isset(erreur) && erreur !== null && erreur.name === 'AbortError') {
      if (expiree) {
        echec(messageExpiree)
        return
      }
      finir()
      return
    }
    if (termine) {
      /* L'échec vient du rendu de la réponse, pas du transport : le dire à la
         console plutôt que d'afficher une erreur de réseau qui n'en est pas
         une. */
      console.error('[presencium] ' + _action, erreur)
      return
    }
    echec(presenciumMessageErreur(erreur))
  })
}

/* Identifiant de l'équipement ouvert, ou null s'il n'est pas encore
   enregistré. Le journal, le test d'une règle et l'évaluation travaillent sur
   ce qui est en base : sans identifiant, il n'y a rien à interroger. */
function presenciumCurrentId(_quiet) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    if (_quiet !== true) {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord l\'équipement.}}', level: 'warning' })
    }
    return null
  }
  return input.value
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function presenciumMarkModified() {
  if (presenciumRendering) { return }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Une ligne de texte posée sans balisage : les noms affichés viennent des
   équipements de l'utilisateur, et rien ne garantit ce qu'ils contiennent. */
function presenciumText(_tag, _className, _text) {
  var element = document.createElement(_tag)
  if (_className !== '') { element.className = _className }
  element.textContent = String(isset(_text) ? _text : '')
  return element
}

/* Une étiquette Bootstrap, texte posé sans balisage pour la même raison. */
function presenciumBadge(_classe, _texte) {
  var badge = presenciumText('span', 'label label-' + _classe, _texte)
  badge.style.marginLeft = '5px'
  return badge
}

/* Le type de l'équipement ouvert : `personne` ou `foyer`. Il est lu dans le
   champ caché et non déduit de l'affichage, qui peut n'être pas encore posé. */
function presenciumType() {
  var input = document.getElementById('in_presenciumType')
  return (input === null) ? '' : String(input.value || '')
}

/* Un entier propre, borné, sans NaN : un champ vidé à la main rendrait NaN,
   que JSON.stringify écrit `null` et que le serveur relirait comme zéro. */
function presenciumEntier(_valeur, _defaut, _min, _max) {
  var valeur = parseInt(_valeur, 10)
  if (isNaN(valeur)) { valeur = _defaut }
  if (valeur < _min) { valeur = _min }
  if (valeur > _max) { valeur = _max }
  return valeur
}

/* L'identifiant d'une règle neuve. Le serveur le renormalise de toute façon
   (presenciumRegles::nouvelIdentifiant) : celui-ci sert seulement à distinguer
   deux règles à l'écran avant le premier enregistrement. */
function presenciumNouvelIdentifiant() {
  return 'r-' + Math.random().toString(16).substring(2, 8)
}

/*
 * Affiche le nom lisible d'une commande désignée par son identifiant.
 *
 * Deux pannes à éviter, toutes deux vues en production :
 *  - la réponse du coeur peut arriver APRÈS que l'utilisateur a choisi une
 *    autre commande ; on ne l'écrit donc que si le champ montre toujours
 *    l'identifiant demandé, sinon deux lignes finiraient sur le même nom ;
 *  - une commande supprimée rend `#42#` tel quel. Laisser le champ vide
 *    ferait croire qu'aucune commande n'est configurée, alors que la règle
 *    évaluera bel et bien un identifiant mort : on montre l'identifiant ET on
 *    le dit.
 */
function presenciumAfficherNomCommande(_input, _id) {
  if (_input === null) { return }
  var id = parseInt(_id, 10)
  _input.style.color = ''
  if (isNaN(id) || id <= 0) {
    _input.setAttribute('data-cmd', '')
    _input.setAttribute('data-nom', '')
    _input.value = ''
    _input.placeholder = '{{Aucune commande choisie}}'
    return
  }

  _input.setAttribute('data-cmd', String(id))
  /* Repli visible immédiat : la forme brute vaut mieux qu'un champ vide le
     temps de l'aller-retour. */
  _input.value = '#' + id + '#'

  var introuvable = function () {
    if (_input.getAttribute('data-cmd') !== String(id)) { return }
    _input.setAttribute('data-nom', '')
    _input.value = '#' + id + '# — {{Commande introuvable}}'
    _input.style.color = '#d9534f'
  }

  jeedom.cmd.getHumanCmdName({
    id: id,
    error: introuvable,
    success: function (human) {
      /* La commande affichée a changé entre-temps : cette réponse ne la
         concerne plus. */
      if (_input.getAttribute('data-cmd') !== String(id)) { return }
      var nom = String(isset(human) && human !== null ? human : '')
      if (nom === '' || nom === '#' + id + '#') {
        introuvable()
        return
      }
      _input.setAttribute('data-nom', nom)
      _input.value = nom
    }
  })
}

/* ========================================================== TYPE DE PANNEAU */

/*
 * Montre ce qui concerne le type ouvert et cache le reste.
 *
 * Les onglets Règles et Journal n'ont aucun sens sur une personne : les
 * laisser visibles ouvrirait un tableau vide que rien n'expliquerait.
 */
function presenciumAppliquerType(_type) {
  var estFoyer = (_type === 'foyer')
  var estPersonne = (_type === 'personne')

  var blocPersonne = document.getElementById('div_presenciumPersonne')
  if (blocPersonne !== null) { blocPersonne.style.display = estPersonne ? '' : 'none' }
  var blocFoyer = document.getElementById('div_presenciumFoyer')
  if (blocFoyer !== null) { blocFoyer.style.display = estFoyer ? '' : 'none' }

  /* Les règles appartiennent au foyer. Le journal, lui, appartient aux deux :
     une personne y garde ses rebonds absorbés, et c'est le seul endroit où ils
     se lisent tant qu'elle n'entre dans aucun foyer. */
  var ongletRegles = document.getElementById('li_presenciumRegleTab')
  if (ongletRegles !== null) { ongletRegles.style.display = estFoyer ? '' : 'none' }
  var ongletJournal = document.getElementById('li_presenciumJournalTab')
  if (ongletJournal !== null) { ongletJournal.style.display = (estFoyer || estPersonne) ? '' : 'none' }

  presenciumAppliquerTypeJournal(estPersonne)

  /* Masquer le <li> ne suffit pas : le coeur mémorise l'onglet actif dans
     window.location.hash et le panneau reste affiché d'un équipement à
     l'autre. Ouvrir un foyer, aller sur Journal, revenir, puis cliquer sur une
     personne montrait la personne sur un onglet qu'on vient de masquer :
     panneau vide, formulaire introuvable, et rien à l'écran pour l'expliquer. */
  if (!estFoyer) {
    presenciumRamenerOnglet(estPersonne ? ['regletab'] : ['regletab', 'journaltab'])
  }
}

/*
 * Le panneau Journal selon le type ouvert.
 *
 * Une personne n'écrit ni règle ni alarme : son journal ne contient que des
 * mouvements de présence, dont les rebonds absorbés. Annoncer des règles
 * au-dessus et proposer de filtrer dessus fait chercher pendant un moment ce
 * qui ne s'y trouvera jamais, et conclure que le journal ne marche pas.
 */
function presenciumAppliquerTypeJournal(_estPersonne) {
  var titre = document.getElementById('span_presenciumJournalTitre')
  if (titre !== null) {
    titre.textContent = _estPersonne ? '{{Journal de cette personne}}' : '{{Journal de ce foyer}}'
  }

  var intro = document.getElementById('div_presenciumJournalIntro')
  if (intro !== null) {
    intro.textContent = _estPersonne
      ? '{{Le journal d\'une personne garde ses mouvements de présence : ses arrivées, ses départs, et surtout les rebonds absorbés — ceux-là seuls montrent ce que sa balise raconte vraiment, et c\'est sur eux que se règle le délai de départ.}}'
      : '{{Le journal garde ce que le plugin a décidé, et pourquoi : les règles déclenchées, celles écartées par leurs conditions ou par leur horaire, et les mouvements de présence — dont les rebonds absorbés, qui montrent ce que votre détecteur raconte vraiment.}}'
  }

  /* Un filtre qui ne rend jamais rien fait douter du journal, pas du filtre. */
  var filtre = document.getElementById('sel_presenciumFiltreJournal')
  if (filtre === null) { return }
  var reserves = ['regle', 'alarme']
  for (var i = 0; i < filtre.options.length; i++) {
    var option = filtre.options[i]
    var masquee = (_estPersonne && reserves.indexOf(String(option.value)) !== -1)
    option.style.display = masquee ? 'none' : ''
    option.disabled = masquee
    if (masquee && String(filtre.value) === String(option.value)) { filtre.value = 'tout' }
  }
}

/* Rebascule sur l'onglet Équipement quand l'onglet actif fait partie de ceux
   qui viennent d'être masqués. Le clic est simulé plutôt que les classes
   posées à la main : c'est le coeur (core/dom/dom.ui.js, gestion des
   a[role="tab"]) qui tient l'état des onglets ET le hash, et poser les classes
   soi-même laisserait le hash sur un onglet invisible — l'équipement suivant
   rouvrirait dessus. */
function presenciumRamenerOnglet(_masques) {
  var actif = document.querySelector('.eqLogic .tab-content > div[role="tabpanel"].active')
  if (actif === null) { return }
  if (_masques.indexOf(String(actif.getAttribute('id') || '')) === -1) { return }
  var lien = document.querySelector('.eqLogic .nav-tabs a[href="#eqtab"]')
  if (lien === null) { return }
  lien.click()
}

/* ==================================================== SOURCE D'UNE PERSONNE */

/* Pose la source à l'écran et dans le champ caché que le coeur enregistrera.
   `source` ne descend qu'à deux niveaux : le coeur la ramasse tout seul, il
   suffit qu'elle soit dans le champ. */
function presenciumAppliquerSource(_id) {
  var champ = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="source"]')
  var id = presenciumEntier(_id, 0, 0, 99999999)
  if (champ !== null) { champ.jeeValue(String(id)) }
  presenciumAfficherNomCommande(document.getElementById('in_presenciumSourceNom'), id)

  /* La liste déroulante suit le choix, y compris quand il vient de la loupe :
     deux endroits qui montrent deux commandes différentes rendraient la page
     impossible à relire. */
  var rapide = document.getElementById('sel_presenciumSourceRapide')
  if (rapide !== null) {
    rapide.value = (id > 0) ? String(id) : ''
    presenciumSourceHorsListe(rapide, id)
  }
  presenciumRenderAvertissements()
}

/*
 * Ajoute au choix rapide l'option d'une commande absente des candidates, et la
 * sélectionne.
 *
 * La loupe ouvre TOUTES les commandes info : la commande choisie n'est pas
 * forcément parmi celles que le plugin a reconnues. Le select retombait alors
 * sur son intitulé neutre, qui vaut '' — il affichait « commandes de présence
 * trouvées… » sur une personne parfaitement configurée, et le resélectionner
 * effaçait la source. Mieux vaut une option de plus qu'un select qui ment.
 */
function presenciumSourceHorsListe(_select, _id) {
  if (_select === null) { return }
  var id = presenciumEntier(_id, 0, 0, 99999999)
  if (id <= 0) { return }
  _select.value = String(id)
  if (_select.value === String(id)) { return }
  var option = document.createElement('option')
  option.value = String(id)
  option.textContent = '{{Commande choisie, hors des candidates}} (#' + id + '#)'
  _select.appendChild(option)
  _select.value = String(id)
}

/* Les commandes de présence découvertes dans l'installation. Le choix rapide
   existe parce que la loupe du coeur ouvre TOUTES les commandes : y retrouver
   la bonne balise demande de connaître son nom exact. */
function presenciumChargerSources() {
  var select = document.getElementById('sel_presenciumSourceRapide')
  if (select === null) { return }
  presenciumAjax('sources', {}, function (result) {
    presenciumSources = Array.isArray(result) ? result : []
    presenciumSourcesEtat = 'ok'
    presenciumRenderSources()
  }, {
    /* Un échec muet laissait une liste vide, lue « aucune balise trouvée ». */
    failure: function () {
      presenciumSources = []
      presenciumSourcesEtat = 'echec'
      presenciumRenderSources()
    }
  })
}

function presenciumRenderSources() {
  var select = document.getElementById('sel_presenciumSourceRapide')
  if (select === null) { return }
  var courant = ''
  var champ = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="source"]')
  if (champ !== null) { courant = String(champ.jeeValue() || '') }

  select.innerHTML = ''
  var vide = document.createElement('option')
  vide.value = ''
  vide.textContent = (presenciumSourcesEtat === 'echec')
    ? '{{— découverte indisponible : utilisez la loupe —}}'
    : '{{— commandes de présence trouvées —}}'
  select.appendChild(vide)

  for (var i = 0; i < presenciumSources.length; i++) {
    var source = presenciumSources[i]
    var option = document.createElement('option')
    option.value = String(source.id)
    var etiquette = String(source.nom)
    if (isset(source.objet) && source.objet !== '') { etiquette = source.objet + ' — ' + etiquette }
    if (isset(source.valeur) && source.valeur !== null && source.valeur !== '') {
      etiquette += ' (' + source.valeur + ')'
    }
    option.textContent = etiquette
    if (courant !== '' && courant === String(source.id)) { option.selected = true }
    select.appendChild(option)
  }
  /* Le select vient d'être reconstruit : la source configurée y remet son
     option si la découverte ne l'a pas proposée. */
  presenciumSourceHorsListe(select, courant)
}

/* ===================================================== PERSONNES DU FOYER */

function presenciumChargerPersonnes() {
  presenciumPersonnesErreur = ''
  /* Une liste déjà chargée reste affichée pendant qu'on la rafraîchit : la
     remplacer par « Lecture… » ferait clignoter les cases à chaque ouverture
     d'équipement, et un clignotement se lit comme une perte. */
  if (presenciumPersonnesEtat !== 'ok') {
    presenciumPersonnesEtat = 'chargement'
    presenciumRenderListePersonnes()
  }
  presenciumAjax('personnes', {}, function (result) {
    presenciumPersonnes = Array.isArray(result) ? result : []
    presenciumPersonnesEtat = 'ok'
    presenciumRenderListePersonnes()
    /* La modale peut être ouverte sur une règle « quelqu'un arrive » : sa
       liste de personnes se remplit de la même source. */
    presenciumRemplirSelectPersonne()
    presenciumRenderAvertissements()
  }, {
    /* Un échec ne doit pas se lire « il n'y a personne » : ce message-là fait
       croire qu'il faut créer des équipements qui existent déjà, et il
       s'affichait aussi pendant l'aller-retour, sur un foyer complet. */
    failure: function (message) {
      presenciumPersonnesErreur = String(message || '')
      if (presenciumPersonnesEtat === 'ok') {
        /* Une lecture précédente avait réussi : garder cette liste-là vaut
           mieux que la remplacer par une erreur, mais il faut dire qu'elle
           date — cocher d'après une liste périmée compose un faux foyer. */
        jeedomUtils.showAlert({
          message: '{{La liste des personnes n\'a pas pu être rafraîchie : celle affichée date de la lecture précédente.}} ' + presenciumPersonnesErreur,
          level: 'warning'
        })
        return
      }
      presenciumPersonnesEtat = 'echec'
      presenciumRenderListePersonnes()
      presenciumRemplirSelectPersonne()
      presenciumRenderAvertissements()
    }
  })
}

/* Les cases à cocher du foyer. Une case n'est jamais relue pour décider : le
   clic met à jour presenciumSelectionPersonnes, qui seul part à
   l'enregistrement. */
function presenciumRenderListePersonnes() {
  var conteneur = document.getElementById('div_presenciumListePersonnes')
  if (conteneur === null) { return }
  conteneur.innerHTML = ''

  /* Tant que la réponse n'est pas arrivée, on le dit. Un conteneur vide se lit
     « ce foyer n'a personne », ce qui est faux et pousse à cocher au hasard. */
  if (presenciumPersonnesEtat === 'vierge' || presenciumPersonnesEtat === 'chargement') {
    conteneur.appendChild(presenciumText('div', 'text-muted', '{{Lecture des personnes déclarées…}}'))
    return
  }

  /* Un échec de chargement n'est pas une absence de personnes : la sélection
     enregistrée est intacte, elle n'est simplement pas affichable. Le dire, et
     offrir de recommencer, au lieu d'accuser l'installation d'être vide. */
  if (presenciumPersonnesEtat === 'echec') {
    conteneur.appendChild(presenciumText('div', 'alert alert-danger',
      '{{La liste des personnes n\'a pas pu être lue. Les cases ne sont pas affichées, mais la composition de ce foyer est conservée telle quelle : enregistrer maintenant ne la perdra pas.}} '
      + presenciumPersonnesErreur))
    var reessayer = presenciumText('a', 'btn btn-sm btn-default', '{{Réessayer}}')
    reessayer.id = 'bt_presenciumRechargerPersonnes'
    conteneur.appendChild(reessayer)
    return
  }

  if (presenciumPersonnes.length === 0) {
    conteneur.appendChild(presenciumText('div', 'alert alert-warning',
      '{{Aucune personne n\'est déclarée. Créez d\'abord des équipements « Personne » : un foyer sans personne ne saura jamais si quelqu\'un est là.}}'))
  }

  for (var i = 0; i < presenciumPersonnes.length; i++) {
    var personne = presenciumPersonnes[i]
    var ligne = document.createElement('div')
    ligne.style.cssText = 'padding:2px 0;'

    var label = document.createElement('label')
    label.style.cssText = 'font-weight:normal;cursor:pointer;margin:0;'

    var checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    checkbox.className = 'presenciumPersonne'
    checkbox.setAttribute('data-id', String(personne.id))
    checkbox.checked = (presenciumSelectionPersonnes.indexOf(parseInt(personne.id, 10)) !== -1)
    checkbox.style.marginRight = '8px'
    label.appendChild(checkbox)

    label.appendChild(presenciumText('span', '', personne.nom))
    if (isset(personne.objet) && personne.objet !== '') {
      label.appendChild(presenciumBadge('default', personne.objet))
    }
    /* L'état courant, pour reconnaître la bonne personne sans quitter la page. */
    if (isset(personne.presence) && personne.presence !== null && personne.presence !== '') {
      label.appendChild(personne.presence == 1
        ? presenciumBadge('success', '{{présente}}')
        : presenciumBadge('default', '{{absente}}'))
    }
    ligne.appendChild(label)
    conteneur.appendChild(ligne)
  }

  presenciumRenderPersonnesDisparues(conteneur)
}

/*
 * Les habitants dont l'équipement n'existe plus.
 *
 * Ils sont dans la configuration du foyer mais n'ont plus de case : relever
 * les cases les retirait de la sélection au premier clic sur n'importe
 * laquelle, sans un mot, et le foyer perdait un membre en silence — on ne
 * s'en apercevait qu'en relisant l'occupation. On leur rend donc une ligne,
 * cochée, on dit ce qu'elle est, et c'est l'utilisateur qui décide de la
 * décocher.
 */
function presenciumRenderPersonnesDisparues(_conteneur) {
  if (presenciumPersonnesEtat !== 'ok') { return }
  var disparues = []
  for (var i = 0; i < presenciumSelectionPersonnes.length; i++) {
    var id = presenciumSelectionPersonnes[i]
    var vivante = false
    for (var p = 0; p < presenciumPersonnes.length; p++) {
      if (parseInt(presenciumPersonnes[p].id, 10) === id) { vivante = true }
    }
    if (!vivante) { disparues.push(id) }
  }
  if (disparues.length === 0) { return }

  _conteneur.appendChild(presenciumText('div', 'alert alert-warning',
    '{{Ce foyer compte des habitants dont l\'équipement « Personne » n\'existe plus. Ils sont comptés dans l\'occupation tant qu\'ils sont cochés, et resteront absents pour toujours. Décochez-les pour les retirer, puis enregistrez.}}'))

  for (var d = 0; d < disparues.length; d++) {
    var ligne = document.createElement('div')
    ligne.style.cssText = 'padding:2px 0;'
    var label = document.createElement('label')
    label.style.cssText = 'font-weight:normal;cursor:pointer;margin:0;'
    var checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    checkbox.className = 'presenciumPersonne'
    checkbox.setAttribute('data-id', String(disparues[d]))
    checkbox.checked = true
    checkbox.style.marginRight = '8px'
    label.appendChild(checkbox)
    label.appendChild(presenciumText('span', 'text-danger', '{{Personne supprimée}} (#' + disparues[d] + '#)'))
    ligne.appendChild(label)
    _conteneur.appendChild(ligne)
  }
}

/* Relève les cases cochées. Appelée au clic, jamais à l'enregistrement : si la
   liste n'a pas été dessinée, la sélection chargée doit rester intacte. */
function presenciumLireListePersonnes() {
  var conteneur = document.getElementById('div_presenciumListePersonnes')
  if (conteneur === null) { return }
  var cases = conteneur.querySelectorAll('.presenciumPersonne')
  if (cases.length === 0) { return }
  var selection = []
  for (var i = 0; i < cases.length; i++) {
    if (cases[i].checked) { selection.push(parseInt(cases[i].getAttribute('data-id'), 10)) }
  }
  presenciumSelectionPersonnes = selection
}

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
 * d'autre.
 *
 * Le repli sur #jee_modal était une bombe à retardement. Ce conteneur-là est
 * créé une fois par le coeur et n'est JAMAIS retiré du DOM : sa fermeture se
 * contente de vider .jeeDialogContent (core/dom/dom.ui.js, close()). Y poser
 * les écouteurs de l'éditeur les gravait pour toute la session ; l'ouverture
 * suivante les reposait sur le vrai conteneur de l'éditeur, imbriqué dedans,
 * et chaque clic remontait alors les deux : deux conditions ajoutées d'un
 * clic, deux actions, une règle neuve validée deux fois — et « Tester » qui
 * exécutait les actions deux fois pour de vrai, sur une alarme. Sans
 * charpente d'éditeur, il n'y a pas d'éditeur : on ne branche rien.
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
 * Trois protections, parce qu'une seule a déjà lâché :
 *  - l'attribut, qui dit que cette fenêtre-ci est déjà branchée ;
 *  - des fonctions NOMMÉES de portée module plutôt que des fermetures
 *    anonymes : addEventListener ignore un doublon exact — même cible, même
 *    fonction, mêmes options — ce qu'il ne peut pas faire d'une fonction
 *    recréée à chaque appel. Le branchement est donc idempotent quoi qu'il
 *    arrive ;
 *  - la racine (presenciumRacineModale) qui ne peut plus désigner un élément
 *    survivant à la fermeture.
 * Ce qui était en jeu : un double comptage des clics, donc « Tester » qui
 * exécute deux fois des actions réelles.
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
      /* Un nom distinct de celui du rappel voisin : deux `var champId` à
         quelques lignes l'un de l'autre se lisent comme une redéclaration, et
         la relecture s'arrête là au lieu de regarder ce qui compte. */
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

/*
 * Les défauts d'un équipement inachevé, dans son panneau.
 *
 * Une personne sans source et un foyer sans habitant ont l'air normaux et le
 * restent indéfiniment : rien ne bouge, aucune erreur n'est journalisée, et on
 * ne s'en aperçoit que le jour où l'alarme ne s'arme pas.
 */
function presenciumRenderAvertissements() {
  var conteneur = document.getElementById('div_presenciumAvertissements')
  if (conteneur === null) { return }
  conteneur.innerHTML = ''
  var type = presenciumType()

  if (type === 'personne') {
    var champ = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="source"]')
    var source = (champ === null) ? 0 : presenciumEntier(champ.jeeValue(), 0, 0, 99999999)
    if (source <= 0) {
      conteneur.appendChild(presenciumText('div', 'alert alert-warning',
        '{{Cette personne n\'a aucune commande de présence : son signal brut ne changera jamais, elle restera absente pour toujours, et les règles d\'arrivée du foyer ne partiront pas. Choisissez la commande de sa balise ci-dessous.}}'))
    }
    return
  }

  if (type === 'foyer') {
    /* Le reproche n'est fait que si la liste est arrivée : sur un échec ou
       pendant l'aller-retour, un foyer parfaitement composé se ferait accuser
       d'être vide. */
    if (presenciumPersonnesEtat === 'ok' && presenciumPersonnes.length > 0
        && presenciumSelectionPersonnes.length === 0) {
      conteneur.appendChild(presenciumText('div', 'alert alert-warning',
        '{{Aucune personne n\'est cochée dans ce foyer alors que des personnes sont déclarées : il restera vide en permanence, « Qui est là » restera muet, et ses règles de départ ne partiront jamais. Cochez ses habitants dans « Les habitants ».}}'))
    }
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
   * une règle sans enregistrer le décalait de la configuration du serveur, qui
   * reprenait ce rang dans SA liste. Le bouton jouait alors — réellement, hors
   * simulation, sur une alarme — une autre règle que celle affichée. Le
   * serveur retrouve désormais la règle par son identifiant, et répond une
   * erreur explicite s'il ne la connaît pas.
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

/* ================================================================ JOURNAL */

/* Une entrée du journal. Elle est lue dans l'ordre : quand, quel verdict,
   sur quoi, puis le détail — conditions et actions dépliées, jamais repliées :
   le journal ne sert qu'à répondre à « pourquoi ça ne s'est pas déclenché ». */
function presenciumEntreeJournal(_entree) {
  var entree = _entree || {}
  var verdict = String(init(entree.verdict, ''))
  var connu = isset(presenciumVerdicts[verdict]) ? presenciumVerdicts[verdict] : { classe: 'default', texte: verdict }
  var simulee = (entree.simulation === true || entree.simulation == 1)
  var rebond = (verdict === 'rebond_absorbe')
  /* Un essai manuel n'est pas un déclenchement : relire le journal une semaine
     plus tard et prendre trois essais de réglage pour de vrais départs ruine
     l'outil de détection des faux positifs, qui n'a d'autre valeur que d'être
     cru. */
  var essai = (entree.essai === true || entree.essai == 1)

  var bloc = document.createElement('div')
  bloc.className = 'presenciumEntree'
  bloc.style.cssText = 'padding:6px 10px;margin-bottom:6px;border-left:4px solid '
    + init(presenciumCouleurs[connu.classe], presenciumCouleurs['default'])
    + ';background:rgba(128,128,128,0.06);'
  /* Le rebond absorbé est ce qu'on vient chercher dans ce journal : c'est lui
     qui prouve que le délai de départ travaille. Il ne se fond pas dans la
     liste. */
  if (rebond) {
    bloc.style.borderLeftWidth = '6px'
    bloc.style.background = 'rgba(240,173,78,0.15)'
  }
  /* Le trait discontinu distingue l'essai avant même qu'on lise l'entrée. */
  if (essai) { bloc.style.borderLeftStyle = 'dashed' }

  var entete = document.createElement('div')
  entete.style.cssText = 'display:flex;align-items:center;flex-wrap:wrap;'

  var date = presenciumText('span', 'text-muted', init(entree.date, ''))
  date.style.cssText = 'font-family:monospace;margin-right:8px;'
  entete.appendChild(date)

  var etiquette = presenciumText('span', 'label label-' + connu.classe, connu.texte)
  if (rebond) { etiquette.style.fontWeight = 'bold' }
  entete.appendChild(etiquette)

  /* Le badge de simulation est ce qui distingue « ça se serait déclenché » de
     « ça s'est déclenché ». Sans lui, le journal se relit à l'envers. */
  if (simulee) {
    var badge = presenciumBadge('warning', '{{SIMULÉ — rien n\'a été exécuté}}')
    badge.style.fontWeight = 'bold'
    entete.appendChild(badge)
  }
  if (essai) {
    var badgeEssai = presenciumBadge('info', '{{ESSAI MANUEL — lancé depuis le bouton « Tester »}}')
    badgeEssai.style.fontWeight = 'bold'
    entete.appendChild(badgeEssai)
  }
  var genres = { regle: '{{Règle}}', presence: '{{Présence}}', alarme: '{{Alarme}}' }
  var genre = String(init(entree.genre, ''))
  entete.appendChild(presenciumBadge('default', isset(genres[genre]) ? genres[genre] : genre))
  bloc.appendChild(entete)

  var titre = document.createElement('div')
  titre.style.marginTop = '3px'
  if (init(entree.nom, '') !== '') {
    titre.appendChild(presenciumText('b', '', entree.nom))
  }
  if (init(entree.declencheur, '') !== '') {
    var declencheur = presenciumText('span', 'text-muted', ' — ' + entree.declencheur)
    titre.appendChild(declencheur)
  }
  if (titre.childNodes.length > 0) { bloc.appendChild(titre) }

  if (rebond) {
    bloc.appendChild(presenciumText('div', '',
      '{{Le signal brut est repassé à « présent » avant la fin du délai de départ : la fausse absence a été absorbée.}}'))
  }
  if (init(entree.detail, '') !== '') {
    bloc.appendChild(presenciumText('div', '', entree.detail))
  }

  var conditions = (isset(entree.conditions) && Array.isArray(entree.conditions)) ? entree.conditions : []
  if (conditions.length > 0) {
    var listeConditions = document.createElement('ul')
    listeConditions.style.cssText = 'margin:4px 0 0 0;padding-left:18px;'
    for (var c = 0; c < conditions.length; c++) {
      var vraie = (conditions[c].resultat === true || conditions[c].resultat == 1)
      var li = presenciumText('li', vraie ? 'text-success' : 'text-danger',
        (vraie ? '✔ ' : '✘ ') + init(conditions[c].texte, ''))
      listeConditions.appendChild(li)
    }
    bloc.appendChild(listeConditions)
  }

  var actions = (isset(entree.actions) && Array.isArray(entree.actions)) ? entree.actions : []
  if (actions.length > 0) {
    var listeActions = document.createElement('ul')
    listeActions.style.cssText = 'margin:4px 0 0 0;padding-left:18px;'
    for (var a = 0; a < actions.length; a++) {
      var resultat = String(init(actions[a].resultat, ''))
      /* La couleur se lit sur le drapeau `ok` que pose le PHP, jamais sur le
         mot qu'il affiche : « simulée » et « échec » passent par __() et
         deviennent « simulated » et « failed » dans une installation anglaise,
         où le journal perdrait toute sa couleur sans qu'on sache pourquoi. */
      var classe = (init(actions[a].ok, true) === false) ? 'text-danger'
        : (entree.simulation ? 'text-warning' : 'text-muted')
      listeActions.appendChild(presenciumText('li', classe,
        init(actions[a].cmd, '') + ' → ' + resultat))
    }
    bloc.appendChild(listeActions)
  }

  return bloc
}

/* Le tableau d'analyse d'une balise.
   Chaque ligne est un délai de départ possible, rejoué sur l'historique réel de
   CETTE balise par la fonction même que le cron utilise. Les deux colonnes qui
   décident sont « faux départs » et « vrais départs » : le bon délai est le
   plus petit qui met la première à zéro sans entamer la seconde. */
function presenciumRenderAnalyse(_resultat) {
  var sortie = document.getElementById('div_presenciumAnalyse')
  if (sortie === null) { return }
  sortie.innerHTML = ''
  if (!isset(_resultat) || !isset(_resultat.lignes)) { return }
  /* Sans aucune absence relevée, aucun délai n'est « meilleur » : ne rien
     recommander plutôt qu'« Appliquer 0 min ». */
  var episodes = parseInt(init(_resultat.episodes, 0), 10)
  var recommande = (episodes > 0 && isset(_resultat.recommande) && _resultat.recommande !== null)
    ? _resultat.recommande : null

  var resume = presenciumText('div', 'text-muted',
    init(_resultat.heures, 0) + ' {{h d\'historique}} — ' + init(_resultat.episodes, 0) + ' {{absences}} : '
    + init(_resultat.courtes, 0) + ' {{courtes}}, ' + init(_resultat.longues, 0) + ' {{longues}} (≥ '
    + init(_resultat.seuil_vrai, 60) + ' {{min}})')
  resume.style.cssText = 'font-size:11px;margin-bottom:4px;'
  sortie.appendChild(resume)

  var table = document.createElement('table')
  table.className = 'table table-condensed table-bordered'
  table.style.cssText = 'margin-bottom:6px;font-size:12px;'
  var thead = document.createElement('thead')
  var ligneEntete = document.createElement('tr')
  var entetes = ['{{Délai}}', '{{Départs}}', '{{Faux}}', '{{Vrais}}', '{{Présence tenue à tort}}', '']
  for (var e = 0; e < entetes.length; e++) {
    var th = document.createElement('th')
    th.textContent = entetes[e]
    ligneEntete.appendChild(th)
  }
  thead.appendChild(ligneEntete)
  table.appendChild(thead)

  var tbody = document.createElement('tbody')
  for (var i = 0; i < _resultat.lignes.length; i++) {
    var l = _resultat.lignes[i]
    var tr = document.createElement('tr')
    var retenu = (recommande !== null && parseInt(l.delai, 10) === parseInt(recommande, 10))
    if (retenu) { tr.style.cssText = 'background:rgba(92,184,92,0.14);font-weight:700;' }

    tr.appendChild(presenciumText('td', '', init(l.delai, 0) + ' {{min}}'))
    tr.appendChild(presenciumText('td', '', String(init(l.departs, 0))))
    tr.appendChild(presenciumText('td', parseInt(l.faux, 10) > 0 ? 'text-danger' : 'text-muted', String(init(l.faux, 0))))
    tr.appendChild(presenciumText('td',
      parseInt(l.vrais, 10) < parseInt(l.vrais_total, 10) ? 'text-danger' : 'text-muted',
      init(l.vrais, 0) + ' / ' + init(l.vrais_total, 0)))
    tr.appendChild(presenciumText('td', 'text-muted', init(l.tenu_present, 0) + ' {{min}}'))

    var cellule = document.createElement('td')
    if (retenu) {
      var appliquer = document.createElement('a')
      appliquer.className = 'btn btn-xs btn-success presenciumAppliquerDelai'
      appliquer.setAttribute('data-delai', String(l.delai))
      appliquer.innerHTML = '<i class="fas fa-check"></i> {{Appliquer}}'
      cellule.appendChild(appliquer)
    }
    tr.appendChild(cellule)
    tbody.appendChild(tr)
  }
  table.appendChild(tbody)
  sortie.appendChild(table)

  if (episodes <= 0) {
    sortie.appendChild(presenciumText('div', 'alert alert-info',
      '{{Aucune absence relevée sur cette période : rien à recommander. Vérifiez que la commande suivie est historisée, ou allongez la fenêtre d\'analyse.}}'))
    return
  }

  if (recommande === null) {
    /* Aucun délai ne sépare les deux familles : le dire vaut mieux que d'en
       désigner un. Cela arrive quand les décrochages de la balise durent plus
       longtemps qu'une vraie sortie — le réglage ne peut alors pas réparer ce
       que le signal ne permet pas de distinguer. */
    sortie.appendChild(presenciumText('div', 'alert alert-warning',
      '{{Aucun délai ne sépare proprement les deux familles sur cette période : les décrochages de cette balise durent aussi longtemps que de vraies sorties. Allongez la fenêtre d\'analyse, ou revoyez le seuil de ce qu\'est une vraie absence.}}'))
    return
  }

  var conseil = presenciumText('div', 'alert alert-success',
    '{{Délai retenu :}} ' + recommande + ' {{min}}. '
    + '{{C\'est le plus petit qui ne laisse passer aucune fausse absence et ne perd aucune vraie.}} '
    + ((parseInt(_resultat.plus_longue_courte, 10) > 0)
        ? '{{La plus longue fausse absence mesurée dure}} ' + _resultat.plus_longue_courte + ' {{min}} ; '
          + '{{la plus courte vraie,}} ' + _resultat.plus_courte_longue + ' {{min}}.'
        : ''))
  conseil.style.cssText = 'padding:6px 10px;margin:0;font-size:12px;'
  sortie.appendChild(conseil)
}

/*
 * Ce que le journal couvre, en une ligne, au-dessus de tout le reste.
 *
 * Un journal se relit pour conclure — « rien ne s'est déclenché de la semaine »,
 * « la balise n'a pas rebondi ». Ces conclusions supposent qu'on voie toute la
 * période, et rien ne le disait : ni combien d'entrées existent au-delà de
 * celles qui s'affichent, ni jusqu'où elles remontent, ni si les plus anciennes
 * ont déjà été effacées faute de place. Les trois se disent en une phrase.
 */
function presenciumCouvertureJournal(_entrees, _total, _taille) {
  var plusAncienne = (_entrees.length > 0) ? String(init(_entrees[_entrees.length - 1].date, '')) : ''
  var texte = (_total > _entrees.length)
    ? (_entrees.length + ' {{entrées affichées sur}} ' + _total)
    : (_total + ' {{entrée(s)}}')
  if (plusAncienne !== '') {
    texte += ', {{la plus ancienne du}} ' + plusAncienne
  }
  /* Plein veut dire que des entrées ont DÉJÀ disparu : la campagne qu'on relit
     commence plus tard qu'on ne croit, et c'est le seul moment où le dire
     change une conclusion. */
  var plein = (_taille > 0 && _total >= _taille)
  if (plein) {
    texte += ' — {{le journal est plein, les plus anciennes ont été effacées. Montez « Entrées conservées » dans la configuration du plugin pour garder une campagne plus longue.}}'
  }
  return presenciumText('div', plein ? 'alert alert-warning' : 'text-muted', texte)
}

/* Le journal du foyer ouvert, filtré par genre, la plus récente en tête. */
function presenciumRenderJournal() {
  var conteneur = document.getElementById('div_presenciumJournal')
  if (conteneur === null) { return }
  var id = presenciumCurrentId(true)
  if (id === null) {
    conteneur.innerHTML = ''
    conteneur.appendChild(presenciumText('div', 'alert alert-info',
      '{{Enregistrez l\'équipement : le journal se remplit au fil des évaluations.}}'))
    return
  }

  /* Le filtre part au serveur à chaque lecture : c'est lui qui écarte les
     genres, et c'est la seule façon que « 200 entrées » veuille dire 200
     entrées DE CE GENRE plutôt que 200 entrées dont trois survivent au tri. */
  var filtre = document.getElementById('sel_presenciumFiltreJournal')
  var genre = (filtre === null) ? 'tout' : String(filtre.value || 'tout')

  /* Deux changements de filtre lancent deux requêtes et rien ne garantit
     l'ordre des réponses : sans ce jeton, la plus lente écrase la plus
     récente, et le journal affiché ne correspond plus au filtre affiché. */
  presenciumJournalJeton++
  var jeton = presenciumJournalJeton

  conteneur.innerHTML = ''
  conteneur.appendChild(presenciumText('div', 'text-muted', '{{Lecture du journal…}}'))

  presenciumAjax('journal', { id: id, limite: 200, genre: genre }, function (result) {
    if (jeton !== presenciumJournalJeton) { return }
    /* La réponse peut arriver après que l'utilisateur a ouvert un autre
       équipement : l'écrire alors mélangerait deux journaux. */
    var courant = presenciumCurrentId(true)
    if (courant === null || String(courant) !== String(id)) { return }

    conteneur.innerHTML = ''
    /* { entrees, total, taille } : la seule forme que rend le serveur. */
    var charge = result || {}
    var entrees = Array.isArray(charge.entrees) ? charge.entrees : []
    var total = parseInt(init(charge.total, entrees.length), 10)
    var taille = parseInt(init(charge.taille, 0), 10)
    /* Le serveur rend déjà la plus récente en tête ; le tri est refait ici
       parce qu'un journal restauré à la main peut arriver dans le désordre, et
       qu'un journal mal ordonné ne se lit pas du tout. */
    entrees.sort(function (_a, _b) { return parseInt(init(_b.ts, 0), 10) - parseInt(init(_a.ts, 0), 10) })

    if (entrees.length === 0) {
      conteneur.appendChild(presenciumText('div', 'alert alert-info',
        (genre === 'tout')
          ? '{{Journal vide. Il se remplira à la première transition de présence ou à la première règle évaluée.}}'
          : '{{Aucune entrée de ce genre.}}'))
      return
    }
    conteneur.appendChild(presenciumCouvertureJournal(entrees, total, taille))
    for (var i = 0; i < entrees.length; i++) {
      conteneur.appendChild(presenciumEntreeJournal(entrees[i]))
    }
  }, {
    failure: function (message) {
      /* Mêmes gardes que le succès : une erreur arrivée après un changement
         d'équipement ou de filtre écrivait « échec » par-dessus le journal
         d'un autre foyer, parfaitement lu. */
      if (jeton !== presenciumJournalJeton) { return }
      var courantEchec = presenciumCurrentId(true)
      if (courantEchec === null || String(courantEchec) !== String(id)) { return }
      conteneur.innerHTML = ''
      conteneur.appendChild(presenciumText('div', 'alert alert-danger', message))
    }
  })
}

/* ============================================================== VERDICT */

/* L'état courant, tel que le serveur le voit. Affiché dans #div_presenciumVerdict
   si la page en porte un — sinon la page n'en veut pas, et il n'y a rien à
   faire. */
function presenciumRafraichirVerdict() {
  var conteneur = document.getElementById('div_presenciumVerdict')
  if (conteneur === null) { return }
  var id = presenciumCurrentId(true)
  if (id === null) {
    conteneur.innerHTML = ''
    presenciumMarquerForcage('')
    return
  }

  presenciumAjax('verdict', { id: id }, function (result) {
    var courant = presenciumCurrentId(true)
    if (courant === null || String(courant) !== String(id)) { return }
    conteneur.innerHTML = ''
    presenciumMarquerForcage('')
    if (!isset(result) || result === null) { return }

    if (isset(result.raison)) {
      conteneur.appendChild(presenciumBadge(result.present ? 'success' : 'default',
        result.present ? '{{Présent}}' : '{{Absent}}'))
      /* Un forçage fait taire la balise : il doit se voir avant le reste. */
      var mode = String(init(result.mode, 'auto'))
      if (mode === 'present' || mode === 'absent') {
        var force = presenciumBadge('warning', (mode === 'present') ? '{{Forcé présent}}' : '{{Forcé absent}}')
        force.style.fontWeight = 'bold'
        conteneur.appendChild(force)
      }
      presenciumMarquerForcage(mode)
      conteneur.appendChild(presenciumBadge('default', '{{signal brut}} : ' + (result.brut ? '1' : '0')))
      if (result.transitoire) {
        conteneur.appendChild(presenciumBadge('warning',
          '{{confirmation en cours}} — ' + presenciumEntier(result.restant, 0, 0, 100000) + ' s'))
      }
      return
    }
    if (isset(result.total)) {
      var presents = isset(result.presents) ? result.presents : {}
      var noms = []
      for (var cle in presents) { noms.push(presents[cle]) }
      conteneur.appendChild(presenciumBadge(noms.length > 0 ? 'success' : 'default',
        noms.length + ' / ' + result.total))
      if (noms.length > 0) { conteneur.appendChild(presenciumText('span', '', ' ' + noms.join(', '))) }
      if (result.simulation == 1) {
        conteneur.appendChild(presenciumBadge('warning', '{{simulation}}'))
      }
    }
  }, { silent: true })
}

/* Le bouton du forçage en cours, enfoncé ; '' les relâche tous. */
function presenciumMarquerForcage(_mode) {
  var boutons = { present: 'bt_presenciumForcerPresent', absent: 'bt_presenciumForcerAbsent', auto: 'bt_presenciumAuto' }
  for (var mode in boutons) {
    var bouton = document.getElementById(boutons[mode])
    if (bouton === null) { continue }
    bouton.classList.toggle('active', mode === _mode)
  }
}

/*
 * Le verdict affiché se fige : « encore 8 min » le reste. On le relit toutes
 * les 30 s tant que la fiche d'une personne est ouverte et visible. Le
 * minuteur s'arrête seul quand la page ou l'équipement changent.
 */
function presenciumSuivreVerdict() {
  if (presenciumMinuteurVerdict !== null) {
    clearInterval(presenciumMinuteurVerdict)
    presenciumMinuteurVerdict = null
  }
  var id = presenciumCurrentId(true)
  if (id === null || presenciumType() !== 'personne') { return }
  presenciumMinuteurVerdict = setInterval(function () {
    var conteneur = document.getElementById('div_presenciumVerdict')
    var courant = presenciumCurrentId(true)
    if (conteneur === null || courant === null || String(courant) !== String(id) || presenciumType() !== 'personne') {
      clearInterval(presenciumMinuteurVerdict)
      presenciumMinuteurVerdict = null
      return
    }
    /* Caché (autre onglet, liste des vignettes, navigateur en arrière-plan) :
       on attend sans interroger. */
    if (conteneur.offsetParent === null || document.visibilityState === 'hidden') { return }
    presenciumRafraichirVerdict()
  }, 30000)
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

function printEqLogic(_eqLogic) {
  presenciumRendering = true
  var type = ''
  try {
    var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
    type = String(init(configuration.type, ''))
    presenciumAppliquerType(type)

    presenciumChargePour = String(init(_eqLogic.id, ''))
    presenciumRegles = (isset(configuration.regles) && Array.isArray(configuration.regles))
      ? JSON.parse(JSON.stringify(configuration.regles)) : []
    presenciumReglesChargees = JSON.stringify(presenciumRegles)
    /* Ce qui est EN BASE, relevé avant que l'écran ne puisse le modifier :
       c'est cette liste qui autorise « Tester », lequel joue la règle
       enregistrée et non celle qu'on est en train de saisir. */
    presenciumReglesEnregistrees = []
    for (var r = 0; r < presenciumRegles.length; r++) {
      var identifiantRegle = String(init(presenciumRegles[r].id, ''))
      if (identifiantRegle !== '') { presenciumReglesEnregistrees.push(identifiantRegle) }
    }
    presenciumRenderRegles()

    presenciumSelectionPersonnes = []
    var personnes = (isset(configuration.personnes) && Array.isArray(configuration.personnes))
      ? configuration.personnes : []
    for (var i = 0; i < personnes.length; i++) {
      var id = parseInt(personnes[i], 10)
      if (!isNaN(id) && id > 0) { presenciumSelectionPersonnes.push(id) }
    }
    presenciumRenderListePersonnes()

    presenciumAppliquerSource(init(configuration.source, 0))

    /* Le coeur ne réinitialise que les .eqLogicAttr : sans cela, le journal et
       le verdict garderaient ceux de l'équipement précédemment ouvert. */
    var journal = document.getElementById('div_presenciumJournal')
    if (journal !== null) { journal.innerHTML = '' }
    var verdict = document.getElementById('div_presenciumVerdict')
    if (verdict !== null) { verdict.innerHTML = '' }
    presenciumMarquerForcage('')
    var analyse = document.getElementById('div_presenciumAnalyse')
    if (analyse !== null) { analyse.innerHTML = '' }

    presenciumRenderAvertissements()
  } finally {
    presenciumRendering = false
  }

  if (type === 'personne') { presenciumChargerSources() }
  if (type === 'foyer') { presenciumChargerPersonnes() }
  if (type === 'personne' || type === 'foyer') { presenciumRenderJournal() }
  presenciumRafraichirVerdict()
  presenciumSuivreVerdict()
}

/*
 * Appelée par plugin.template.js juste avant l'enregistrement.
 *
 * Les règles et les personnes sont des structures imbriquées : data-lXkey ne
 * descend qu'à trois niveaux, et une règle en compte davantage (conditions →
 * lignes → cmd). Le coeur ne les ramasse donc pas, et sans ces deux lignes la
 * saisie disparaît à l'enregistrement, sans le moindre message : la page se
 * recharge sur la configuration d'avant.
 */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  /* Les règles et la composition du foyer n'appartiennent qu'au foyer. Les
     poser aussi sur une personne ne cassait rien, mais laissait dans sa
     configuration des clés qui n'ont aucun sens pour elle — de quoi faire
     douter, un jour, celui qui lira la base pour comprendre un comportement.
     Ces deux structures dépassent trois niveaux de clés : le cœur ne les
     ramasse pas seul, c'est ici et nulle part ailleurs qu'elles sont posées. */
  if (String(init(_eqLogic.configuration.type, '')) === 'foyer') {
    _eqLogic.configuration.regles = presenciumRegles
    _eqLogic.configuration.personnes = presenciumSelectionPersonnes
  } else {
    delete _eqLogic.configuration.regles
    delete _eqLogic.configuration.personnes
    delete _eqLogic.configuration.simulation
  }
  return _eqLogic
}

/* ================================================================ COMMANDES */

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* L'identifiant, caché mais présent : sans lui, chaque enregistrement
     détruirait les commandes et les recréerait — historique perdu, scénarios et
     widgets pointant dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages du plugin sont chargées en AJAX : DOMContentLoaded a déjà eu lieu
   quand ce script est évalué, et l'attendre ne déclencherait jamais rien. Les
   écouteurs sont donc posés ici, à la racine du script, en délégation sur le
   conteneur de page — qui est remplacé à chaque navigation, ce qui les emporte
   avec lui au lieu de les empiler. */
var presenciumContainer = document.getElementById('div_pageContainer') || document.body

presenciumContainer.addEventListener('click', function (event) {
  var cible = null

  /* ---- création des deux types d'équipement */
  if ((cible = event.target.closest('#bt_presenciumAddPersonne'))
      || (cible = event.target.closest('#bt_presenciumAddFoyer'))) {
    var type = (cible.id === 'bt_presenciumAddPersonne') ? 'personne' : 'foyer'
    jeeDialog.prompt('{{Nom de l\'équipement ?}}', function (nom) {
      if (nom === null || String(nom).trim() === '') { return }
      presenciumAjax('ajouter', { nom: String(nom).trim(), type: type }, function (result) {
        /* Quitter la page sans lever l'avertissement du coeur : l'équipement
           vient d'être enregistré côté serveur, il n'y a rien à perdre. */
        if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = false }
        window.modifyWithoutSave = false
        var vars = getUrlVars()
        var url = 'index.php?'
        for (var i in vars) {
          if (i != 'id' && i != 'saveSuccessFull' && i != 'removeSuccessFull') {
            url += i + '=' + vars[i].replace('#', '') + '&'
          }
        }
        url += 'id=' + result.id + '&saveSuccessFull=1'
        jeedomUtils.loadPage(url)
      }, { button: cible })
    })
    return
  }

  /* ---- source d'une personne */
  if (cible = event.target.closest('#bt_presenciumChoisirSource')) {
    /* Filtré sur les commandes d'information : une source de présence est un
       état, pas un bouton. Le callback n'est PAS appelé si l'utilisateur
       valide sans rien choisir : la source en place reste en place. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(result) || !isset(result.cmd) || !isset(result.cmd.id)) { return }
      presenciumAppliquerSource(result.cmd.id)
      presenciumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('#bt_presenciumViderSource')) {
    presenciumAppliquerSource(0)
    presenciumMarkModified()
    return
  }

  /* ---- règles */
  if (event.target.closest('#bt_presenciumAjouterRegle')) {
    presenciumOuvrirRegle(-1)
    return
  }

  if (cible = event.target.closest('.presenciumEditerRegle')) {
    presenciumOuvrirRegle(parseInt(cible.closest('tr').getAttribute('data-index'), 10))
    return
  }

  if (cible = event.target.closest('.presenciumSupprimerRegle')) {
    var rangSuppression = parseInt(cible.closest('tr').getAttribute('data-index'), 10)
    if (isNaN(rangSuppression) || !isset(presenciumRegles[rangSuppression])) { return }
    var supprimee = init(presenciumRegles[rangSuppression].nom, '{{Règle sans nom}}')
    presenciumRegles.splice(rangSuppression, 1)
    presenciumRenderRegles()
    presenciumMarkModified()
    /* Une corbeille qui ne dit rien laisse croire que la règle est perdue pour
       de bon : elle ne l'est qu'à l'enregistrement, et quitter la page sans
       sauvegarder la rend intacte. Le dire évite autant la panique que la
       fausse sécurité. */
    jeedomUtils.showAlert({
      message: '{{Règle retirée de la liste :}} ' + supprimee
        + ' — {{elle ne disparaîtra définitivement qu\'au « Sauvegarder » de l\'équipement. Quitter la page sans enregistrer la laisse intacte.}}',
      level: 'warning', timeOut: 8000
    })
    return
  }

  if (cible = event.target.closest('.presenciumDupliquerRegle')) {
    var rangCopie = parseInt(cible.closest('tr').getAttribute('data-index'), 10)
    var copie = JSON.parse(JSON.stringify(presenciumRegles[rangCopie]))
    /* Un identifiant neuf : deux règles partageant le même identifiant
       partageraient aussi leur anti-répétition et leur attente, et la seconde
       ne se déclencherait jamais. */
    copie.id = presenciumNouvelIdentifiant()
    copie.nom = init(copie.nom, '') + ' {{(copie)}}'
    presenciumRegles.splice(rangCopie + 1, 0, copie)
    presenciumRenderRegles()
    presenciumMarkModified()
    return
  }

  if (cible = event.target.closest('.presenciumBasculerRegle')) {
    var rangBascule = parseInt(cible.closest('tr').getAttribute('data-index'), 10)
    presenciumRegles[rangBascule].actif = (init(presenciumRegles[rangBascule].actif, 1) == 1) ? 0 : 1
    presenciumRenderRegles()
    presenciumMarkModified()
    return
  }

  if ((cible = event.target.closest('.presenciumMonterRegle'))
      || (cible = event.target.closest('.presenciumDescendreRegle'))) {
    var rang = parseInt(cible.closest('tr').getAttribute('data-index'), 10)
    var vers = cible.classList.contains('presenciumMonterRegle') ? rang - 1 : rang + 1
    if (vers < 0 || vers >= presenciumRegles.length) { return }
    var deplacee = presenciumRegles.splice(rang, 1)[0]
    presenciumRegles.splice(vers, 0, deplacee)
    presenciumRenderRegles()
    presenciumMarkModified()
    return
  }

  /* L'onglet Règles est le moment où l'utilisateur regarde ce tableau : c'est
     donc là que le diagnostic « non lues » doit être posé, et pas seulement au
     chargement de l'équipement. Le coeur restaure l'onglet par un .click()
     programmé 150 ms après le chargement de la page (« let time for plugin
     page! » dit son commentaire) : ce clic-là passe ici aussi. */
  if (event.target.closest('a[href="#regletab"]')) {
    presenciumRenderRegles()
    return
  }

  /* Relire l'équipement quand les règles n'ont pas été chargées. On repasse par
     le coeur plutôt que de relire nous-mêmes : c'est lui qui repose aussi les
     champs, les commandes et le reste, et un tableau de règles juste au milieu
     d'un formulaire vide n'avancerait à rien. */
  if (cible = event.target.closest('#bt_presenciumRechargerRegles')) {
    var idRecharge = presenciumCurrentId()
    if (idRecharge === null) { return }
    if (typeof jeeFrontEnd !== 'undefined' && isset(jeeFrontEnd.pluginTemplate)
        && typeof jeeFrontEnd.pluginTemplate.displayEqlogic === 'function') {
      jeeFrontEnd.pluginTemplate.displayEqlogic(null, idRecharge)
    } else {
      window.location.reload()
    }
    return
  }

  /* ---- journal */
  if (cible = event.target.closest('#bt_presenciumRafraichirJournal')) {
    presenciumRenderJournal()
    presenciumRafraichirVerdict()
    return
  }

  if (cible = event.target.closest('#bt_presenciumViderJournal')) {
    var idJournal = presenciumCurrentId()
    if (idJournal === null) { return }
    /* Le journal est le fichier qui porte toute une campagne de simulation :
       ce qui s'y est passé pendant des jours ne se reconstitue pas. Le
       détruire sur un clic voisin de « Rafraîchir » ne peut pas être
       silencieux. */
    var boutonVider = cible
    jeeDialog.confirm('{{Vider ce journal ? Les entrées déjà écrites — règles jouées, rebonds absorbés, mouvements de présence — sont perdues définitivement, et c\'est sur elles que se relit une campagne de simulation.}}',
      function (reponse) {
        if (reponse !== true) { return }
        presenciumAjax('viderJournal', { id: idJournal }, function () {
          presenciumRenderJournal()
          jeedomUtils.showAlert({ message: '{{Journal vidé.}}', level: 'success' })
        }, { button: boutonVider })
      })
    return
  }

  /* La liste des personnes n'a pas pu être lue : recommencer sans quitter la
     page ni perdre la composition en cours. */
  if (cible = event.target.closest('#bt_presenciumRechargerPersonnes')) {
    presenciumPersonnesEtat = 'vierge'
    presenciumChargerPersonnes()
    return
  }

  /* ---- appliquer le délai que l'analyse recommande */
  if (cible = event.target.closest('.presenciumAppliquerDelai')) {
    var champDelai = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="delai_depart"]')
    if (champDelai !== null) {
      champDelai.jeeValue(cible.getAttribute('data-delai'))
      presenciumMarkModified()
      jeedomUtils.showAlert({ message: '{{Délai posé dans le formulaire. Il faut encore sauvegarder l\'équipement.}}', level: 'success' })
    }
    return
  }

  /* ---- forcer la présence, pour éprouver une règle sans sortir de chez soi */
  if (cible = event.target.closest('#bt_presenciumForcerPresent, #bt_presenciumForcerAbsent, #bt_presenciumAuto')) {
    var idForcage = presenciumCurrentId()
    if (idForcage === null) { return }
    var mode = (cible.id === 'bt_presenciumForcerPresent') ? 'present'
             : (cible.id === 'bt_presenciumForcerAbsent' ? 'absent' : 'auto')
    presenciumAjax('forcer', { id: idForcage, mode: mode }, function () {
      presenciumRafraichirVerdict()
      presenciumRenderJournal()
      jeedomUtils.showAlert({
        message: (mode === 'auto')
          ? '{{La balise reprend la main.}}'
          : '{{Forçage posé. La balise n\'a plus voix au chapitre jusqu\'à ce que vous rendiez la main.}}',
        level: 'success'
      })
    }, { button: cible })
    return
  }

  /* ---- exporter le journal */
  if (cible = event.target.closest('#bt_presenciumExporterJournal')) {
    var idExport = presenciumCurrentId()
    if (idExport === null) { return }
    presenciumAjax('journalCsv', { id: idExport }, function (csv) {
      if (!isset(csv) || String(csv) === '') {
        jeedomUtils.showAlert({ message: '{{Le journal est vide : rien à exporter.}}', level: 'warning' })
        return
      }
      /* Le point d'ordre d'octets en tête : sans lui, les accents d'un journal
         français s'affichent en charabia dans un tableur, et on croit le
         fichier abîmé. */
      var lien = document.createElement('a')
      lien.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }))
      lien.download = 'presencium-' + idExport + '-' + (new Date()).toISOString().substring(0, 10) + '.csv'
      document.body.appendChild(lien)
      lien.click()
      document.body.removeChild(lien)
      URL.revokeObjectURL(lien.href)
    }, { button: cible })
    return
  }

  /* ---- analyser l'historique de la balise */
  if (cible = event.target.closest('#bt_presenciumAnalyser')) {
    var idAnalyse = presenciumCurrentId()
    if (idAnalyse === null) { return }
    var sortieAnalyse = document.getElementById('div_presenciumAnalyse')
    if (sortieAnalyse !== null) {
      sortieAnalyse.innerHTML = ''
      sortieAnalyse.appendChild(presenciumText('div', 'text-muted', '{{Lecture de l\'historique…}}'))
    }
    presenciumAjax('analyser', {
      id: idAnalyse,
      jours: presenciumEntier((document.getElementById('in_presenciumAnalyseJours') || {}).value, 7, 1, 90),
      /* Mêmes bornes que le champ et que presencium::analyserSource. */
      seuil: presenciumEntier((document.getElementById('in_presenciumAnalyseSeuil') || {}).value, 60, 5, 720)
    }, function (resultat) {
      /* Réponse d'un équipement qu'on a quitté entre-temps : ignorée. */
      if (String(presenciumCurrentId(true)) !== String(idAnalyse)) { return }
      presenciumRenderAnalyse(resultat)
    }, {
      button: cible,
      delai: 180000,
      failure: function (message) {
        if (String(presenciumCurrentId(true)) !== String(idAnalyse)) { return }
        if (sortieAnalyse === null) { return }
        sortieAnalyse.innerHTML = ''
        sortieAnalyse.appendChild(presenciumText('div', 'alert alert-warning', String(message || '')))
      }
    })
    return
  }

  /* ---- réévaluation immédiate, si la page en propose le bouton */
  if (cible = event.target.closest('#bt_presenciumEvaluer')) {
    var idEvaluation = presenciumCurrentId()
    if (idEvaluation === null) { return }
    presenciumAjax('evaluer', { id: idEvaluation }, function () {
      presenciumRafraichirVerdict()
      presenciumRenderJournal()
      jeedomUtils.showAlert({ message: '{{Réévaluation faite.}}', level: 'success' })
    }, { button: cible })
    return
  }
})

presenciumContainer.addEventListener('change', function (event) {
  /* Le choix rapide d'une source : il pose la même chose que la loupe. */
  if (event.target.closest('#sel_presenciumSourceRapide')) {
    /* L'intitulé neutre de la liste vaut '' : le sélectionner effaçait la
       source d'une personne configurée, sans un mot et sans l'avoir demandé.
       Pour retirer la commande, il y a le bouton prévu pour ça — ici, on
       remet simplement la liste sur la source en place. */
    if (String(event.target.value) === '') {
      var champSource = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="source"]')
      presenciumAppliquerSource((champSource === null) ? 0 : champSource.jeeValue())
      return
    }
    presenciumAppliquerSource(event.target.value)
    presenciumMarkModified()
    return
  }

  /* Les personnes d'un foyer ne sont pas des .eqLogicAttr : le coeur ne les voit
     pas changer, et sans cela on quitterait la page en perdant la composition
     du foyer sans le moindre avertissement. */
  if (event.target.closest('.presenciumPersonne')) {
    presenciumLireListePersonnes()
    presenciumRenderAvertissements()
    presenciumMarkModified()
    return
  }

  /* La simulation du foyer se lit aussi depuis l'éditeur de règle : si la
     fenêtre est ouverte, son verdict doit suivre la case qu'on vient de
     cocher, sinon elle annonce le contraire de ce qui est à l'écran. */
  if (event.target.closest('#in_presenciumSimulation')) {
    presenciumRegleEditeurSimulationEtat()
    return
  }

  /* Le filtre relance une lecture complète, filtre compris, côté serveur :
     trier les entrées déjà reçues laisserait croire qu'il n'y a que trois
     rebonds alors qu'on n'a lu que les 200 dernières entrées, tous genres
     confondus. */
  if (event.target.closest('#sel_presenciumFiltreJournal')) {
    presenciumRenderJournal()
    return
  }
})
