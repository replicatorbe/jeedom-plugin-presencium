# Changelog

## 1.2

Cette version répare ce que le journal ne disait pas. Trois des cinq points
viennent d'une soirée passée à relire un vrai journal d'exploitation : chaque
fois qu'il a fallu comparer deux horodatages à la main pour comprendre ce qui
s'était passé, c'est que le plugin avait une phrase à écrire et ne l'écrivait
pas.

- **Le faux départ a sa ligne, en rouge.** « Rebond absorbé » ne se dit que
  lorsque le signal revient *avant* la fin du délai — le cas heureux, celui où
  le plugin a fait son travail. Quand il revient après, le départ a été
  confirmé, les règles de départ ont été jouées et l'alarme a pu s'armer : le
  journal n'écrivait qu'un départ, puis une arrivée dix minutes plus loin, sans
  rien dire du lien entre les deux. Le seul cas qui coûte cher était le seul à
  passer inaperçu. L'arrivée qui clôt une absence plus courte que le seuil de
  vraie absence écrit désormais son propre verdict, avec le creux mesuré et le
  délai qui n'a pas suffi. Ce seuil devient un réglage du plugin, une heure par
  défaut, partagé avec le bouton *Analyser* qui le codait en dur.
- **Le forçage ne se déguise plus en départ.** « Forcer absent » produisait une
  entrée « Untel est parti(e) » indiscernable d'un vrai départ — alors qu'un
  départ forcé peut armer l'alarme. Le geste est journalisé comme l'armement
  l'est déjà, et la bascule qu'il provoque porte le mode en toutes lettres.
- **Le journal dit ce qu'il couvre.** On le relit pour conclure — « rien ne
  s'est déclenché de la semaine », « la balise n'a pas rebondi » — et ces
  conclusions supposent qu'on voie toute la période. Une ligne annonce
  désormais le nombre d'entrées, jusqu'où elles remontent, et passe en
  avertissement quand le journal est plein : ce sont alors les plus anciennes
  qui ont disparu, et la campagne commence plus tard qu'on ne croyait. La
  rétention passe de 200 à 1000 entrées, deux cents ne couvrant que deux jours
  là où la documentation invite à laisser tourner plusieurs jours.
- **« Depuis » ne mélange plus deux grandeurs.** Le détail d'une décision
  annonçait « absent depuis 5 min » à la seconde même où le départ venait
  d'être confirmé : le mot se rapportait à l'état, le nombre à l'âge du signal
  brut. Les deux coïncident à l'arrivée et divergent aux départs de tout le
  délai — c'est-à-dire que le chiffre faux valait exactement le réglage qu'on
  relit ce journal pour choisir. « Depuis » est maintenant l'instant du dernier
  changement confirmé, celui que la commande *Depuis* publie, et l'âge du
  signal reste affiché à côté quand il diffère.
- **L'analyse décide en secondes, comme le moteur.** Le bouton *Analyser*
  gardait les épisodes en minutes arrondies et comparait par un « > » strict,
  là où le moteur décide en secondes avec un « >= ». Un creux de 15 min 12 s
  devenait « 15 », et le tableau annonçait « aucune fausse absence » pour un
  délai de quinze minutes que le plugin, lui, aurait franchi à la 900e seconde
  — il recommandait exactement le délai qui laisse passer le creux qu'on lui
  demandait de couvrir. C'est le seul chiffre que cet écran sert à choisir.

Et trois pannes muettes de moins :

- **Les balises muettes et les départs qui n'aboutissent pas sortent de la page
  Santé.** Ces deux-là ne donnent envie de soupçonner personne — tout continue
  de fonctionner, les états sont publiés, les règles tournent — et la page
  Santé ne s'ouvre que le jour où l'on soupçonne déjà quelque chose. Elles
  écrivent maintenant, une fois par heure, dans le centre de messages de
  Jeedom, et le message se retire de lui-même dès que la cause cesse.
- **La page Santé dit si le cron du cœur passe encore.** C'est la panne qui
  arrête tout : les délais n'expirent plus, les attentes ne se terminent plus,
  et rien ne change à l'écran puisque les commandes gardent leur dernière
  valeur, qui a l'air juste. Treize contrôles au vert sous un cron arrêté ne
  veulent rien dire ; la première ligne de la page le dit désormais.
- **Le journal d'une personne s'ouvre.** Il existait depuis la 1.1, mais
  l'onglet tombait sur « cet équipement n'est pas un foyer » : les rebonds
  absorbés d'une personne hors de tout foyer — le cas de toute installation qui
  démarre — restaient illisibles. Le panneau se règle au passage sur le type
  ouvert, une personne n'écrivant ni règle ni alarme.

## 1.1

Cette version répare trois silences — des situations où le plugin ne faisait
rien et ne disait rien — et ajoute de quoi régler un délai sur des mesures
plutôt qu'à l'estime.

- **Un foyer neuf rassemble les personnes déjà déclarées.** Il partait vide :
  on écrivait ses règles, on enregistrait, et rien ne se déclenchait jamais,
  sans erreur ni message, parce qu'un foyer sans habitant est vide en
  permanence. Décocher quelqu'un prend un clic ; comprendre pourquoi rien ne
  part prend une soirée.
- **Chaque personne tient son propre journal.** Les mouvements de présence
  n'étaient écrits que dans les foyers qui la contenaient : une personne
  n'appartenant à aucun foyer — le cas de toute installation qui démarre —
  voyait ses rebonds absorbés disparaître sans laisser de trace, alors que
  c'est précisément ce que le mode simulation doit montrer. L'onglet Journal
  est désormais celui des deux types d'équipement.
- **Les balises muettes sont signalées.** Quand la pile d'une balise meurt
  pendant que la personne est chez elle, le signal reste figé sur « présent » :
  la présence ne bouge plus jamais, la maison ne devient plus jamais vide et
  l'alarme ne peut plus s'armer. Une nouvelle commande dit depuis combien de
  temps la balise a été vue, et la page Santé le relève. Le plugin ne bascule
  jamais la présence de lui-même sur ce motif — déclarer absent quelqu'un dont
  on n'a plus de nouvelles reviendrait à armer sur une personne assise dans son
  salon. Il le signale, vous tranchez.
- **Analyser la balise.** Un bouton relit l'historique de la commande suivie et
  rejoue la décision pour huit délais de départ, avec la fonction même que le
  cron utilise. Le tableau dit, sur vos données et pour cette balise-là,
  combien de fausses absences chaque délai supprime, combien de vraies il
  laisse passer, et ce qu'il coûte en présence tenue à tort. Le délai retenu
  se pose dans le formulaire d'un clic. Deux balises d'une même maison n'ont
  aucune raison de demander le même réglage, et c'est maintenant mesurable.
- **Forcer la présence depuis la fiche.** Les deux commandes existaient mais
  restaient invisibles : pour éprouver une règle, il fallait sortir de chez
  soi.
- **Exporter le journal en CSV.** Une semaine de campagne se relit dans un
  tableur, où l'on trie par verdict et où l'on compte.
- **Les personnes hors de tout foyer** sont relevées en page Santé.
- Nouvelle commande **Occupée depuis** sur le foyer, symétrique de *Vide
  depuis* : le compteur existait déjà, il n'était simplement jamais publié.

## 1.0

Première version.

- **Des personnes.** Un équipement par personne : une commande d'information
  quelconque en entrée — une balise Bluetooth remontée par MQTT, un détecteur de
  mouvement, un téléphone vu par le réseau — et une présence sur laquelle on
  peut décider en sortie.
- **Une confirmation de départ, quinze minutes par défaut.** C'est la raison
  d'être du plugin. Une balise mesurée chez nous a annoncé cinq absences en
  soixante-six minutes — 2 secondes, 4,1 minutes, 5,7 minutes, 7,0 minutes et
  13,6 minutes — alors que personne n'était sorti, et cela **après** les 300
  secondes de silence que le démon amont exige déjà. Les vraies absences du même
  historique durent 92, 271 et 548 minutes : quinze minutes de confirmation
  absorbent les cinq fausses et laissent passer les trois vraies.
- **Les arrivées, elles, sont publiées tout de suite.** L'asymétrie est
  délibérée : une arrivée manquée, c'est une porte qui ne s'ouvre pas ; un
  départ inventé, c'est une alarme qui s'arme sur quelqu'un assis dans son
  salon. Une confirmation d'arrivée en secondes existe tout de même, pour les
  détecteurs de mouvement qui voient passer le chat.
- **Aucun état mémorisé.** La présence est recalculée à partir du signal brut et
  de la date de son dernier changement : un redémarrage, une mise à jour ou un
  vidage de cache ne fabriquent jamais de faux départ.
- **Une présence rafraîchie à l'événement et à la minute.** Le plugin écoute la
  commande source — une arrivée se voit dans la seconde — et repasse chaque
  minute pour faire s'écouler les délais, que personne ne vient annoncer.
- **Des foyers.** Un groupe de personnes qui publie ce qui intéresse les
  automatismes : la présence, tout le monde est là, combien, qui, depuis
  quand la maison est vide, qui est arrivé le premier, qui est parti le dernier.
- **L'alarme que le cœur n'a plus.** Jeedom n'a plus d'alarme depuis la v4. Le
  foyer porte donc lui-même « en service » et « armée », avec les types
  génériques d'alarme du cœur, qui, eux, existent toujours : les assistants
  vocaux et la vue Maison les rangent correctement dès aujourd'hui, et le jour
  où une vraie alarme sera installée, les règles la piloteront par leurs actions
  et la liront par leurs conditions sans changer de forme. Les deux états sont
  enregistrés en base, pas seulement en cache : une alarme qui se désarmerait à
  un vidage de cache serait pire que pas d'alarme.
- **Des règles, par foyer.** Quand ceci arrive, si ces conditions sont remplies,
  après tant de minutes, faire cela — et pas plus d'une fois par tant de
  minutes. Sept déclencheurs : le dernier part, le premier arrive, tout le monde
  est là, quelqu'un arrive, quelqu'un part, vide depuis, occupée depuis.
- **Une attente qui s'annule.** Si le déclencheur s'inverse pendant l'attente,
  la règle ne joue pas : partir, revenir chercher ses clés et repartir
  n'arme rien.
- **Un anti-répétition par règle**, pour que deux personnes entrant à trente
  secondes d'intervalle ne produisent pas deux notifications.
- **Des conditions qui lisent toute l'installation** : plage horaire, jours de
  la semaine, et autant de comparaisons qu'on veut sur n'importe quelle commande
  d'information de Jeedom. Elles retiennent l'identifiant de la commande, pas
  son nom : renommer un équipement ne casse aucune règle.
- **Des actions comme dans un scénario**, avec leurs options, et un bouton
  *Tester* qui les exécute pour de vrai et dit les échecs — c'est la seule façon
  de vérifier la chaîne jusqu'à la notification qui arrive sur le téléphone.
- **Un mode simulation, à trois niveaux** : tout le plugin, un foyer, une règle.
  Le plugin fait alors tout sauf agir — les délais s'écoulent, les règles sont
  évaluées, les attentes sont tenues — et il écrit ce qu'il aurait fait. C'est
  le détecteur de faux positifs : on laisse tourner quelques jours, on relit, on
  coupe la simulation quand plus rien ne surprend.
- **Un journal par foyer**, deux cents entrées par défaut, dans un fichier du
  plugin plutôt que dans les logs de Jeedom : il survit à un redémarrage et ne
  se fait pas noyer. Chaque décision y figure avec son déclencheur, son verdict,
  ses conditions une par une et ses actions.
- **Les rebonds sont journalisés aussi**, et c'est la moitié de l'intérêt : une
  ligne « rebond absorbé » chaque fois que le signal brut est revenu avant la
  fin du délai de départ. C'est là que se lisent les faux positifs de la balise
  elle-même, et nulle part ailleurs.
- **Forcer une personne présente ou absente**, et rendre la main au capteur : la
  balise oubliée sur la table de la cuisine, le téléphone déchargé, l'ami qui
  dort là.
- **Une page Santé** qui compte ce qui ne se voit pas autrement : les personnes
  dont la source a disparu, les règles dont une action ne pointe plus sur aucune
  commande — elles échouent en silence —, et ce qui tourne en simulation, la
  panne la plus discrète du plugin puisque tout fonctionne et que rien n'agit.
- **Ni démon, ni dépendance, ni appel réseau.**

La présence par pièce n'est pas de cette version : les balises publient aussi la
pièce la plus proche et une puissance de signal par récepteur, mais cela se fera
sur des fondations éprouvées plutôt qu'à côté d'elles.
