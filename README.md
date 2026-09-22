# Plugin Jeedom — Presencium

Savoir qui est à la maison, pour de bon, et agir dessus — sans écrire un
scénario, et sans qu'une balise qui hoquette arme l'alarme sur quelqu'un assis
dans son salon.

## Ce qu'il apporte

- **Une présence sur laquelle on peut décider.** Le plugin prend une commande
  d'information quelconque — une balise Bluetooth remontée par MQTT, un
  détecteur de mouvement, un téléphone vu par le réseau — et en publie une
  présence confirmée. Entre les deux, un délai de départ.
- **Quinze minutes de confirmation, et le chiffre est mesuré.** Une balise
  suivie ici a annoncé cinq absences en soixante-six minutes — 2 secondes,
  4,1 minutes, 5,7 minutes, 7,0 minutes et 13,6 minutes — alors que personne
  n'était sorti, et cela *après* les 300 secondes de silence que le démon amont
  exige déjà. Les vraies absences du même historique durent 92, 271 et 548
  minutes. Quinze minutes séparent les deux familles.
- **L'arrivée sans délai, le départ avec.** L'asymétrie est le cœur du plugin :
  une arrivée manquée, c'est une porte qui ne s'ouvre pas ; un départ inventé,
  c'est une alarme qui s'arme sur quelqu'un qui est là.
- **Rien de mémorisé, donc rien à perdre.** La présence est recalculée à partir
  du signal et de la date de son dernier changement. Un redémarrage, une mise à
  jour ou un vidage de cache ne fabriquent jamais de faux départ.
- **Des foyers.** Présence, tout le monde est là, combien, qui, depuis
  quand la maison est vide, qui est arrivé le premier, qui est parti le dernier.
- **L'alarme que le cœur n'a plus.** Jeedom n'a plus d'alarme depuis la v4 : le
  foyer porte « en service » et « armée » lui-même, avec les types génériques
  d'alarme du cœur, qui, eux, existent toujours. Le jour où une vraie alarme
  sera installée, les règles la piloteront par leurs actions et la liront par
  leurs conditions, sans changer de forme.
- **Des règles qui se lisent comme une phrase.** Quand le dernier part, si
  l'alarme est en service, après cinq minutes, armer — et pas plus d'une fois
  par dix minutes. Sept déclencheurs, une plage horaire, des jours, et autant de
  conditions qu'on veut sur n'importe quelle commande de l'installation.
- **Une attente qui s'annule.** Si le déclencheur s'inverse pendant l'attente,
  la règle ne joue pas : partir, revenir chercher ses clés et repartir n'arme
  rien.
- **Un mode simulation à trois niveaux.** Tout le plugin, un foyer, une règle.
  Le plugin fait alors tout sauf agir, et écrit ce qu'il aurait fait. On laisse
  tourner quelques jours, on relit, on coupe la simulation quand plus rien ne
  surprend.
- **Un journal par foyer qui explique.** Le déclencheur en clair, le verdict,
  les conditions une par une, les actions et leur résultat — et une ligne
  « rebond absorbé » chaque fois que le signal est revenu avant la fin du délai.
  C'est là, et nulle part ailleurs, que se lisent les faux positifs du capteur.

## Ce qu'il ne fait pas

Il ne dit pas dans quelle pièce vous êtes. Les balises exploitées ici publient
aussi la pièce la plus proche et une puissance de signal par récepteur ; le
plugin n'y touche pas. La présence par pièce est un autre travail, et elle sera
faite sur des fondations éprouvées plutôt qu'ajoutée à côté d'elles.

Il ne parle à aucun matériel : ni Bluetooth, ni MQTT, ni Wi-Fi, ni réseau du
tout. Il lit les commandes d'information que d'autres plugins publient, et c'est
ce qui permet de changer de technologie de détection en désignant une autre
source.

Ce n'est pas une alarme. Pas de sirène, pas de temporisation de sortie, pas de
code, pas de liste de détecteurs surveillés. Il porte deux états et les actions
qui vont avec, en attendant qu'une vraie alarme prenne la place.

Il ne fait pas de géolocalisation, et il ne remplace pas les scénarios : ce qui
se rejoue tous les jours autour de la présence est dans le plugin, ce qui dépend
d'un événement compliqué reste un scénario — qui a tout ce qu'il lui faut,
puisque les commandes du plugin sont des commandes Jeedom ordinaires.

## Prérequis

Jeedom 4.4 ou plus récent, et **au moins une commande d'information qui dise la
présence** : une balise Bluetooth remontée par une passerelle MQTT, un détecteur
de mouvement, une commande de présence produite par un autre plugin. Le plugin
ne détecte rien par lui-même, il stabilise et décide.

Ni démon, ni dépendance, ni appel réseau.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis :

| Champ | Valeur |
|---|---|
| Utilisateur | `replicatorbe` |
| Dépôt | `jeedom-plugin-presencium` |
| Branche | `master` (stable) ou `beta` |

## Branches

- **`master`** — version stable. Tout commit poussé ici est proposé en mise à
  jour aux utilisateurs, Jeedom identifiant la version par le SHA du dernier
  commit de la branche.
- **`beta`** — développement. C'est la branche par défaut du dépôt.

## Architecture

```
commande source qui change ──► écoute du cœur ──► la personne, puis ses foyers
                                                          │
cron du cœur (chaque minute) ─────────────────────────────┤
                                                          │
                     ┌────────────────────────────────────┘
                     ▼
        presenciumPersonne::evaluer()
            signal brut + date du dernier changement + délais
            → présent | absent | départ en cours | arrivée en cours
                     │
                     ▼
        instantané du foyer, comparé au précédent
                     │
        presenciumRegles::transitions()  → le dernier part, le premier arrive…
                     │
        pour chaque règle : horaire, jours, conditions, attente, repos
                     │
          ┌──────────┴───────────┐
          ▼                      ▼
    simulation ?            actions exécutées
    journal seul            (scenarioExpression)
```

Le verdict de présence est dans `presenciumPersonne`, l'interprétation des
règles dans `presenciumRegles` : **ni l'une ni l'autre ne connaît Jeedom**.
C'est ce qui permet de rejouer hors ligne l'épisode de rebond réellement
mesuré — dix bascules en soixante-six minutes — et de prouver qu'un délai de
quinze minutes absorbe les cinq fausses absences et laisse passer les trois
vraies (`php tests/run.php`).

## Contrôles

```bash
php tests/run.php            # les contrôles hors ligne, sans Jeedom
php tests/check-classes.php  # les pièges du cœur, par réflexion
```

## Outils

```bash
php tools/make-icon.php      # redessine plugin_info/presencium_icon.png
```

## Documentation

- [Français](docs/fr_FR/index.md)
- [English](docs/en_US/index.md)

## Licence

AGPL v3. Voir [LICENSE](LICENSE).
