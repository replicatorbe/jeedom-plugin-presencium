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
 * L'équipement : la fiche d'une personne ou d'un foyer, les fonctions que le
 * cœur appelle (printEqLogic, saveEqLogic, addCmdToTable), les écouteurs de la
 * page, et le socle commun aux trois fichiers (presenciumAjax, presenciumText…).
 *
 * Trois fichiers, chargés par desktop/php/presencium.php dans cet ordre :
 * presencium.journal.js, presencium.regle.js, le gabarit du cœur
 * (plugin.template.js), puis celui-ci EN DERNIER. Le gabarit relit
 * l'équipement dès qu'il s'exécute et appelle printEqLogic à la réponse, s'il
 * existe : ce fichier-ci ne doit donc la définir qu'une fois les deux autres
 * en place, sans quoi elle appellerait des fonctions pas encore chargées.
 *
 * Chaque fichier est traduit sous son propre chemin dans core/i18n : un texte
 * à traduire déplacé d'un fichier à l'autre change de section.
 *
 * Tout est rejoué à chaque navigation (loadPage réexécute les scripts) : les
 * globales sont déclarées avec `var`, jamais `let` ou `const`, qu'une
 * seconde exécution refuserait.
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

/*
 * Les personnes cochées dans le foyer ouvert.
 *
 * Elles vivent ici et non dans les cases à cocher : la liste est dessinée
 * APRÈS une réponse AJAX, et enregistrer avant qu'elle n'arrive relèverait un
 * conteneur vide — le foyer perdrait toutes ses personnes sans un mot.
 */
var presenciumSelectionPersonnes = []

/* Les règles telles que chargées, en JSON : ce qui en diffère n'est pas
   enregistré. */
var presenciumReglesChargees = '[]'

/* 'ok', ou 'echec' quand la découverte des sources n'a pas répondu. */
var presenciumSourcesEtat = 'ok'

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
 * tantôt avec trois à la façon de jQuery (requête, statut, message). Lire le
 * troisième seul perdrait le message dans le premier cas : on retient le
 * premier des trois qui dit quelque chose.
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
     l'autre — une personne s'ouvrirait sur un onglet masqué, panneau vide. */
  if (!estFoyer) {
    presenciumRamenerOnglet(estPersonne ? ['regletab'] : ['regletab', 'journaltab'])
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
    ligne.className = 'presenciumPersonneLigne'

    var label = document.createElement('label')

    var checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    checkbox.className = 'presenciumPersonne'
    checkbox.setAttribute('data-id', String(personne.id))
    checkbox.checked = (presenciumSelectionPersonnes.indexOf(parseInt(personne.id, 10)) !== -1)
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
    ligne.className = 'presenciumPersonneLigne'
    var label = document.createElement('label')
    var checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    checkbox.className = 'presenciumPersonne'
    checkbox.setAttribute('data-id', String(disparues[d]))
    checkbox.checked = true
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
  /* Les règles et la composition du foyer n'appartiennent qu'au foyer : une
     personne n'en garde pas la trace dans sa configuration. Ces deux
     structures dépassent trois niveaux de clés : le cœur ne les ramasse pas
     seul, c'est ici et nulle part ailleurs qu'elles sont posées. */
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
