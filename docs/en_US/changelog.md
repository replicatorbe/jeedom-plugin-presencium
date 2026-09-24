# Changelog

## 1.3

A full review of the plugin, aimed at finding whatever could arm the alarm on
someone who is home — or keep it from arming — without anything saying so. Five
such cases were found and fixed.

**What could arm by mistake**

- **A returning signal is no longer an arrival.** With a non-zero arrival
  delay, a beacon coming back during a pending departure made the person absent
  for the length of that delay: a false departure, and `depart_dernier` with
  it. Someone who was never declared gone now stays present.
- **Editing a household no longer creates a departure.** The "membership
  changed" guard only looked at the member count: removing a present person and
  adding an absent one in the same save produced a departure. Arrivals and
  departures are now computed on members present both before *and* after, and
  another member's real departure in the same minute is no longer lost.
- **A lost source no longer makes anyone leave.** A deleted source command — a
  reinstall of the MQTT plugin is enough — declared the person absent at once,
  without any departure delay. They now keep their last known state, the log
  says so once, and the Health page has a "Lost sources" line.
- **"Test" honours what is on screen.** The rule window read the simulation
  checkboxes on screen, the server read the saved version: ticking "Log without
  executing" without saving, then Test, armed for real. A box ticked on screen
  now forces the test into simulation, and a real test asks for confirmation.
- **A command with no value no longer satisfies a condition.** "Luminosity
  < 50" was true on a sensor never read. An empty value now only matches
  "== empty".

**What could silently keep it from arming**

- **After a cache flush, the departure goes through.** With no date in cache,
  the core dated the signal to the current instant, on every pass: the
  departure delay could never expire. The date is read straight from the cache,
  falling back to when the plugin first saw the signal.
- **A failed action is no longer logged "executed".** Actions were run by their
  human-readable name, through a core path that swallows errors: rename a
  device, and "Arm" stopped firing while the log said otherwise. The command is
  run by its id, a missing command is a "failure", and scenario keywords
  (`wait`, `variable`…) are reported "launched", not "executed".
- **Pending waits survive a restart.** Waits, cooldowns, snapshot and episodes
  ("empty since") lived in Jeedom's cache, only saved from time to time. They
  are written to `data/`, per household.
- **A pause no longer freezes Jeedom.** A `wait` in a rule stopped the cron of
  every plugin for its duration. A rule containing a pause now runs its actions
  in a separate process.

**Also**

- Wider default present values: `1.0`, `home`, `detected`…
- Time "7:5" accepted; a "From = To" range covers the whole day.
- The *Analyse* threshold finally uses the global setting, as 1.2 already
  announced.
- The *Analyse* result no longer lands on another person's page, and no
  "0 min" recommendation is made when no absence was found.
- A condition or action with no command is refused on validation instead of
  vanishing; the "Who" list only offers the household's members.
- The person page shows an active override, and the verdict refreshes itself.
- The log is appended line by line (JSON Lines) instead of being rewritten in
  full on every entry; the old format is converted without loss.
- Scenario actions are no longer flagged as dead references on the Health page.
- An update no longer rewrites the order or generic type of commands.
- Automated checks under PHP 7.4 to 8.4, and a `--statique` mode for the core
  pitfalls; AGPL header on every file.

## 1.2

This release fixes what the log was not saying. Three of the five points come
from an evening spent reading back a real operational log: every time two
timestamps had to be compared by hand to understand what had happened, it meant
the plugin had a sentence to write and was not writing it.

- **The false departure gets its own line, in red.** "Bounce absorbed" is only
  said when the signal comes back *before* the delay expires — the happy case,
  the one where the plugin did its job. When it comes back after, the departure
  has been confirmed, the departure rules have fired and the alarm may have
  armed: the log only wrote a departure, then an arrival ten minutes further
  down, saying nothing of the link between the two. The only case that costs
  something was the only one going unnoticed. An arrival that closes an absence
  shorter than the real-absence threshold now writes its own verdict, with the
  measured gap and the delay that was not enough. That threshold becomes a
  plugin setting, one hour by default, shared with the *Analyse* button which
  had it hard-coded.
- **An override no longer disguises itself as a departure.** "Force absent"
  produced a "so-and-so has left" entry indistinguishable from a real departure
  — while a forced departure can arm the alarm. The gesture is now logged the
  way arming already is, and the switch it causes carries the mode in full.
- **The log says what it covers.** You read it back to conclude — "nothing
  fired all week", "the tag did not bounce" — and those conclusions assume you
  see the whole period. A line now announces how many entries there are, how
  far back they go, and turns into a warning when the log is full: the oldest
  ones have then gone for good, and the campaign starts later than you thought.
  Retention goes from 200 to 1000 entries, two hundred covering only two days
  where the documentation invites you to let it run for several.
- **"For" no longer mixes two quantities.** The detail of a decision announced
  "absent for 5 min" at the very second the departure had been confirmed: the
  word referred to the state, the number to the age of the raw signal. The two
  coincide on arrival and diverge on departures by the whole delay — meaning
  the wrong figure was worth exactly the setting this log is read back to
  choose. "For" is now the instant of the last confirmed change, the one the
  *For* command publishes, and the signal's age stays beside it when it
  differs.
- **The analysis decides in seconds, like the engine.** The *Analyse* button
  kept episodes in rounded minutes and compared with a strict ">", where the
  engine decides in seconds with a ">=". A 15 min 12 s gap became "15", and the
  table announced "no false absence" for a fifteen-minute delay that the plugin
  would have crossed at the 900th second — it recommended exactly the delay
  that lets through the gap it was asked to cover. It is the only figure that
  screen exists to choose.

And three fewer silent failures:

- **Silent tags and stalled departures leave the Health page.** Neither gives
  you any reason to suspect anything — everything keeps working, states are
  published, rules run — and the Health page is only opened on a day you
  already suspect something. They now write, once an hour, into Jeedom's
  message centre, and the message removes itself as soon as the cause stops.
- **The Health page says whether the core cron still runs.** This is the
  failure that stops everything: delays never expire, waits never end, and
  nothing changes on screen since the commands keep their last value, which
  looks right. Thirteen green checks under a stopped cron mean nothing; the
  page's first line now says so.
- **A person's log opens.** It has existed since 1.1, but the tab fell on "this
  device is not a household": the absorbed bounces of a person outside any
  household — the case of every installation that starts up — stayed
  unreadable. The panel now also adapts to the type opened, a person writing
  neither rules nor alarm entries.

## 1.1

This release fixes three silences — situations where the plugin did nothing and
said nothing — and adds what it takes to set a delay from measurements rather
than by guesswork.

- **A new household gathers the people already declared.** It used to start
  empty: you wrote its rules, saved, and nothing ever fired, with no error and
  no message, because a household with no resident is empty at all times.
  Unticking someone takes one click; working out why nothing fires takes an
  evening.
- **Every person keeps their own log.** Presence moves were only written into
  the households containing them: a person belonging to no household — the case
  of every installation that starts up — saw their absorbed bounces vanish
  without a trace, which is exactly what simulation mode is meant to show. The
  Log tab now belongs to both device types.
- **Silent tags are reported.** When a tag's battery dies while the person is
  home, the signal freezes on “present”: presence never moves again, the house
  never becomes empty and the alarm can no longer arm. A new command says how
  long ago the tag was seen, and the Health page picks it up. The plugin never
  flips presence on its own for this reason — declaring absent someone you have
  no news from would arm on a person sitting in their living room. It reports
  it, you decide.
- **Analyze the tag.** A button reads back the followed command's history and
  replays the decision for eight departure delays, with the very function the
  cron uses. The table says, on your data and for that tag, how many false
  absences each delay removes, how many real ones it lets through, and what it
  costs in presence held wrongly. The chosen delay drops into the form with one
  click. Two tags in the same house have no reason to ask for the same setting,
  and that is now measurable.
- **Force presence from the panel.** Both commands existed but stayed hidden:
  to test a rule you had to leave the house.
- **Export the log to CSV.** A week of campaign reads better in a spreadsheet,
  where you sort by verdict and count.
- **People outside any household** are reported on the Health page.
- New **Occupied for** command on the household, symmetrical to *Empty for*:
  the counter already existed, it was simply never published.

## 1.0

First release.

- **People.** One device per person: any information command as input — a
  Bluetooth tag relayed over MQTT, a motion sensor, a phone seen on the
  network — and a presence you can act on as output.
- **A departure confirmation, fifteen minutes by default.** This is what the
  plugin exists for. A tag measured here announced five absences in
  sixty-six minutes — 2 seconds, 4.1 minutes, 5.7 minutes, 7.0 minutes and
  13.6 minutes — while nobody had left, and that **after** the 300 seconds of
  silence the upstream daemon already requires. The real absences in the same
  history last 92, 271 and 548 minutes: fifteen minutes of confirmation absorb
  the five false ones and let the three real ones through.
- **Arrivals, on the other hand, are published immediately.** The asymmetry is
  deliberate: a missed arrival is a door that does not open; an invented
  departure is an alarm arming on somebody sitting in their living room. An
  arrival confirmation in seconds exists all the same, for the motion sensors
  that see the cat go by.
- **No remembered state.** The presence is recomputed from the raw signal and
  the date of its last change: a reboot, an update or a cache flush never
  manufactures a false departure.
- **A presence refreshed on the event and on the minute.** The plugin listens to
  the source command — an arrival shows within the second — and comes round
  every minute to let the delays run out, which nobody comes to announce.
- **Households.** A group of people that publishes what automations care about:
  presence, everyone is home, how many, who, how long the house has
  been empty, who came in first, who left last.
- **The alarm the core no longer has.** Jeedom has had no alarm since v4. So the
  household carries "enabled" and "armed" itself, with the core's alarm generic
  types, which do still exist: voice assistants and the Home view file them
  correctly as of today, and the day a real alarm is installed, the rules will
  drive it through their actions and read it through their conditions without
  changing shape. Both states are saved in the database, not only in the cache:
  an alarm that disarmed itself on a cache flush would be worse than no alarm.
- **Rules, per household.** When this happens, if these conditions are met,
  after so many minutes, do that — and no more than once every so many minutes.
  Seven triggers: the last one leaves, the first one arrives, everyone is home,
  someone arrives, someone leaves, empty for, occupied for.
- **A delay that cancels itself.** If the trigger reverses during the delay, the
  rule does not play: leaving, coming back for your keys and leaving again arms
  nothing.
- **A cooldown per rule**, so that two people coming in thirty seconds apart
  do not produce two notifications.
- **Conditions that read the whole installation**: a time range, days of the
  week, and as many comparisons as you like on any information command in
  Jeedom. They keep the command's id, not its name: renaming a device breaks no
  rule.
- **Actions just like in a scenario**, with their options, and a *Test* button
  that runs them for real and reports failures — the only way to check the chain
  through to the notification that lands on the phone.
- **A simulation mode, at three levels**: the whole plugin, one household, one
  rule. The plugin then does everything except act — delays run out, rules are
  evaluated, rule delays are honored — and it writes down what it would have
  done. It is
  the false-positive detector: let it run a few days, read it back, turn
  simulation off once nothing surprises you.
- **A log per household**, two hundred entries by default, in a file of the
  plugin's own rather than in Jeedom's logs: it survives a reboot and does not
  get drowned. Every decision is there with its trigger, its verdict, its
  conditions one by one and its actions.
- **Bounces are logged too**, and that is half the interest: a “bounce
  absorbed” line every time the raw signal came back before the departure delay
  was over. That is where the sensor's own false positives can be read, and
  nowhere else.
- **Forcing a person present or away**, and handing control back to the sensor:
  the tag left on the kitchen table, the flat phone, the friend sleeping
  over.
- **A Health page** counting what shows nowhere else: the people whose source
  has vanished, the rules with an action that no longer points at any command —
  those fail silently — and whatever is running in simulation, the plugin's
  quietest failure, since everything works and nothing acts.
- **No daemon, no dependency, no network call.**

Room-level presence is not part of this release: the tags also publish the
nearest room and a signal strength per receiver, but that will be built on
proven foundations rather than beside them.
