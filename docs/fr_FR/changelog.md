# Changelog

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
