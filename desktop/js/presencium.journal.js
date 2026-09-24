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
 * Le journal d'un équipement, l'analyse de l'historique d'une balise et le
 * verdict courant affiché sur la fiche.
 *
 * Chargé avant presencium.js, dont il emploie le socle (presenciumAjax,
 * presenciumText…) : rien n'est appelé ici au chargement, seulement à
 * l'usage.
 */

/* ============================================================== VARIABLES */

/* Le numéro de la dernière lecture du journal demandée. Changer deux fois de
   filtre lance deux requêtes et rien ne garantit l'ordre des réponses : sans
   ce jeton, la plus lente écrase la plus récente et le journal affiché ne
   correspond plus au filtre affiché. */
var presenciumJournalJeton = 0

/* Le rafraîchissement périodique du verdict. Repris d'un chargement du script
   à l'autre pour ne jamais laisser tourner un minuteur orphelin. */
var presenciumMinuteurVerdict = (typeof presenciumMinuteurVerdict !== 'undefined') ? presenciumMinuteurVerdict : null

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
  essai: { classe: 'info', texte: '{{Essai manuel}}' },
  /* La commande suivie est devenue illisible : la personne garde son dernier
     état au lieu de partir, et rien d'autre ne le dirait. */
  source_perdue: { classe: 'warning', texte: '{{Source perdue}}' },
  source_retrouvee: { classe: 'info', texte: '{{Source retrouvée}}' }
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

/* ======================================================= PANNEAU JOURNAL */

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
  /* La forme est dans presencium.css ; seule la couleur du liseré, qui dépend
     du verdict, est posée ici. */
  bloc.style.borderLeftColor = init(presenciumCouleurs[connu.classe], presenciumCouleurs['default'])
  /* Le rebond absorbé est ce qu'on vient chercher dans ce journal : c'est lui
     qui prouve que le délai de départ travaille. Il ne se fond pas dans la
     liste. */
  if (rebond) { bloc.classList.add('presenciumEntreeRebond') }
  /* Le trait discontinu distingue l'essai avant même qu'on lise l'entrée. */
  if (essai) { bloc.classList.add('presenciumEntreeEssai') }

  var entete = document.createElement('div')
  entete.className = 'presenciumEntreeEntete'

  var date = presenciumText('span', 'text-muted presenciumEntreeDate', init(entree.date, ''))
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
  titre.className = 'presenciumEntreeTitre'
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
    listeConditions.className = 'presenciumEntreeListe'
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
    listeActions.className = 'presenciumEntreeListe'
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
      /* Source illisible : l'état affiché n'est plus lu, il est gardé. Sans ce
         badge, « Présent » se lirait comme une balise qui répond. Le drapeau et
         non la raison : un forçage remplace la raison, pas la panne. */
      var perdue = (result.source_perdue === true || result.source_perdue == 1)
      if (perdue) {
        var badgePerdue = presenciumBadge('warning', '{{Source perdue — dernier état gardé}}')
        badgePerdue.style.fontWeight = 'bold'
        conteneur.appendChild(badgePerdue)
      }
      conteneur.appendChild(presenciumBadge('default', '{{signal brut}} : ' + (result.brut ? '1' : '0')))
      if (result.transitoire) {
        conteneur.appendChild(presenciumBadge('warning',
          '{{confirmation en cours}} — ' + presenciumEntier(result.restant, 0, 0, 100000) + ' s'))
      }
      if (perdue) {
        conteneur.appendChild(presenciumText('div', 'text-warning presenciumVerdictDetail',
          '{{La commande suivie ne se lit plus}} (' + String(init(result.source_perdue_raison, '')) + '). '
          + '{{La personne garde son dernier état connu — absente s\'il n\'y en a pas. Rechoisissez sa « Commande de présence ».}}'))
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
