# Presencium

Savoir qui est à la maison, pour de bon — et agir dessus sans écrire un
scénario.

Le plugin ne parle à aucun matériel : il lit les commandes d'information que
vos autres plugins publient déjà. Une balise Bluetooth remontée par MQTT, un
détecteur de mouvement Zigbee, un téléphone vu par le réseau — tout ce qui dit
« présent » ou « absent » dans Jeedom peut devenir une personne.

Entre ce signal et la décision, il pose ce qui manque partout : un délai de
confirmation, une notion de foyer, des règles qui savent attendre, et un mode
simulation qui vous laisse tout essayer sans rien déclencher.

## Le problème qu'il résout

Un détecteur de présence ne se trompe presque jamais sur l'arrivée. Il se trompe
sur le départ, et il s'y trompe souvent.

Le 19 septembre 2026, entre 11:45 et 12:52, une balise Bluetooth posée dans un
trousseau de clés a changé d'avis **dix fois en soixante-six minutes**. Elle a
annoncé cinq absences : **2 secondes, 4,1 minutes, 5,7 minutes, 7,0 minutes et
13,6 minutes**. Personne n'était sorti de la maison.

Le démon qui remonte ces balises fait pourtant déjà son travail : il attend
**300 secondes de silence** avant de déclarer une absence. Ces dix bascules sont
ce qui reste **après** ce filtre. Une pile qui faiblit, un mur porteur, un sac
posé du mauvais côté de la pièce, et cinq minutes de silence se produisent alors
que la personne est dans son canapé.

Les vraies absences du même historique, elles, durent **92 minutes, 271 minutes
et 548 minutes**. Entre 13,6 minutes et 92 minutes, il y a de la place — et
c'est toute l'idée du plugin.

## Une personne

Une **personne** est un équipement qui prend un signal brut et en publie une
présence sur laquelle on peut décider.

**Plugins → Sécurité → Presencium → Ajouter une personne.** Donnez-lui le nom de
quelqu'un, puis remplissez quatre champs.

**La source.** La commande d'information qui porte le signal brut. Le bouton en
forme de loupe ouvre le sélecteur de commandes de Jeedom ; à côté, une liste
déroulante propose directement les commandes que le plugin a reconnues comme des
commandes de présence dans votre installation. Si votre balise y est, un clic
suffit.

**La valeur « présent ».** Ce que vaut le signal quand la personne est là, `1`
par défaut. Le plugin est souple à la lecture : avec `1` pour valeur présente,
il accepte aussi `on`, `true` et `present`, parce que les passerelles ne
publient pas toutes la même chose.

**La confirmation de départ**, en minutes. C'est le réglage qui compte, et la
section suivante lui est consacrée.

**La confirmation d'arrivée**, en secondes. Zéro par défaut, et c'est presque
toujours le bon réglage.

### Le délai de départ

Quand le signal brut passe à « absent », le plugin ne vous déclare pas absent :
il attend. Si le signal revient avant la fin du délai, il ne s'est rien passé —
la présence n'a pas bougé d'un pouce, et le journal note un rebond absorbé. Si
le délai s'écoule sans que le signal revienne, le départ est confirmé.

**Quinze minutes** par défaut. Ce n'est pas un chiffre rond choisi au hasard :
c'est celui qui sépare les deux familles de la mesure ci-dessus. Les cinq
fausses absences duraient au plus 13,6 minutes ; les trois vraies duraient au
moins 92 minutes. Un délai de quinze minutes absorbe les cinq premières et
laisse passer les trois secondes.

#### Ce que quinze minutes donnent sur de vraies balises

Le plugin a été rejoué sur les 111 heures d'historique réel des deux balises,
minute par minute, pour chaque valeur du délai. Voici ce que chacune aurait
donné au niveau du foyer — c'est-à-dire **combien de fois l'alarme se serait
armée**, et combien de ces armements auraient été des erreurs :

| Délai | Armements | dont erronés | Vide masquée |
|---|---|---|---|
| 0 min | 4 | **2** | 0 min |
| 5 min | 3 | **1** | 20 min |
| 10 min | 3 | **1** | 35 min |
| **15 min** | **2** | **0** | 46 min |
| 30 min | 2 | 0 | 76 min |

Quinze minutes est la plus petite valeur qui ne se trompe jamais. Au-delà, on ne
gagne plus rien et on retarde davantage : trente minutes donnent exactement les
mêmes deux armements, quinze minutes plus tard.

Le prix se paie en deux monnaies. **Chaque armement arrive quinze minutes après
le départ réel** — c'est la définition même du délai, et c'est le retard qu'il
faut accepter. Et sur l'ensemble de la période, 46 minutes de maison réellement
vide n'ont pas été vues comme telles, soit sept dixièmes de pour cent des 111
heures : ce total compte aussi les fausses absences qui n'ont jamais abouti à un
armement, et c'est très exactement le temps que le plugin a refusé de prendre
pour un départ.

#### Et pourquoi il faut quand même observer avant de brancher une sirène

Deux réserves, qui valent d'être dites plutôt que tues.

**La marge est mince.** La plus longue fausse absence mesurée fait 13,6 minutes.
Il s'en faut de 1,4 minute. Un seul décrochage de seize minutes — banal pour du
Bluetooth — suffirait à produire un faux armement.

**Les deux balises ne se valent pas.** Le zéro erreur du tableau doit autant à
la seconde personne qu'au délai : quand l'une décroche, l'autre tient la maison
occupée. Prise seule, la première balise est propre dès quinze minutes ; la
seconde, non — elle a produit deux absences de 22 et 23 minutes qu'aucun délai
de quinze ne rattrape, et il lui faudrait trente minutes pour être aussi sûre. Ces
deux épisodes sont d'ailleurs **indécidables** : une course de vingt minutes et
un décrochage de vingt minutes ont exactement la même signature.

D'où la seule recommandation honnête : **un foyer d'une seule personne, ou une
balise au profil incertain, passe une semaine en mode simulation avant qu'on
lui confie une alarme.** C'est précisément à cela que sert ce mode, et c'est la
seule façon de trancher des épisodes que la théorie ne tranche pas.

À l'inverse, une **arrivée** est publiée tout de suite. L'asymétrie est
délibérée : une arrivée manquée, c'est une porte qui ne s'ouvre pas et qu'on
ouvre à la main ; un départ inventé, c'est une alarme qui s'arme sur quelqu'un
assis dans son salon. Le doute profite à la présence.

La confirmation d'arrivée existe quand même, en secondes, pour le cas inverse :
un détecteur de mouvement dans un couloir voit passer le chat, et trente
secondes de confirmation évitent que la maison se réveille pour lui. Laissez-la
à zéro tant que vous n'avez pas ce problème.

### Ce que la personne publie

| Commande | Ce qu'elle dit |
|---|---|
| **Présence** | La présence confirmée, celle sur laquelle on décide. Historisée. |
| **État** | En clair : « Présent », « Absent », « Départ en cours (7 min) », « Arrivée en cours ». |
| **Signal brut** | Ce que dit la source, sans délai. Créée masquée, historisée : c'est elle qu'on compare à la présence pour voir ce que le délai a absorbé. |
| **Depuis (min)** | Depuis combien de minutes la présence dure. Créée masquée. |
| **Mode** | « Automatique », « Forcé présent » ou « Forcé absent ». Créée masquée. |
| **Forcer présent** / **Forcer absent** / **Suivi automatique** | Trois actions pour reprendre la main. Créées masquées. |

**Départ en cours** est l'état le plus utile de la liste. Il dit, avec le nombre
de minutes restantes, que le signal est retombé et que le plugin n'y croit pas
encore. Sur un tableau de bord, c'est ce qui explique pourquoi rien n'a bougé.

**Forcer présent** et **Forcer absent** servent le jour où le capteur ne peut
pas savoir : une balise oubliée sur la table de la cuisine pendant le week-end,
un téléphone déchargé, quelqu'un qui dort là sans être dans le système.
**Suivi automatique** rend la main au capteur. Le mode reste tel que vous l'avez
mis : il ne se réarme pas tout seul, et la commande **Mode** est là pour qu'un
mode forcé oublié finisse par se voir.

### Le plugin ne mémorise pas où il en était

La présence est recalculée à partir de deux choses seulement : la valeur du
signal brut, et la date de son dernier changement — que Jeedom conserve pour
chaque commande.

C'est ce qui fait qu'un redémarrage de la box, une mise à jour ou un vidage du
cache ne fabriquent jamais de faux départ. Au premier passage suivant, le plugin
relit le signal, lit depuis quand il est là, et retrouve exactement le verdict
qu'il avait — pas celui qu'un état mémorisé et perdu lui aurait fait inventer.

Cela vaut pour la **présence**, qui est l'essentiel. Quelques informations de
confort, elles, ne vivent qu'en cache et repartent de zéro s'il est vidé :
« vide depuis », « premier arrivé », « dernier parti », et le point de
comparaison qui sert à repérer les arrivées et les départs. Aucune décision
d'alarme n'en dépend — au pire, un compteur qui redémarre et une règle
« vide depuis trente minutes » qui recompte ses trente minutes.

### Quand la présence est-elle rafraîchie ?

Deux fois plutôt qu'une.

Le plugin **écoute** la commande source : dès qu'elle change, il est réveillé et
recalcule la personne, puis les foyers qui la contiennent. Une arrivée se voit
dans la seconde.

Et il repasse **chaque minute**, avec le cron du cœur. C'est ce passage-là qui
fait s'écouler les délais : personne ne signale qu'un silence dure depuis quinze
minutes, il faut aller le constater. La conséquence pratique est qu'un départ se
confirme à la minute près, jamais à la seconde.

## Un foyer

Un **foyer** rassemble des personnes et répond à la question qui intéresse les
automatismes : y a-t-il quelqu'un ?

**Plugins → Sécurité → Presencium → Ajouter un foyer.** Cochez les personnes
qui en font partie, et c'est tout ce qu'il faut pour qu'il publie ses états. Les
règles viendront après.

Une même personne peut appartenir à plusieurs foyers — la maison et le bureau —
et un foyer peut n'en contenir qu'une.

| Commande | Ce qu'elle dit |
|---|---|
| **Présence** | Au moins une personne présente. Historisée. C'est la commande à brancher dans vos scénarios. |
| **Occupation** | Combien de personnes sont là. Historisée. |
| **Qui est là** | Leurs noms, en clair. |
| **Tout le monde est là** | Toutes les personnes du foyer sont présentes. Créée masquée, historisée. |
| **État** | « Vide », « Partiel », « Complet ». Créée masquée. |
| **Vide depuis (min)** | Depuis combien de minutes la maison est vide. Créée masquée : c'est elle que lisent les déclencheurs temporels. |
| **Premier arrivé** / **Dernier parti** | Qui a ouvert, qui a fermé. Créées masquées. |
| **Mode simulation** | 1 quand ce foyer est en simulation, pour quelque raison que ce soit. |
| **Réévaluer maintenant** | Force un passage immédiat, sans attendre la minute suivante. Créée masquée. |

Si vous renommez une commande ou changez sa visibilité, le plugin ne vous
contredira pas : il repose le type et le type générique à chaque
enregistrement, jamais le nom ni la visibilité. Ils sont à vous dès que vous y
avez touché.

## L'alarme que le plugin porte

Jeedom n'a plus d'alarme depuis la version 4 : `jeeAlarm` a disparu du cœur, et
rien ne l'a remplacé dans l'installation de base. Or « armer en partant » est
exactement ce qu'on veut faire d'une présence fiable.

Le foyer porte donc lui-même les deux états d'une alarme :

| Commande | Ce qu'elle dit |
|---|---|
| **Alarme en service** | L'interrupteur général. Hors service, plus rien ne doit s'armer. Historisée. |
| **Alarme armée** | L'alarme est armée. Historisée. |
| **Armer** / **Désarmer** | Les deux actions, visibles sur la tuile. |
| **Mettre en service** / **Mettre hors service** | Les deux actions de l'interrupteur général. Créées masquées. |

Ces quatre commandes portent les **types génériques d'alarme du cœur**, qui,
eux, existent toujours. Deux conséquences concrètes : les assistants vocaux, les
widgets et la vue Maison les rangent au bon endroit dès aujourd'hui ; et le jour
où une vraie alarme s'installera, vos règles n'auront pas à changer de forme —
elles piloteront ses commandes d'armement par leurs **actions**, et liront son
état par leurs **conditions**, comme elles le font aujourd'hui avec celles du
foyer.

Les deux états sont enregistrés dans la configuration de l'équipement, et pas
seulement dans la commande : une alarme qui se désarmerait toute seule à un
vidage de cache serait pire que pas d'alarme du tout.

**« En service » ne s'impose pas tout seul** : c'est un état que vous pilotez et
que vos règles lisent. La bonne habitude est de poser la condition « Alarme en
service == 1 » sur toute règle qui arme. C'est ce que fait l'exemple plus bas,
et c'est ce qui permet de suspendre toute la mécanique d'un clic — un ami qui
dort là, des travaux, un samedi où l'on entre et sort vingt fois.

## Les règles

Une règle appartient à un foyer et se lit comme une phrase : **quand** ceci se
produit, **si** ces conditions sont remplies, **après** tant de minutes,
**faire** cela — et ne pas le refaire avant tant de minutes.

Elles se créent dans l'onglet **Règles** du foyer. Elles sont ordonnées,
peuvent être désactivées une par une sans être supprimées, et chacune porte un
nom qui se retrouvera tel quel dans le journal : écrivez-y ce que la règle fait,
pas son numéro.

### Quand — les déclencheurs

| Déclencheur | Il tombe quand… |
|---|---|
| **Le dernier part** | la maison était occupée et devient vide. |
| **Le premier arrive** | la maison était vide et quelqu'un entre. |
| **Tout le monde est là** | la dernière personne manquante arrive. |
| **Quelqu'un arrive** | une personne devient présente — celle que vous désignez, ou n'importe laquelle. |
| **Quelqu'un part** | une personne devient absente — idem. |
| **Vide depuis** | la maison est vide depuis le nombre de minutes que vous donnez. |
| **Occupée depuis** | elle est occupée depuis ce nombre de minutes. |

Les cinq premiers sont des **bascules** : ils ne tombent qu'au moment où l'état
change, jamais tant qu'il dure. Les deux derniers sont des **durées** : ils
tombent une fois, quand le compteur atteint la valeur.

Et tous se fondent sur la présence **confirmée**, pas sur le signal brut. C'est
la raison d'être de tout ce qui précède : « le dernier part » veut dire que
quinze minutes de silence se sont écoulées, pas que la balise a hoqueté.

### Si — les conditions

Trois filtres, cumulables, tous facultatifs :

- **Une plage horaire.** « Entre 22:00 et 06:00 ». Rien à remplir si l'heure
  n'a pas d'importance.
- **Des jours de la semaine.** Les sept cases ; décocher un jour suspend la
  règle ce jour-là.
- **Des lignes de condition.** Chacune compare une commande d'information de
  votre installation à une valeur, avec `==`, `!=`, `>`, `>=`, `<` ou `<=`.
  **Toutes les lignes doivent être vraies** pour que la règle agisse.

Une ligne de condition peut interroger n'importe quoi dans Jeedom, pas seulement
le plugin : l'alarme en service, un mode vacances, une porte de garage, la
présence d'une personne en particulier, la valeur d'une variable. C'est ce qui
évite d'écrire un scénario pour un « sauf si ».

Les conditions retiennent l'**identifiant** de la commande, pas son nom : vous
pouvez renommer un équipement sans casser une règle. Le nom affiché n'est qu'une
étiquette, rafraîchie à l'ouverture.

### Après — l'attente

Une règle peut attendre un nombre de minutes avant d'agir. Et pendant cette
attente, **si le déclencheur s'inverse, la règle est annulée**.

C'est le second filet du plugin, après le délai de départ, et il ne fait pas le
même travail. Le délai de départ répond au capteur qui ment ; l'attente répond à
la vie réelle : on part, on revient parce qu'on a oublié quelque chose, on
repart. Cinq minutes d'attente sur une règle qui arme, et l'aller-retour ne
déclenche rien — le journal note l'attente, puis son annulation.

### Et pas trop souvent — le repos

Après avoir agi, une règle ne peut pas agir de nouveau avant le nombre de
minutes que vous donnez. Sans ce garde-fou, une source instable ou deux
personnes qui entrent à trente secondes d'intervalle peuvent produire deux
déclenchements, et la notification part deux fois. Le journal indique alors la
règle comme étant au repos, avec l'heure de sa dernière action.

### Faire — les actions

Les actions d'une règle sont les mêmes que dans un scénario Jeedom : n'importe
quelle commande d'action de votre installation, avec ses options — un message,
un titre de notification, un niveau de curseur, une couleur. Une règle peut en
enchaîner plusieurs.

Le bouton **Tester** de l'éditeur les exécute pour de vrai, tout de suite, sans
regarder ni les conditions ni l'attente : un bouton d'essai qui ne ferait rien
parce qu'on est mardi serait incompréhensible. Il vous dit quand même si les
conditions étaient remplies, mais il n'en fait pas dépendre l'essai. Deux
précisions qui comptent : **en mode simulation il n'exécute rien non plus** — la
simulation prime sur tout — et l'entrée qu'il laisse dans le journal porte le
verdict *Essai manuel*, pour qu'en la relisant dans trois semaines vous ne la
preniez pas pour un vrai déclenchement. Il rend compte action par action,
et **il dit les échecs** — une commande supprimée, un plugin en panne, une
option manquante. C'est la seule façon de vérifier la chaîne complète jusqu'à la
notification qui arrive sur le téléphone.

Une seule chose l'arrête : la simulation. Tant qu'elle est levée, l'essai rend
compte comme d'habitude et n'exécute rien — c'est le principe même du mode, et
un bouton d'essai qui ferait exception le viderait de son sens.

## Le mode simulation

C'est la fonctionnalité pour laquelle le plugin a été écrit de cette façon, et
elle mérite qu'on s'y arrête.

**En simulation, le plugin fait tout sauf agir.** Les personnes sont suivies,
les délais s'écoulent, les états sont publiés, les règles sont évaluées, les
conditions sont testées, les attentes sont tenues et annulées — et au moment
d'exécuter une action, il écrit dans le journal ce qu'il aurait fait, et ne le
fait pas. Aucune action de règle n'est exécutée, l'alarme ne s'arme pas, la mise
en service ne bouge pas.

### Trois interrupteurs

La simulation s'active à trois endroits, et **il suffit d'un seul** pour qu'elle
s'applique :

| Où | Ce qu'il couvre | À quoi il sert |
|---|---|---|
| **La configuration du plugin** | tout, partout | le grand interrupteur : on gèle l'installation entière pendant qu'on met les règles au point. La page affiche un bandeau tant qu'il est levé, parce qu'un plugin qui n'agit plus sans qu'on sache pourquoi est la pire des pannes. |
| **Le foyer** | toutes ses règles | mettre au point un foyer pendant que l'autre travaille pour de bon. |
| **La règle** | elle seule | essayer une règle neuve au milieu de règles qui tournent. C'est le réglage le plus utile au quotidien. |

### S'en servir pour débusquer les faux positifs

Voici la méthode, et c'est celle qui a produit les chiffres du début de cette
page.

**1. Écrivez vos règles et mettez le foyer en simulation.** Réglez tout comme si
c'était pour de vrai : les mêmes déclencheurs, les mêmes conditions, les mêmes
actions. Ne vous retenez pas d'écrire la règle qui arme l'alarme — c'est
justement celle qu'il faut éprouver.

**2. Vivez normalement pendant quelques jours.** Trois jours valent mieux qu'un :
il faut un week-end, une journée de travail, une soirée où l'on sort.

**3. Relisez le journal.** Deux lectures, avec le filtre de l'onglet Journal.

Sur le filtre **Règles**, vous lisez ce qui se serait passé. Chaque ligne dit
quand, pourquoi, avec quel détail — « première balise absente depuis 16 min, seconde absente
depuis 22 min » — et ce qui aurait été exécuté. Une règle qui aurait armé
l'alarme à 14:10 un mercredi, alors que tout le monde était à la maison, est un
faux positif : vous l'avez attrapé dans un fichier texte au lieu de l'attraper
en rentrant le soir.

Sur le filtre **Présence**, vous lisez le capteur lui-même. Les lignes
**« rebond absorbé »** sont les fois où le signal brut est retombé puis revenu
avant la fin du délai de départ : chacune est une fausse absence que votre délai
a mangée. Elles ne se voient nulle part ailleurs. S'il y en a beaucoup, et que
certaines durent presque le délai entier, montez la confirmation de départ. S'il
n'y en a aucune en une semaine, votre source est meilleure que la moyenne et
vous pouvez la descendre.

**4. Coupez la simulation quand plus rien ne vous surprend.** Un seul clic, et
les mêmes règles agissent enfin. Vous n'avez rien à réécrire : ce que vous avez
lu dans le journal est exactement ce qui va se produire.

Et gardez l'habitude pour la suite : toute règle nouvelle naît avec sa case
**simulation** cochée, vit quelques jours, puis passe en production quand le
journal l'a acquittée.

## Le journal

Chaque foyer tient son propre journal, dans son onglet **Journal** : les deux
cents dernières entrées par défaut, la plus récente en tête, avec un filtre par
genre et un bouton pour le vider.

Il est écrit dans un fichier du plugin, pas dans les logs de Jeedom : il survit à
un redémarrage, il ne se fait pas noyer par le reste de l'installation, et il ne
grossit pas sans fin — les entrées les plus anciennes tombent d'elles-mêmes.

Une entrée de règle porte l'heure, le nom de la règle, le déclencheur en clair,
le verdict, le détail chiffré qui l'explique, la liste des conditions avec leur
résultat une par une, et la liste des actions avec le leur.

| Verdict | Ce qu'il veut dire |
|---|---|
| **déclenchée** | la règle a agi. Les actions disent « exécutée », ou « simulée » si le plugin ne faisait que regarder. |
| **conditions non remplies** | le déclencheur est tombé, une ligne de condition a dit non. Le journal dit laquelle. |
| **hors horaire** | la plage horaire ou le jour de la semaine ne s'y prêtait pas. |
| **en attente** | le compte à rebours de l'attente a démarré. |
| **attente annulée** | le déclencheur s'est inversé avant la fin : quelqu'un est rentré. |
| **repos** | la règle a déjà agi il y a moins que son délai d'anti-répétition. |
| **désactivée** | la règle existe mais sa case est décochée. |
| **échec** | une action n'est pas passée. Le message d'erreur est là. |

Les entrées de genre **présence** sont l'autre moitié de l'intérêt : arrivées,
départs confirmés, et surtout **rebonds absorbés**. Les entrées de genre
**alarme** notent les armements, les désarmements et les mises en service, avec
ce qui les a demandés.

Lire ce journal de temps en temps est le seul entretien que le plugin demande.

## La page Santé

La page **Santé** de Jeedom répond d'un coup d'œil à « est-ce que tout va
bien ? ». Le plugin n'y compte que des choses qui ne se voient pas autrement —
onze lignes, dont aucune n'est décorative :

| Contrôle | Ce qu'il rattrape |
|---|---|
| Personnes suivies, Foyers | le décompte, pour repérer un équipement oublié |
| Personnes sans source | une personne créée puis jamais terminée. Elle a l'air normale et reste absente à vie |
| Sources disparues | la commande a été supprimée depuis |
| Sources qui ne sont pas des informations | un bouton choisi à la place d'un état : la personne reste absente pour toujours |
| Départs en cours bloqués | une personne dont le départ « en cours » dure au-delà de son délai. C'est le symptôme d'une source sans date exploitable — et, quand il apparaît, la maison ne peut plus devenir vide |
| Écouteurs posés | sans écouteur, tout marche encore, mais avec une minute de retard. C'est la panne la plus difficile à voir du plugin |
| Foyers sans personne | un foyer vide en permanence, dont les règles de départ partent dans le vide |
| Dossier de données inscriptible | sans lui, le journal ne s'écrit pas — et une campagne de simulation ne laisse aucune trace |
| Références mortes dans les règles | une action qui ne pointe plus sur rien. Celles-là échouent en silence |
| Mode simulation | ce qui tourne à blanc en ce moment |

Les deux dernières lignes sont les plus utiles à la longue. Une action morte ne
dit rien : la règle part, le journal note « déclenchée », et rien ne se produit.
Et une simulation oubliée est la panne la plus discrète du plugin : tout
fonctionne, le journal se remplit, les états sont justes, et rien n'agit.

## Deux exemples complets

### Armer en partant

La maison se vide, l'alarme s'arme — sauf si vous l'avez mise hors service.

| Réglage | Valeur |
|---|---|
| **Nom** | J'arme en partant |
| **Quand** | Le dernier part |
| **Après** | 5 minutes |
| **Pas plus souvent que** | 10 minutes |
| **Plage horaire** | aucune |
| **Jours** | les sept |
| **Condition** | `[Maison][Foyer][Alarme en service]` `==` `1` |
| **Action** | `[Maison][Foyer][Armer]` |

Ce que chaque ligne empêche :

- **Le dernier part** repose sur la présence confirmée : entre le moment où la
  balise se tait et celui où ce déclencheur tombe, quinze minutes se sont déjà
  écoulées pour chaque personne du foyer.
- **Les 5 minutes d'attente** couvrent l'aller-retour — la lettre à poster, la
  poubelle à sortir. Si quelqu'un revient dans l'intervalle, la règle est
  annulée et le journal l'écrit noir sur blanc.
- **Les 10 minutes de repos** évitent que deux départs rapprochés arment deux
  fois. Armer une alarme déjà armée n'est pas grave ; envoyer deux
  notifications, un peu plus.
- **La condition « en service »** est le seul moyen de dire « pas ce week-end »
  sans toucher à la règle. Un clic sur *Mettre hors service*, et toutes les
  règles qui arment se taisent — le journal les note en « conditions non
  remplies », donc vous savez pourquoi.

### Désarmer en arrivant

Quelqu'un rentre dans une maison vide : on désarme avant qu'il n'ait à y penser.

| Réglage | Valeur |
|---|---|
| **Nom** | Je désarme en arrivant |
| **Quand** | Le premier arrive |
| **Après** | 0 minute |
| **Pas plus souvent que** | 5 minutes |
| **Plage horaire** | aucune |
| **Jours** | les sept |
| **Condition** | `[Maison][Foyer][Alarme armée]` `==` `1` |
| **Action** | `[Maison][Foyer][Désarmer]` |

Ce que chaque ligne empêche :

- **Aucune attente**, contrairement à la règle qui arme. Une arrivée est
  publiée sans délai, et faire patienter un désarmement n'a aucun sens : on
  cherche exactement le contraire.
- **La condition « armée »** évite de désarmer ce qui ne l'était pas, ce qui
  remplirait le journal de lignes sans objet et, avec une vraie alarme, la
  ferait parler pour rien.
- **Les 5 minutes de repos** sont là pour la source : deux personnes qui entrent
  ensemble, ou un signal qui s'ébroue en arrivant, ne doivent produire qu'un
  seul désarmement.

Et le conseil qui vaut pour les deux : écrivez-les avec la **simulation** du
foyer levée, laissez-les vivre trois jours, relisez le journal. Une règle
d'armement qu'on met en production sans l'avoir lue est une règle qui se
présentera à vous un soir, à 22 h, avec une sirène.

## Ce que le plugin ne fait pas

**Il ne dit pas dans quelle pièce vous êtes.** Les balises que ce plugin exploite
publient aussi la pièce la plus proche et une puissance de signal par récepteur ;
le plugin n'y touche pas. La présence par pièce est une autre paire de manches,
et elle sera faite sur des fondations qui tiennent, pas ajoutée à côté.

**Il ne parle à aucun matériel.** Ni Bluetooth, ni MQTT, ni Wi-Fi, ni réseau du
tout. Il lit des commandes d'information que d'autres plugins publient, et c'est
une force : vous pouvez changer de technologie de détection sans rien
reconstruire, en désignant une autre source.

**Ce n'est pas une alarme.** Pas de sirène, pas de temporisation de sortie, pas
de code à taper, pas de liste de détecteurs surveillés. Il porte deux états —
en service, armée — et les actions qui vont avec, en attendant qu'une vraie
alarme prenne la place ; il saura alors la piloter par ses actions et la lire
par ses conditions.

**Il ne fait pas de géolocalisation.** Pas de rayon autour de la maison, pas de
téléphone suivi dehors. Il regarde ce que voient vos capteurs, chez vous.

**Il ne remplace pas les scénarios.** Ce qui se rejoue tous les jours autour de
la présence est dans le plugin ; ce qui dépend d'un événement compliqué reste un
scénario — et ce scénario a tout ce qu'il lui faut, puisque les commandes du
foyer et des personnes sont des commandes Jeedom ordinaires, lisibles et
pilotables.

**Il n'a ni démon, ni dépendance, ni appel réseau.** Tout est en PHP, dans le
cron du cœur, plus une écoute sur les commandes sources.
