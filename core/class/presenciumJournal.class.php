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

/*
 * Ce qui vit dans data/ : le journal en JSON Lines, l'état du foyer et les
 * verrous fichiers qui sérialisent le cron et l'écouteur.
 *
 * Trait de la classe presencium, chargé par presencium.class.php : voir
 * l'en-tête de ce fichier-là.
 */
trait presenciumJournal {

    /* ================================================================ JOURNAL */

    /*
     * Le journal est en JSON Lines : une entrée par ligne, la plus ancienne en
     * haut, chaque nouvelle entrée AJOUTÉE en fin de fichier, sans relire les
     * autres. L'ancien format, un tableau JSON, porte l'extension .json : l'un
     * ne peut pas être pris pour l'autre, et l'ancien fichier est converti une
     * fois (voir journalMigrer()).
     */
    public function cheminJournal() {
        return self::dossierDonnees() . '/journal-' . (int) $this->getId() . '.jsonl';
    }

    private function cheminJournalAncien() {
        return self::dossierDonnees() . '/journal-' . (int) $this->getId() . '.json';
    }

    /* data/ n'est jamais écrasé par un déploiement ni par une mise à jour du
     * plugin : c'est la seule raison pour laquelle le journal y vit plutôt que
     * dans un fichier posé à côté de la classe. */
    public static function dossierDonnees() {
        return realpath(__DIR__ . '/../..') . '/data';
    }

    public static function journalTaille() {
        $taille = (int) self::reglageGlobal('journal_taille', self::JOURNAL_TAILLE_DEFAUT);
        return max(self::JOURNAL_TAILLE_MIN, min(self::JOURNAL_TAILLE_MAX, $taille));
    }

    /*
     * Ajoute une entrée en fin de journal, sans relire le fichier.
     *
     * Un cron tué au milieu d'une écriture ne coûte que sa propre ligne,
     * que la lecture écarte : le reste du journal est intact. La rétention
     * (`journal_taille`) est appliquée par cronDaily() ; ici, seulement quand
     * le fichier a doublé, pour qu'un journal très bavard ne grossisse pas
     * toute une journée.
     *
     * Rien de ce qui se passe ici ne doit interrompre une décision : écrire
     * l'histoire est moins important que la faire.
     */
    public function journalAjouter($_entree) {
        try {
            /* Foyers et personnes ont chacun leur journal : une personne qui
             * n'appartient à aucun foyer y garde ses rebonds absorbés, ce que
             * le mode simulation doit précisément montrer. Seul un
             * équipement pas encore enregistré n'en a pas. */
            if ((int) $this->getId() <= 0) {
                return false;
            }
            $entree = is_array($_entree) ? $_entree : array('detail' => (string) $_entree);
            $entree['ts'] = isset($entree['ts']) ? (int) $entree['ts'] : time();
            $entree['date'] = date('Y-m-d H:i:s', $entree['ts']);
            if (!isset($entree['genre'])) {
                $entree['genre'] = 'regle';
            }
            $entree['simulation'] = !empty($entree['simulation']);

            $ligne = self::journalLigne($entree);
            if ($ligne === false) {
                return $this->journalEchec(self::dossierDonnees());
            }

            /* Le verrou reste nécessaire : le retaillage remplace le fichier
             * par rename(), et une ligne ajoutée à l'ancien pendant ce temps
             * partirait avec lui. Il sérialise aussi le cron et l'écouteur. */
            $verrou = $this->journalVerrou();
            try {
                $this->journalMigrer();
                $chemin = $this->cheminJournal();
                $dossier = dirname($chemin);
                if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                    return $this->journalEchec($dossier);
                }
                $nouveau = !file_exists($chemin);
                $poignee = @fopen($chemin, 'a+');
                if ($poignee === false) {
                    return $this->journalEchec($dossier);
                }
                try {
                    $infos = @fstat($poignee);
                    $octets = is_array($infos) ? (int) $infos['size'] : 0;
                    /* Une ligne laissée sans fin par un processus tué
                     * collerait la nôtre à elle : les deux seraient perdues. */
                    if ($octets > 0 && @fseek($poignee, -1, SEEK_END) === 0 && @fread($poignee, 1) !== "\n") {
                        $ligne = "\n" . $ligne;
                    }
                    $ecrits = @fwrite($poignee, $ligne . "\n");
                    @fflush($poignee);
                } finally {
                    @fclose($poignee);
                }
                if ($nouveau) {
                    /* Le cron tourne en www-data, le déploiement sous un autre
                     * compte : sans ce chmod, un journal écrit par l'un devient
                     * illisible pour l'autre. */
                    @chmod($chemin, 0664);
                }
                if ($ecrits !== strlen($ligne) + 1) {
                    return $this->journalEchec($dossier);
                }
                if ($this->journalDeborde($octets + $ecrits)) {
                    $this->journalRetailler();
                }
                return true;
            } finally {
                $this->journalLibererVerrou($verrou);
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Journal :', __FILE__) . ' ' . $e->getMessage());
            return false;
        }
    }

    /* Le verrou d'écriture du journal, sur son propre fichier. Il ne peut pas
     * être celui du foyer : flock() ne se reprend pas dans un même processus —
     * deux fopen() donnent deux poignées, donc deux verrous — et le journal est
     * écrit DEPUIS la section que le verrou de foyer protège. Un seul fichier
     * pour les deux, et le foyer s'attendrait lui-même indéfiniment. */
    private function journalVerrou() {
        return self::verrouFichier($this->cheminJournal() . '.lock');
    }

    private function journalLibererVerrou($_poignee) {
        self::libererVerrou($_poignee);
    }

    /* Le fichier de verrou d'un foyer : un par équipement, pour que deux foyers
     * ne s'attendent pas l'un l'autre sans raison. */
    public function cheminVerrouFoyer() {
        return self::dossierDonnees() . '/foyer-' . (int) $this->getId() . '.lock';
    }

    /*
     * Un verrou exclusif sur un fichier de data/.
     *
     * Un échec de verrouillage rend null et laisse le travail se faire quand
     * même. Une installation où flock n'aboutit pas — data/ sur NFS, dossier
     * appartenant à un autre compte — doit continuer de suivre les présences
     * et d'armer l'alarme, avec un risque de collision, plutôt que de
     * s'arrêter de décider sans que rien ne l'explique.
     */
    private static function verrouFichier($_chemin, $_attendre = true) {
        $dossier = dirname($_chemin);
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return null;
        }
        $poignee = @fopen($_chemin, 'c');
        if ($poignee === false) {
            return null;
        }
        $occupe = 0;
        if (!@flock($poignee, $_attendre ? LOCK_EX : (LOCK_EX | LOCK_NB), $occupe)) {
            @fclose($poignee);
            /* Trois issues et non deux : une poignée, `false` quand le verrou
             * est DÉJÀ TENU par un autre processus, et null quand le
             * verrouillage lui-même n'aboutit pas. L'appelant n'en tire pas la
             * même conclusion — renoncer parce qu'un autre fait déjà le travail
             * n'a rien à voir avec renoncer parce que flock ne marche pas. */
            return ($occupe) ? false : null;
        }
        /* Le cron tourne en www-data, le déploiement sous un autre compte :
         * sans ce chmod, le verrou posé par l'un devient inouvrable par
         * l'autre et la protection disparaît en silence. */
        @chmod($_chemin, 0664);
        return $poignee;
    }

    private static function libererVerrou($_poignee) {
        if (!is_resource($_poignee)) {
            return;
        }
        @flock($_poignee, LOCK_UN);
        @fclose($_poignee);
    }

    /* Une entrée, une ligne. json_encode n'émet jamais de saut de ligne brut :
     * ceux des textes sont échappés. Un octet non UTF-8 est remplacé plutôt
     * que de faire perdre l'entrée entière. */
    private static function journalLigne($_entree) {
        return json_encode($_entree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /*
     * Remplace un fichier de data/ d'un seul coup : fichier temporaire, puis
     * rename(). Un processus tué au milieu laisse l'ancien fichier intact, et
     * un lecteur voit l'ancien ou le nouveau, jamais un mélange. Le temporaire
     * est dans le même dossier — seule condition sous laquelle rename() est
     * atomique — et porte le PID : le cron et l'écouteur écrivent en parallèle.
     */
    private static function fichierRemplacer($_chemin, $_contenu) {
        $dossier = dirname($_chemin);
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return false;
        }
        $temporaire = $_chemin . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporaire, $_contenu) !== strlen($_contenu)) {
            @unlink($temporaire);
            return false;
        }
        /* Voir verrouFichier() : le cron et le déploiement n'ont pas le même
         * compte. */
        @chmod($temporaire, 0664);
        if (!@rename($temporaire, $_chemin)) {
            @unlink($temporaire);
            return false;
        }
        return true;
    }

    /*
     * Le fichier dépasse-t-il deux fois `journal_taille` entrées ?
     *
     * Compter les lignes demanderait de tout relire, ce qu'on évite justement.
     * En deçà de 120 octets par entrée — moins qu'aucune entrée réelle — le
     * fichier ne peut pas avoir doublé, et la réponse ne coûte rien. Au-delà,
     * la longueur moyenne d'une ligne est mesurée sur les 32 premiers Ko.
     */
    private function journalDeborde($_octets) {
        $plafond = 2 * self::journalTaille();
        if ($_octets <= $plafond * 120) {
            return false;
        }
        $debut = @file_get_contents($this->cheminJournal(), false, null, 0, 32768);
        if (!is_string($debut)) {
            return false;
        }
        $fin = strrpos($debut, "\n");
        if ($fin === false) {
            /* Pas une seule ligne complète en 32 Ko : retailler dira la vérité. */
            return true;
        }
        $lignes = substr_count($debut, "\n");
        return ($_octets / (($fin + 1) / $lignes)) > $plafond;
    }

    /*
     * Les $_nombre dernières entrées lisibles, dans l'ordre du fichier (la plus
     * ancienne d'abord), chacune avec sa ligne brute. Les lignes corrompues —
     * tronquées par un processus tué, éditées à la main — sont sautées sans
     * compter : elles ne prennent la place de personne.
     */
    private static function journalDernieres($_chemin, $_nombre) {
        $contenu = @file_get_contents($_chemin);
        if (!is_string($contenu) || $contenu === '') {
            return array();
        }
        $lignes = explode("\n", $contenu);
        unset($contenu);
        $gardees = array();
        for ($i = count($lignes) - 1; $i >= 0 && count($gardees) < $_nombre; $i--) {
            $ligne = trim($lignes[$i]);
            if ($ligne === '') {
                continue;
            }
            $entree = json_decode($ligne, true);
            if (!is_array($entree)) {
                continue;
            }
            $gardees[] = array($ligne, $entree);
        }
        return array_reverse($gardees);
    }

    /* Ramène le fichier à `journal_taille` entrées. À appeler sous le verrou du
     * journal : une ligne ajoutée pendant la réécriture serait perdue. Un
     * fichier déjà à la bonne taille n'est pas réécrit. */
    private function journalRetailler() {
        $chemin = $this->cheminJournal();
        if (!file_exists($chemin)) {
            return true;
        }
        $gardees = self::journalDernieres($chemin, self::journalTaille());
        $contenu = '';
        foreach ($gardees as $paire) {
            $contenu .= $paire[0] . "\n";
        }
        if ($contenu === @file_get_contents($chemin)) {
            return true;
        }
        if (!self::fichierRemplacer($chemin, $contenu)) {
            return $this->journalEchec(dirname($chemin));
        }
        return true;
    }

    /*
     * Convertit une fois l'ancien journal (tableau JSON, le plus récent en
     * tête) en JSON Lines. À appeler sous le verrou du journal.
     *
     * Rien n'est perdu : les anciennes entrées passent AVANT celles que le
     * nouveau fichier aurait déjà reçues, et l'ancien fichier n'est effacé
     * qu'une fois le nouveau écrit — un échec laisse tout en place pour la
     * prochaine fois. Un ancien fichier illisible était déjà lu comme vide : il
     * est mis de côté plutôt qu'effacé, et plus relu.
     */
    private function journalMigrer() {
        $ancien = $this->cheminJournalAncien();
        if (!file_exists($ancien)) {
            return true;
        }
        $entrees = json_decode((string) @file_get_contents($ancien), true);
        if (!is_array($entrees)) {
            @rename($ancien, $ancien . '.illisible');
            return false;
        }
        $contenu = '';
        foreach (array_reverse(array_values($entrees)) as $entree) {
            if (!is_array($entree)) {
                continue;
            }
            $ligne = self::journalLigne($entree);
            if ($ligne !== false) {
                $contenu .= $ligne . "\n";
            }
        }
        $chemin = $this->cheminJournal();
        if (file_exists($chemin)) {
            $existant = @file_get_contents($chemin);
            if (!is_string($existant)) {
                return false;
            }
            if ($existant !== '' && substr($existant, -1) !== "\n") {
                $existant .= "\n";
            }
            $contenu .= $existant;
        }
        if (!self::fichierRemplacer($chemin, $contenu)) {
            return $this->journalEchec(dirname($chemin));
        }
        @unlink($ancien);
        /* Le verrou de l'ancien format : plus personne ne le prend. */
        @unlink($ancien . '.lock');
        return true;
    }

    /* Un journal qui n'arrive pas à s'écrire est la pire des pannes de ce
     * plugin : le mode simulation continue de tourner, l'utilisateur relit son
     * journal au bout de trois jours, il est vide, et il en conclut qu'aucune
     * règle ne s'est déclenchée. Le cas le plus fréquent est un dossier de
     * données appartenant à un autre compte que celui du cron. On le dit une
     * fois par heure dans le journal du plugin, et la page Santé le répète. */
    private function journalEchec($_dossier) {
        $cle = self::CACHE_JOURNAL_ECHEC . $this->getId();
        if (cache::byKey($cle)->getValue('') === '') {
            cache::set($cle, '1', 3600);
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                . sprintf(__('journal non enregistrable dans %s — vérifiez que le dossier appartient à www-data.', __FILE__), $_dossier));
        }
        return false;
    }

    /*
     * La plus récente en tête. Un fichier illisible — tronqué par un disque
     * plein, édité à la main — rend un journal vide plutôt qu'une exception :
     * le journal est une commodité, il ne doit jamais empêcher d'ouvrir la
     * page de l'équipement.
     *
     * Seules les `journal_taille` dernières entrées comptent, filtre ou non,
     * même si le fichier en garde davantage en attendant cronDaily() : la page
     * voit exactement ce qu'elle voyait quand le fichier était tronqué à chaque
     * écriture, avertissement « journal plein » compris.
     */
    public function journalLire($_limite = 200, $_genre = '') {
        try {
            if (file_exists($this->cheminJournalAncien())) {
                $verrou = $this->journalVerrou();
                try {
                    $this->journalMigrer();
                } finally {
                    $this->journalLibererVerrou($verrou);
                }
            }
            $chemin = $this->cheminJournal();
            if (!file_exists($chemin)) {
                return array();
            }
            $entrees = array();
            foreach (array_reverse(self::journalDernieres($chemin, self::journalTaille())) as $paire) {
                $entree = $paire[1];
                if ($_genre !== '' && $_genre !== 'tout'
                    && (!isset($entree['genre']) || $entree['genre'] !== $_genre)) {
                    continue;
                }
                $entrees[] = $entree;
            }
            $limite = max(1, min(self::JOURNAL_TAILLE_MAX, (int) $_limite));
            return array_slice($entrees, 0, $limite);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Journal illisible :', __FILE__) . ' ' . $e->getMessage());
            return array();
        }
    }

    /* L'ancien format aussi : vider le journal, c'est ne rien en laisser. */
    public function journalVider() {
        $verrou = $this->journalVerrou();
        try {
            $ancien = $this->cheminJournalAncien();
            foreach (array($this->cheminJournal(), $ancien, $ancien . '.illisible', $ancien . '.lock') as $chemin) {
                if (file_exists($chemin)) {
                    @unlink($chemin);
                }
            }
        } finally {
            $this->journalLibererVerrou($verrou);
        }
        return true;
    }

    /* La rétention quotidienne (cronDaily), sous le verrou du journal. */
    public function journalTronquer() {
        $verrou = $this->journalVerrou();
        try {
            $this->journalMigrer();
            return $this->journalRetailler();
        } finally {
            $this->journalLibererVerrou($verrou);
        }
    }

    /* ======================================================== ÉTAT DU FOYER */

    /*
     * Ce qu'un foyer doit se rappeler d'un passage à l'autre pour décider
     * juste : le dernier instantané (d'où naissent les fronts), l'épisode en
     * cours (vide_depuis, occupee_depuis, premier arrivé, dernier parti), les
     * attentes, les repos et les épisodes temporels déjà consommés.
     *
     * Pas dans le cache de Jeedom, recopié sur disque de temps en temps
     * seulement : une coupure y perdrait une attente ou en rejouerait une déjà
     * exécutée, et un cache vidé en pleine absence ferait repartir vide_depuis
     * de zéro — donc rejouer les règles que cette absence a déjà déclenchées.
     * Le fichier data/etat-<id>.json est réécrit à chaque CHANGEMENT,
     * atomiquement, sous son propre verrou.
     *
     * Ordre de lecture : le secours en cache (qui n'existe que si le fichier
     * n'a pas pu s'écrire, et est alors plus récent que lui), le fichier,
     * puis les anciennes clés de cache — la migration, faite une fois. Sans
     * rien de tout cela, l'instantané est absent et le foyer observe avant
     * d'agir, comme au premier démarrage.
     */
    private function cheminEtat() {
        return self::dossierDonnees() . '/etat-' . (int) $this->getId() . '.json';
    }

    private static function etatVide() {
        return array('instantane' => null, 'foyer' => null,
                     'attentes' => array(), 'repos' => array(), 'temporel' => array());
    }

    /* Toutes les sections présentes et du bon type, quoi qu'on ait relu. */
    private static function etatNormaliser($_etat) {
        $etat = self::etatVide();
        if (!is_array($_etat)) {
            return $etat;
        }
        foreach (array('instantane', 'foyer') as $section) {
            if (isset($_etat[$section]) && is_array($_etat[$section])) {
                $etat[$section] = $_etat[$section];
            }
        }
        foreach (array('attentes', 'repos', 'temporel') as $section) {
            if (isset($_etat[$section]) && is_array($_etat[$section])) {
                $etat[$section] = $_etat[$section];
            }
        }
        return $etat;
    }

    /* Rend array(état, provenance) : 'secours', 'fichier', 'ancien' ou 'vide'.
     * Lu hors verrou : le fichier est remplacé par rename(), jamais réécrit en
     * place. */
    private function etatCharger() {
        $id = (int) $this->getId();
        $secours = cache::byKey(self::CACHE_ETAT . $id)->getValue(null);
        if (is_array($secours)) {
            return array(self::etatNormaliser($secours), 'secours');
        }
        $chemin = $this->cheminEtat();
        if (file_exists($chemin)) {
            $etat = json_decode((string) @file_get_contents($chemin), true);
            if (is_array($etat)) {
                return array(self::etatNormaliser($etat), 'fichier');
            }
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('état illisible, le foyer observe avant d\'agir', __FILE__));
            return array(self::etatVide(), 'vide');
        }

        $etat = self::etatVide();
        $trouve = false;
        $instantane = cache::byKey(self::CACHE_INSTANTANE . $id)->getValue(null);
        if (is_array($instantane)) {
            $etat['instantane'] = $instantane;
            $trouve = true;
        }
        $foyer = cache::byKey(self::CACHE_FOYER . $id)->getValue(null);
        if (is_array($foyer)) {
            $etat['foyer'] = $foyer;
            $trouve = true;
        }
        foreach ($this->regles() as $regle) {
            $cles = array('attentes' => self::CACHE_ATTENTE, 'repos' => self::CACHE_REPOS, 'temporel' => self::CACHE_TEMPOREL);
            foreach ($cles as $section => $prefixe) {
                $valeur = cache::byKey($prefixe . $id . '::' . $regle['id'])->getValue(null);
                if ($valeur !== null && $valeur !== '' && $valeur !== 0 && $valeur !== '0') {
                    $etat[$section][$regle['id']] = $valeur;
                    $trouve = true;
                }
            }
        }
        return array(self::etatNormaliser($etat), $trouve ? 'ancien' : 'vide');
    }

    /* Une section entière ($_cle null), ou l'une de ses entrées. */
    private function etatValeur($_section, $_cle = null, $_defaut = null) {
        list($etat) = $this->etatCharger();
        $valeur = isset($etat[$_section]) ? $etat[$_section] : null;
        if ($_cle === null) {
            return ($valeur === null) ? $_defaut : $valeur;
        }
        return (is_array($valeur) && isset($valeur[$_cle])) ? $valeur[$_cle] : $_defaut;
    }

    /* Pose une section ($_cle null) ou une entrée ; null efface l'entrée. Une
     * valeur inchangée ne coûte ni verrou ni écriture. */
    private function etatPoser($_section, $_cle, $_valeur) {
        list($etat, $source) = $this->etatCharger();
        $actuelle = ($_cle === null) ? $etat[$_section]
                  : (isset($etat[$_section][$_cle]) ? $etat[$_section][$_cle] : null);
        if ($source === 'fichier' && $actuelle === $_valeur) {
            return true;
        }
        return $this->etatModifier(function ($_etat) use ($_section, $_cle, $_valeur) {
            if ($_cle === null) {
                $_etat[$_section] = $_valeur;
            } elseif ($_valeur === null) {
                unset($_etat[$_section][$_cle]);
            } else {
                $_etat[$_section][$_cle] = $_valeur;
            }
            return $_etat;
        });
    }

    /*
     * Relit, transforme par $_fonction, réécrit — sous le verrou de l'état.
     *
     * Ce verrou-là ne peut être ni celui du foyer ni celui du journal : voir
     * journalVerrou(). $_fonction ne doit donc rien faire qui le reprenne.
     *
     * Si le fichier ne s'écrit pas, l'état part en cache, où etatCharger() le
     * lit en premier. Sans ce secours, un data/ en lecture seule ferait
     * relire chaque minute le même instantané : chaque arrivée serait rejouée
     * à chaque passage.
     */
    private function etatModifier($_fonction) {
        $id = (int) $this->getId();
        if ($id <= 0) {
            return false;
        }
        $chemin = $this->cheminEtat();
        $verrou = self::verrouFichier($chemin . '.lock');
        try {
            list($avant, $source) = $this->etatCharger();
            $apres = self::etatNormaliser($_fonction($avant));
            if ($source === 'fichier' && $apres === $avant) {
                return true;
            }
            $json = json_encode($apres, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false || !self::fichierRemplacer($chemin, $json)) {
                cache::set(self::CACHE_ETAT . $id, $apres, self::CACHE_DUREE);
                $cle = self::CACHE_ETAT . 'echec::' . $id;
                if (cache::byKey($cle)->getValue('') === '') {
                    cache::set($cle, '1', 3600);
                    log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                        . sprintf(__('état non enregistrable dans %s — vérifiez que le dossier appartient à www-data.', __FILE__), dirname($chemin)));
                }
                return false;
            }
            if ($source === 'secours') {
                cache::delete(self::CACHE_ETAT . $id);
            } elseif ($source === 'ancien') {
                /* Migré : les anciennes clés, laissées là, seraient relues le
                 * jour où le fichier disparaîtrait — avec un état périmé. */
                $this->purgerCache();
            }
            return true;
        } finally {
            self::libererVerrou($verrou);
        }
    }

    /* Suppression du foyer : son état part avec lui. */
    private function etatVider() {
        cache::delete(self::CACHE_ETAT . (int) $this->getId());
        $chemin = $this->cheminEtat();
        if (file_exists($chemin)) {
            @unlink($chemin);
        }
    }

    /* Les attentes, repos et épisodes des règles supprimées depuis : personne
     * ne les relira plus. Appelé par cronDaily(). */
    private function etatElaguer() {
        if (!file_exists($this->cheminEtat())) {
            return true;
        }
        $vivantes = array();
        foreach ($this->regles() as $regle) {
            $vivantes[(string) $regle['id']] = true;
        }
        return $this->etatModifier(function ($_etat) use ($vivantes) {
            foreach (array('attentes', 'repos', 'temporel') as $section) {
                foreach (array_keys($_etat[$section]) as $idRegle) {
                    if (!isset($vivantes[(string) $idRegle])) {
                        unset($_etat[$section][$idRegle]);
                    }
                }
            }
            return $_etat;
        });
    }

    /* La conversion des fichiers et clés d'avant, faite d'avance par
     * presencium_update() ; sans elle, elle se ferait au premier passage. */
    public function migrerDonnees() {
        $verrou = $this->journalVerrou();
        try {
            $this->journalMigrer();
        } finally {
            $this->journalLibererVerrou($verrou);
        }
        if ($this->type() === self::TYPE_FOYER) {
            list(, $source) = $this->etatCharger();
            if ($source === 'ancien') {
                $this->etatModifier(function ($_etat) {
                    return $_etat;
                });
            }
        }
        return true;
    }
}
