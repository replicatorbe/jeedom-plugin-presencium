# Presencium

Knowing who is home, for real — and acting on it without writing a single
scenario.

The plugin talks to no hardware: it reads the information commands your other
plugins already publish. A Bluetooth tag relayed over MQTT, a Zigbee motion
sensor, a phone seen on the network — anything in Jeedom that says "present" or
"absent" can become a person.

Between that signal and the decision, it adds what is missing everywhere else: a
confirmation delay, a notion of household, rules that know how to wait, and a
simulation mode that lets you try everything without firing anything.

## The problem it solves

A presence detector hardly ever gets an arrival wrong. It gets departures wrong,
and it gets them wrong often.

On 19 September 2026, between 11:45 and 12:52, a Bluetooth tag sitting on a key
ring changed its mind **ten times in sixty-six minutes**. It announced five
absences: **2 seconds, 4.1 minutes, 5.7 minutes, 7.0 minutes and 13.6 minutes**.
Nobody had left the house.

The daemon that relays those tags does its job already: it waits **300 seconds
of silence** before declaring an absence. Those ten flips are what remains
**after** that filter. A weakening battery, a load-bearing wall, a bag put down
on the wrong side of the room, and five minutes of silence happen while the
person is sitting on the sofa.

The real absences in the same history last **92 minutes, 271 minutes and 548
minutes**. Between 13.6 minutes and 92 minutes there is room to spare — and that
is the whole idea of the plugin.

## A person

A **person** is a device that takes a raw signal and publishes a presence you
can act on.

**Plugins → Security → Presencium → Add a person.** Give it somebody's name,
then fill in four fields.

**The source.** The information command carrying the raw signal. The magnifying
glass button opens Jeedom's command selector; next to it, a drop-down list
offers straight away the commands the plugin has recognized as presence commands
in your installation. If your tag is there, one click is enough.

**The "present" value.** What the signal reads when the person is there, `1` by
default. The plugin is lenient when reading: with `1` as the present value it
also accepts `1.0`, `on`, `true`, `present`, `home`, `detected`, `oui`, `yes`
and their neighbours, regardless of case, because gateways do not all publish
the same thing.

**The departure confirmation**, in minutes. This is the setting that matters,
and the next section is devoted to it.

**The arrival confirmation**, in seconds. Zero by default, and that is almost
always the right setting.

### The departure delay

When the raw signal goes to "absent", the plugin does not declare you absent: it
waits. If the signal comes back before the delay is over, nothing has happened —
the presence has not moved an inch, and the log records a bounce absorbed. If
the delay runs out without the signal coming back, the departure is confirmed.

**Fifteen minutes** by default. This is not a round number picked at random: it
is the one that separates the two families in the measurement above. The five
false absences lasted at most 13.6 minutes; the three real ones lasted at least
92 minutes. A fifteen-minute delay absorbs the first five and lets the other
three through.

#### What fifteen minutes give on real tags

The plugin was replayed over the 111 hours of real history from the two tags,
minute by minute, for every value of the delay. Here is what each one would have
given at household level — that is, **how many times the alarm would have
armed**, and how many of those armings would have been mistakes:

| Delay | Armings | of which wrong | Empty time masked |
|---|---|---|---|
| 0 min | 4 | **2** | 0 min |
| 5 min | 3 | **1** | 20 min |
| 10 min | 3 | **1** | 35 min |
| **15 min** | **2** | **0** | 46 min |
| 30 min | 2 | 0 | 76 min |

Fifteen minutes is the smallest value that never gets it wrong. Beyond that
there is nothing more to gain and more delay to pay: thirty minutes gives
exactly the same two armings, fifteen minutes later.

The price is paid in two currencies. **Every arming comes fifteen minutes after
the real departure** — that is the very definition of the delay, and it is the
lateness one has to accept. And over the period as a whole, 46 minutes of a
genuinely empty house were not seen as such, seven tenths of one percent of the
111 hours: that total also counts the false absences that never led to an
arming, and it is exactly the time the plugin refused to take for a departure.

#### And why you should still watch before wiring up a siren

Two reservations, better said than left unsaid.

**The margin is thin.** The longest false absence measured runs to 13.6 minutes.
That leaves 1.4 minutes to spare. A single sixteen-minute dropout — nothing out
of the ordinary for Bluetooth — would be enough to produce a false arming.

**The two tags are not equal.** The zero errors in the table owe as much to the
second person as to the delay: when one tag drops out, the other keeps the house
occupied. Taken on its own, the first tag is clean from fifteen minutes up;
the second is not — it produced two absences of 22 and 23 minutes that no
fifteen-minute delay catches, and it would need thirty minutes to be just as
safe. Those two episodes are, moreover, **undecidable**: a twenty-minute errand
and a twenty-minute dropout have exactly the same signature.

Hence the only honest recommendation: **a single-person household, or a tag
whose profile is uncertain, spends a week in simulation mode before being
trusted with an alarm.** That is precisely what the mode is for, and it is the
only way to settle episodes that theory does not settle.

#### Setting it on your measurements, not on mine

The figures above are those of two Tile tags, in one house, over five days.
Yours will say something else — and your two tags will already disagree with
each other.

The **Analyze** button on a person's panel therefore does the same work on your
installation. It reads back the followed command's history, cuts the absences
out of it, and replays the decision for eight possible delays — with the very
function the cron uses, so the analysis cannot diverge from what the plugin will
actually do. It returns a table of this shape:

| Delay | Departures | False | Real | Presence held wrongly |
|---|---|---|---|---|
| 0 min | 10 | **6** | 4 / 4 | 0 min |
| 10 min | 6 | **2** | 4 / 4 | 82 min |
| **15 min** | **4** | **0** | **4 / 4** | 108 min |
| 30 min | 4 | 0 | 4 / 4 | 168 min |

The chosen delay is the smallest one that brings the *False* column to zero
without eating into the *Real* column. One click drops it into the form — the
device still has to be saved.

Two settings drive the analysis. The **window**: a week is usually enough, but a
tag that rarely drops out needs to be watched longer. And the duration beyond
which **an absence counts as real**, sixty minutes by default: this is the only
judgement the machine cannot make for you. If you go out shopping for twenty
minutes, it must come down — otherwise those outings will be counted as dropouts
and the proposed delay will be too long.

Sometimes no delay fits. The plugin says so instead of picking one at random: it
means this tag's dropouts last as long as real outings, and no setting repairs
what the signal does not let you tell apart. That is the moment to look at the
tag itself — its battery, its range, how many gateways hear it.

#### When a tag goes silent

There is one failure the departure delay cannot catch, because it does not look
like a departure. If a tag's battery dies **while the person is home**, the
signal freezes on “present”. Presence never moves again, the house never becomes
empty, and the alarm can no longer arm. Nothing fails, nothing is logged.

So the plugin watches the date the tag was last *heard* — which is not the date
it last changed its mind. A Bluetooth tag beats regularly as long as it is seen;
a long silence betrays a dead battery, a lost range or a stopped gateway. Beyond
the threshold set in the plugin's configuration, two hours by default, the
Health page reports it and the *Seen ago* command gives the figure.

And it does not wait for you to open the Health page: once an hour, a
tag gone silent — and a person whose departure never completes — writes a
message into Jeedom's **message centre**, the one whose bell lights up at the
top of the screen. The message removes itself as soon as the tag speaks again.
It names the threshold rather than the elapsed time, because the core does not
rewrite the text of a message already posted: the exact figure is on the *Seen
ago* command and on the Health page, which do recompute on every read.

The plugin **never** flips presence on its own for this reason. Declaring absent
someone you have no news from would arm the alarm on a person sitting in their
living room — exactly what the whole design works to avoid. It reports it, you
decide. And if you want to make a rule of it, the command is there: a condition
on *Seen ago* is enough.

The same principle holds when the **source itself disappears** — the followed
command has been deleted, or its plugin uninstalled. The person keeps their
last state instead of being declared gone, and the Health page reports it on
the *Missing sources* line. All that is left is to pick another source.

#### Testing a rule without leaving home

A person's panel carries three buttons — **Present**, **Absent**, **Hand control
back**. While an override is active, the tag has no say, and both the panel and
the card say so. This is what lets you write a departure rule on a Sunday
afternoon and watch it fire without walking round the block. The override
survives a restart: it is written in the device's configuration, not in a cache.

An **arrival**, on the other hand, is published straight away. The asymmetry is
deliberate: a missed arrival is a door that does not open and that you open by
hand; an invented departure is an alarm arming on somebody sitting in their
living room. When in doubt, presence wins.

The arrival confirmation exists all the same, in seconds, for the opposite case:
a motion sensor in a corridor sees the cat go by, and thirty seconds of
confirmation stop the house from waking up for it. Leave it at zero until you
have that problem.

It only applies to a real arrival, that of a person held to be absent. A signal
that comes back **while a departure is pending** is not an arrival: it simply
cancels the departure, at once, without going through the arrival confirmation
— the person never stopped being present.

### What a person publishes

| Command | What it says |
|---|---|
| **Presence** | The confirmed presence, the one you decide on. Logged. |
| **Status** | In plain words: "Present", "Absent", "Departure pending (7 min)", "Arrival pending". |
| **Raw signal** | What the source says, with no delay. Created hidden, logged: it is what you compare with the presence to see what the delay absorbed. |
| **For (min)** | How many minutes the presence has lasted. Created hidden. |
| **Seen (min) ago** | How many minutes since the source last gave any sign of life. Not to be confused with the previous one: this speaks of the tag, not of the person. Created hidden. |
| **Mode** | "Automatic", "Forced present" or "Forced absent". Created hidden. |
| **Force present** / **Force absent** / **Automatic tracking** | Three actions to take over. Created hidden. |

**Departure pending** is the most useful status of the list. It says, with the
number of minutes left, that the signal has dropped and that the plugin does not
believe it yet. On a dashboard, it is what explains why nothing has moved.

**Force present** and **Force absent** are for the day the sensor cannot know: a
tag left on the kitchen table all weekend, a flat phone, somebody sleeping over
who is not in the system. **Automatic tracking** hands control back to the
sensor. The mode stays as you set it: it does not reset itself, and the **Mode**
command is there so that a forgotten forced mode eventually shows.

### The plugin remembers nothing

The presence is recomputed from two things only: the value of the raw signal,
and the date of its last change — which Jeedom keeps for every command.

That is what makes sure a reboot, an update or a cache flush never manufactures
a false departure. On the very next pass, the plugin reads the signal again,
reads how long it has been there, and finds exactly the verdict it had — not the
one a remembered and lost state would have made it invent.

That holds for the **presence**, which is what matters. A few pieces of comfort
information, on the other hand, live in the cache only and start again from zero
if it is flushed: “empty for”, “first to arrive”, “last to leave”, and the
point of comparison used to spot arrivals and departures. No alarm decision
depends on them — at worst, a counter that restarts and an “empty for thirty
minutes” rule that counts its thirty minutes over again.

What the rules have in progress, on the other hand, is written to disk, in the
plugin's `data/` folder: a running **delay**, an unfinished **cooldown**, an
**episode** already handled by a time-based rule. They survive a reboot as well
as a cache flush: a delay that has started is not lost on the way, and a rule in
cooldown does not start acting again because the box rebooted.

### When is the presence refreshed?

Twice rather than once.

The plugin **listens** to the source command: as soon as it changes, the plugin
is woken up and recomputes the person, then the households that contain them. An
arrival shows within the second.

And it comes round **every minute**, with the core's cron. That pass is what
makes the delays run out: nobody reports that a silence has been going on for
fifteen minutes, somebody has to go and look. The practical consequence is that
a departure is confirmed to the minute, never to the second.

## A household

A **household** gathers people and answers the question automations care about:
is anybody there?

**Plugins → Security → Presencium → Add a household.** Tick the people who
belong to it, and that is all it takes for it to publish its states. The rules
come afterwards.

The same person can belong to several households — the house and the office —
and a household may contain only one.

**Changing a household's members fires nothing.** Adding a person is not an
arrival, removing one is not a departure: no rule fires for the members added or
removed. The other members are not forgotten, though — if one of them really
arrives or leaves at the same moment, that genuine change is kept and its rules
play as usual.

| Command | What it says |
|---|---|
| **Presence** | At least one person present. Logged. This is the command to wire into your scenarios. |
| **Occupancy** | How many people are here. Logged. |
| **Who is home** | Their names, in plain words. |
| **Everyone is home** | Every person of the household is present. Created hidden, logged. |
| **Status** | "Empty", "Partial", "Full". Created hidden. |
| **Empty for (min)** | How many minutes the house has been empty. Created hidden: it is what the time-based triggers read. |
| **Occupied for (min)** | The mirror image, for the occupied house. Created hidden. |
| **First to arrive** / **Last to leave** | Who opened up, who locked up. Created hidden. |
| **Simulation mode** | 1 when this household is in simulation, for whatever reason. |
| **Turn on simulation mode** / **Turn off simulation mode** | Raise and lower the household's simulation, from a scenario or the dashboard. They touch neither the plugin's simulation nor the rules': while the global simulation is on, turning the household's off still executes nothing. Created hidden. |
| **Re-evaluate now** | Forces an immediate pass, without waiting for the next minute. Created hidden. |

If you rename a command or change its visibility, the plugin will not argue: it
sets the type and the generic type again on every save, never the name nor the
visibility. They are yours as soon as you have touched them.

## The alarm the plugin carries

Jeedom has had no alarm since version 4: `jeeAlarm` is gone from the core, and
nothing replaced it in a stock installation. Yet "arm when leaving" is exactly
what one wants to do with a reliable presence.

So the household carries the two states of an alarm itself:

| Command | What it says |
|---|---|
| **Alarm enabled** | The master switch. Disabled, nothing must arm. Logged. |
| **Alarm armed** | The alarm is armed. Logged. |
| **Arm** / **Disarm** | The two actions, visible on the tile. |
| **Night mode** | Only with a linked alarm that has one (see below). |
| **Enable** / **Disable** | The two actions of the master switch. Created hidden. |

The first four carry the **core's alarm generic types**, which do still exist.
Two concrete consequences: voice assistants, widgets and the Home view file
them in the right place as of today; and the day a real alarm is installed,
your rules do not have to change shape: the household is **linked** to it (see
below), its actions then drive the control panel and "Alarm armed" follows its
real state — generic types included, so Google Home, Matter and widgets see the
real alarm's state.

Without a linked alarm, both states are saved in the device configuration, and not only in the command:
an alarm that disarmed itself on a cache flush would be worse than no alarm at
all.

**"Enabled" does not enforce itself**: it is a state you drive and your rules
read. The good habit is to put the condition "Alarm enabled == 1" on every rule
that arms. That is what the example further down does, and it is what lets you
suspend the whole machinery with one click — a friend sleeping over, building
work, a Saturday of going in and out twenty times.

### Linking a real alarm

The day a real alarm is installed, the household must not keep carrying a state
of its own: "Alarm armed" would say 0 on an armed house, and everything that
reads it — a widget, Google Home, a rule — would believe the house open. The
household's **Linked alarm** section turns it into the **front** of the control
panel:

- **"Alarm armed" follows the real state.** A listener on the control panel's
  state command carries it over immediately; the cron reads it again every
  minute (in case the listener got lost), and so does saving the household.
  Arming from the control panel's own app therefore shows on the household and
  is written to its log ("The linked alarm is now armed"). As long as the state
  cannot be read — command deleted, never collected — "Alarm armed" keeps its
  last value: unknown never becomes "disarmed".
- **Arm, Disarm and Night mode send orders.** Each executes the control panel
  command you linked to it, and **nothing else**: the state is not written in
  advance. If the panel refuses to arm (an open door, a faulty zone), "Alarm
  armed" stays at 0, and that is the truth. The household log says which order
  left, to which command, and **who asked for it**: the rule, the user from the
  interface, the scenario — or "a Jeedom command" when Jeedom does not pass it
  on (a plugin, the API).
- **Simulation applies as everywhere else.** In simulation, the order is logged
  with the command that would have been executed, and nothing is sent to the
  panel.
- **A linked command that cannot be found breaks nothing.** The order fails,
  the household log carries a "failure" line saying so, and the scenario or rule
  that asked carries on. The Health page lists what is missing ("Linked
  alarms").
- **"Alarm enabled" stays with the household.** It is still the master switch
  of the household's arming: disabled, Arm and Night mode are refused (Disarm
  always goes through). But disabling **does not disarm the control panel**:
  suspending the rules for a weekend must not open a house you armed by hand
  when leaving.
- **Without a link, nothing changes.** The box is unticked by default, and an
  existing household keeps exactly its former behaviour.

| Setting | Configuration key | Role |
|---|---|---|
| **Link a real alarm** | `alarme_liee` | 0 or 1. Unticked by default. |
| **Control panel armed state** | `alarme_etat` | The info command saying whether the panel is armed. |
| **Armed when the value is** | `alarme_operateur`, `alarme_valeur` | The comparison, the same as rule conditions: `==` `1` by default. |
| **Command for Arm** | `alarme_cmd_armer` | The action command executed by *Arm*. |
| **Command for Disarm** | `alarme_cmd_desarmer` | The action command executed by *Disarm*. |
| **Command for Night mode** | `alarme_cmd_nuit` | Optional. When chosen, it adds a **Night mode** action to the household. |

The **Night mode** command only appears once a command is linked to it. It is
never deleted afterwards — a scenario naming it must not end up pointing at
nothing —: without a link, it refuses cleanly, in the log. It carries no
generic type: `ALARM_SET_MODE` assumes a list of modes the household does not
publish.

#### This house's example: an Ajax control panel

The Ajax control panel is exposed by the **ajaxsiabe** plugin, on its "hub"
device:

| Ajax command | Type | What it says or does |
|---|---|---|
| **Armée** (Armed) | binary info | 1 when fully armed, night or partial |
| **Mode** | text info | Désarmé, Armé, Mode nuit, Armé partiel |
| **Armer** / **Mode nuit** / **Désarmer** | actions | the orders, confirmed by the panel |

The link takes four choices: state **[…][Hub][Armée]** `==` `1`, and the three
actions. Written directly into the household configuration — the numbers are
this installation's, replace them with yours:

```json
{
    "alarme_liee": 1,
    "alarme_etat": 6908,
    "alarme_operateur": "==",
    "alarme_valeur": "1",
    "alarme_cmd_armer": 7002,
    "alarme_cmd_desarmer": 7004,
    "alarme_cmd_nuit": 7003
}
```

The **Mode** info works too, with `!=` `Désarmé`: it is the same comparison as
in a condition line, case-insensitive.

#### What it changes for the rules

Nothing mandatory: a rule driving the control panel directly keeps working. But
it can now be written **with the household's commands**:

| Instead of | Write |
|---|---|
| condition `[…][Hub][Armée]` `==` `0` | condition `[Home][Household][Alarm armed]` `==` `0` |
| action `[…][Hub][Armer]` | action `[Home][Household][Arm]` |
| action `[…][Hub][Désarmer]` | action `[Home][Household][Disarm]` |
| action `[…][Hub][Mode nuit]` | action `[Home][Household][Night mode]` |

Three things gain from it. Orders go through **the household's simulation** —
leaving the household in simulation is enough to send nothing to the panel,
without touching the rules. They go through **"Alarm enabled"** — disabled, no
rule arms, even those that forgot the condition. And the household log says
**which rule** asked for what, where the panel's own log would only see
"Jeedom". Changing control panels one day will only mean redoing the link: the
rules stay as they are.

## The rules

A rule belongs to a household and reads like a sentence: **when** this happens,
**if** these conditions are met, **after** so many minutes, **do** that — and do
not do it again for so many minutes.

They are created in the household's **Rules** tab. They are ordered, can be
disabled one by one without being deleted, and each carries a name that will
show up as such in the log: write what the rule does in it, not its number.

### When — the triggers

| Trigger | It fires when… |
|---|---|
| **The last one leaves** | the house was occupied and becomes empty. |
| **The first one arrives** | the house was empty and somebody comes in. |
| **Everyone is home** | the last missing person arrives. |
| **Someone arrives** | a person becomes present — the one you name, or anybody. |
| **Someone leaves** | a person becomes absent — likewise. |
| **Empty for** | the house has been empty for the number of minutes you give. |
| **Occupied for** | it has been occupied for that number of minutes. |
| **At a fixed time** | the clock reaches one of the times you list (1 to 24, for instance 21:30, 22:00, 22:30) — every day, once per time. |

The first five are **transitions**: they only fire at the moment the state
changes, never while it lasts. The next two are **durations**: they fire once,
when the counter reaches the value.

And those seven are based on the **confirmed** presence, not on the raw signal.
That is what everything above exists for: "the last one leaves" means fifteen
minutes of silence have gone by, not that a tag hiccuped.

#### At a fixed time

The last one stands apart: it does not look at presence at all, it looks at the
clock. It is for what gets decided **at a given time, depending on the state of
the house** — switching the alarm to night mode in the evening, closing the
shutters, turning off what was left on. The state of the house is what the
conditions say ("the house is occupied", "the TV is off"); the trigger only
sets the appointments. See the **Automatic night mode** example below.

- **Once a day per time.** Each time in the list fires only once a day, even if
  the cron and the listener both run in the same minute. The time played is
  remembered in the household's state (`data/`), so it survives a reboot.
- **Five minutes of catch-up, no more.** If Jeedom missed the minute — a late
  cron, a reboot —, the time is played on the next run, provided it happens
  within five minutes. Beyond that it is lost: the house has had time to change,
  and the next time in the list takes over. Two times missed within the same
  window give a single firing.
- **Midnight.** 00:00 and 00:30 belong to the day that begins: they fire every
  night, and a time from the previous day is never caught up after midnight
  (23:59 missed is not played at 00:02).
- **No retroactivity.** A time already past when the rule is saved is not
  played: a rule created at 22:10 waits for 22:30, not 22:00. The same goes for
  a time added to the list afterwards.
- **Disabled or cooling down, the time is used up.** A time is an instant, not
  a state: if the rule is disabled or in its cooldown at 22:00, the log says
  so, and ticking the rule again at 22:02 does not replay 22:00.
- **Nothing reverses.** For the other triggers, a delay is canceled when the
  trigger reverses (somebody comes back). A time does not reverse: the delay
  runs to its end, then the **conditions** are read again. If the house emptied
  in the meantime, it is the condition "Household presence == 1" that says no
  — write it.
- The **Who** field makes no sense here and is not offered. The time range and
  the days remain plain filters; at 00:30 on a Saturday, without a time range,
  it is Saturday.

### If — the conditions

Three filters, which add up, all optional:

- **A time range.** "Between 22:00 and 06:00". Nothing to fill in if the time of
  day does not matter. A time typed without zeros, such as `7:5`, reads as
  07:05; a range whose start and end are equal covers the whole day.
- **Days of the week.** Seven boxes; unticking a day suspends the rule that day.
- **Condition lines.** Each one compares an information command of your
  installation with a value, using `==`, `!=`, `>`, `>=`, `<` or `<=`. **Every
  line must be true** for the rule to act. A line that asks about a command
  **with no value** — deleted, or that has not published anything yet — is
  false whatever the operator; only an `==` comparison with a value left empty
  is then true, and that is the way to write "as long as this command has said
  nothing".

A condition line can ask about anything in Jeedom, not only the plugin: the
alarm being enabled, a holiday mode, a garage door, one particular person's
presence, the value of a variable. That is what saves you from writing a
scenario for an "unless".

Conditions keep the command's **id**, not its name: you can rename a device
without breaking a rule. The name shown is only a label, refreshed when the
page opens.

### After — the delay

A rule may wait a number of minutes before acting. And during that delay, **if
the trigger reverses, the rule is canceled**.

This is the plugin's second safety net, after the departure delay, and it does
not do the same job. The departure delay answers the sensor that lies; the
rule's delay answers real life: you leave, you come back because you forgot
something, you leave again. Five minutes of delay on a rule that arms, and the
round trip fires nothing — the log records the delay, then its cancellation.

### And if it is not the right moment — the retry

When it is time to act — right away, or at the end of the delay —, a rule whose
condition line says no gives up. That is the right behaviour for a condition
describing a lasting situation ("the alarm is not already enabled"), the wrong
one for a condition describing a passing moment.

The typical example: a rule "Someone arrives", with a 10-minute delay, the
conditions "no motion on the South camera", "no motion on the East camera" and
"the door is not locked", and the action "lock the door". If somebody still
walks past a camera at the tenth minute, the rule gives up, and the door is
never locked again.

The **Retry if the conditions are not met** setting gives the rule a new try
every *N* minutes, for a maximum duration (60 minutes by default). With "every
5 min for 60 min", the door is locked at the first try where both cameras are
quiet — at 15, 20 or 45 minutes — and the rule gives up after an hour if the
quiet never comes back.

- The duration counts from the **first failed try**, not from the trigger: a
  10-minute delay followed by an hour of retries does retry for an hour. The
  last try falls within that duration, not after it.
- A retry **is canceled like the delay**: if the trigger reverses — the person
  who arrived leaves again, the house empties again —, the rule stops retrying,
  and the log says so.
- Only the **condition lines** are retried. A time range or a day of the week
  says *when* the rule is allowed to act: a rule that fires outside its range
  does not wait for it to open, and a retry that falls outside it stops there.
- The **cooldown** does not get in the way: it is only set when the rule acts.
  Once the rule has acted, the pending retry is cleared.
- In **simulation**, the retry runs for real — waiting, re-evaluating, logging
  — and only the actions are not sent.
- The **Test** button never retries: it runs the actions whatever the
  conditions say, that is its job.

Every failed try leaves a **Retry scheduled** line in the log, with the
conditions one by one: try after try, you see which one said no. The last
refusal is written **Conditions not met**, mentioning that the rule gave up. An
interval longer than the duration would leave room for no further try: the rule
window refuses it.

For "the house has been empty for" and "the house has been occupied for", the
retry is also the only way to try again during the same absence: without it, a
rule whose conditions are false at the minute the duration is reached waits for
the next absence.

For **At a fixed time**, the retry is the way to say "at 21:30, or as soon as
the last lamp is off". Since a time does not reverse, the series only stops at
the end of its duration — or when the **next time** in the list fires: that one
cancels the running series (the log writes *Delay canceled — the next time takes
over*) and starts a fresh series of its own. So pick a duration shorter than the
gap between two times (25 minutes for times 30 minutes apart): each time then
gets its full series, and the rule never acts twice for the same time.

### And not too often — the cooldown

Having acted, a rule cannot act again before the number of minutes you give.
Without that guard, an unstable source or two people coming in thirty seconds
apart can produce two firings, and the notification goes out twice. The log then
shows the rule as being in cooldown, with the time of its last action.

### Do — the actions

A rule's actions are the same as in a Jeedom scenario: any action command of
your installation, with its options — a message, a notification title, a slider
level, a color. A rule can chain several of them.

The **Test** button in the editor runs them for real, right now, looking at
neither the conditions nor the delay: a test button that did nothing because it
is Tuesday would be baffling. It does tell you whether the conditions were met,
but it does not make the test depend on them. Two points that matter: **in
simulation mode it executes nothing either** — simulation comes before
everything else — and the entry it leaves in the log carries the verdict *Manual
test*, so that when you read it back three weeks later you do not take it for a
real firing. It reports action by action, and **it tells you about failures** —
a deleted command, a broken plugin, a missing option. It is the only way to
check the whole chain through to the notification that lands on the phone.

One single thing stops it: simulation. For as long as it is on, the test reports
as usual and executes nothing — that is the very principle of the mode, and a
test button making an exception would empty it of its meaning. It takes the
simulation **as it is ticked on screen**, on the rule as on the household, even
before you have saved: unticking a box and then testing does not arm the alarm
for real without you meaning it to. And when no simulation applies, it asks for
confirmation before executing.

An action whose command cannot be found — deleted since the rule was written —
is logged as **Failed**, no longer as "executed".

`wait` and `sleep` actions placed in a rule are executed without blocking
Jeedom's cron.

## Simulation mode

This is the feature the plugin was written this way for, and it deserves a stop.

**In simulation, the plugin does everything except act.** People are tracked,
delays run out, states are published, rules are evaluated, conditions are
tested, rule delays are honored and canceled — and when the time comes to run an
action, it writes in the log what it would have done, and does not do it. No
rule action is executed, the alarm does not arm, the master switch does not
move.

### Three switches

Simulation is turned on in three places, and **one is enough** for it to apply:

| Where | What it covers | What it is for |
|---|---|---|
| **The plugin configuration** | everything, everywhere | the master switch: you freeze the whole installation while the rules are being worked out. The page shows a banner for as long as it is up, because a plugin that stops acting without anybody knowing why is the worst kind of failure. |
| **The household** | all of its rules | working out one household while the other one works for real. |
| **The rule** | itself alone | trying a brand new rule in the middle of rules that are running. This is the most useful setting day to day. |

### Using it to flush out false positives

Here is the method, and it is the one that produced the figures at the top of
this page.

**1. Write your rules and put the household in simulation.** Set everything up
as if it were for real: the same triggers, the same conditions, the same
actions. Do not hold back from writing the rule that arms the alarm — that is
precisely the one to put to the test.

**2. Live normally for a few days.** Three days beat one: you need a weekend, a
working day, and an evening out.

**3. Read the log.** Two readings, with the filter in the Log tab.

On the **Rules** filter, you read what would have happened. Every line says
when, why, with what detail — "first tag away for 16 min, second away for 22 min" —
and what would have been executed. A rule that would have armed the alarm at
14:10 on a Wednesday, while everyone was at home, is a false positive: you have
caught it in a text file instead of catching it as you walked in that evening.

On the **Presence** filter, you read the sensor itself. The **“bounce
absorbed”** lines are the times the raw signal dropped and came back before the
departure delay was over: each one is a false absence your delay has eaten. They
show nowhere else. If there are many of them, and some last nearly the whole
delay, raise the departure confirmation. If there is not a single one in a week,
your source is better than most and you can lower it.

**4. Turn simulation off once nothing surprises you any more.** One click, and
the very same rules finally act. You have nothing to rewrite: what you read in
the log is exactly what is going to happen.

And keep the habit afterwards: every new rule is born with its **simulation**
box ticked, lives a few days, and goes into production once the log has
acquitted it.

## The log

Every device keeps its own log, in its **Log** tab: a thousand entries kept by
default, the last two hundred displayed, the most recent first, with a filter by
kind, a button to export it and another to empty it.

A line at the top says what the log covers — how many entries, how far back they
go — and turns into a warning when it is full: the oldest ones have then gone,
and the campaign you are reading back starts later than you think. It is the
kind of detail without which you conclude "nothing fired all week" while only
two days are in front of you.

Households record their rules there, people their presence moves. A person who
belongs to no household yet therefore keeps the trace of their bounces: that is
the first day of the installation, the day you set the delays, and precisely the
day when nothing must be lost.

The **CSV export** serves the simulation campaign: a week of observation reads
better in a spreadsheet, where you sort by verdict and count, than in a web page
you scroll through. Its last column, `declencheur`, says for each entry what
fired it — and, for a fixed-time rule, which of the times: that is how you
count at what time night mode really arms.

It is written to a file of the plugin's own, not to Jeedom's logs: it survives a
reboot, it does not get drowned by the rest of the installation, and it does not
grow without end — the oldest entries fall off by themselves.

A rule entry carries the time, the rule's name, the trigger in plain words — for
a fixed-time rule, the time that fired it, "At 21:30", with a clock in front,
even at the end of a delay or a retry —, the verdict, the figures that explain it, the list of conditions with their result
one by one, and the list of actions with theirs.

| Verdict | What it means |
|---|---|
| **Fired** | the rule acted. The actions read "executed", or "simulated" if the plugin was only watching. |
| **Conditions not met** | the trigger fired, a condition line said no. The log says which one — and, for a rule with a retry, that the new tries are exhausted. |
| **Outside the time range** | the time range or the day of the week did not allow it. |
| **Waiting** | the delay's countdown has started. |
| **Retry scheduled** | a condition line said no, but the rule has a retry: it will try again in the number of minutes shown. |
| **Delay canceled** | the trigger reversed before the end — of the delay or of the retries: somebody came back, or left again. For a fixed-time rule, it is the next time in the list that replaced the running series. |
| **Cooldown** | the rule acted less than its cooldown ago. |
| **Disabled** | the rule exists but its box is unticked. |
| **Failed** | an action did not go through — including a command that can no longer be found. The error message is there. |

The **presence** entries are the other half of the interest: arrivals, confirmed
departures, and above all **bounces absorbed**. The **alarm** entries record
armings, disarmings and enablings, together with what asked for them.

Reading that log now and then is the only maintenance the plugin asks for.

## The Health page

Jeedom's **Health** page answers "is everything all right?" at a glance. The
plugin counts only things there that do not show anywhere else — fifteen checks,
not one of them decorative:

| Check | What it catches |
|---|---|
| Last evaluation | the core cron no longer runs. This is the failure that stops everything: departure delays never expire, rule waits never end, and the commands keep their last value — which looks right. While this line is red, the other fourteen mean nothing |
| People tracked, Households | the head count, to spot a forgotten device |
| People with no source | a person created and then never finished. It looks perfectly normal and stays absent for life |
| Missing sources | the command has been deleted since. The person keeps their last state until another source is picked |
| Sources that are not info commands | a button picked instead of a state: the person stays absent for ever |
| Stalled departures | a person stuck in "Departure pending" past their delay. It is the symptom of a source with no usable date — and, while it shows, the house can no longer become empty |
| Listeners installed | without a listener everything still works, but one minute late. It is the plugin's hardest failure to see |
| Households with no person | a household that is empty for good, whose departure rules fire into the void |
| Data folder writable | without it the log is not written — and a simulation campaign leaves no trace at all |
| Dead references in the rules | an action that no longer points at anything. Those fail silently |
| Fixed-time rules without a time | an enabled "At a fixed time" rule with no valid time. It will never fire, and nothing else says so |
| Silent tags | a tag that claims to be present but has not emitted for a long time. See below: this is the failure that freezes a house on “occupied” forever |
| People outside any household | they are followed, but no rule can fire on them |
| Simulation mode | what is running without acting right now |

The last two lines are the most useful in the long run. A dead action says
nothing: the rule fires, the log records "Fired", and nothing happens. And a
forgotten simulation is the plugin's quietest failure: everything works, the log
fills up, the states are right, and nothing acts.

## Three complete examples

### Arming when leaving

The house empties, the alarm arms — unless you have disabled it.

| Setting | Value |
|---|---|
| **Name** | Arm when leaving |
| **When** | The last one leaves |
| **After** | 5 minutes |
| **No more often than** | 10 minutes |
| **Time range** | none |
| **Days** | all seven |
| **Condition** | `[Home][Household][Alarm enabled]` `==` `1` |
| **Action** | `[Home][Household][Arm]` |

What each line prevents:

- **The last one leaves** rests on the confirmed presence: between the moment
  the tag goes quiet and the moment this trigger fires, fifteen minutes have
  already gone by for each person of the household.
- **The 5 minutes of delay** cover the round trip — the letter to post, the bin
  to take out. If somebody comes back in the meantime, the rule is canceled and
  the log says so in black and white.
- **The 10 minutes of cooldown** keep two departures close together from arming
  twice. Arming an already armed alarm does no harm; sending two notifications
  does a little more.
- **The "enabled" condition** is the only way of saying "not this weekend"
  without touching the rule. One click on *Disable*, and every rule that arms
  falls silent — the log records them as "Conditions not met", so you know why.

### Disarming on arrival

Somebody comes into an empty house: disarm before they have to think about it.

| Setting | Value |
|---|---|
| **Name** | Disarm on arrival |
| **When** | The first one arrives |
| **After** | 0 minutes |
| **No more often than** | 5 minutes |
| **Time range** | none |
| **Days** | all seven |
| **Condition** | `[Home][Household][Alarm armed]` `==` `1` |
| **Action** | `[Home][Household][Disarm]` |

What each line prevents:

- **No delay at all**, unlike the rule that arms. An arrival is published
  without delay, and making a disarming wait makes no sense: what one wants here
  is the exact opposite.
- **The "armed" condition** avoids disarming what was not armed, which would
  fill the log with pointless lines and, with a real alarm, make it speak for
  nothing.
- **The 5 minutes of cooldown** are there for the source: two people coming in
  together, or a signal shaking itself on arrival, must produce one single
  disarming.

### Automatic night mode

In the evening, when the house is ready for bed — somebody is home, the alarm
is not armed yet, the TV and the lamps are off —, the alarm switches to night
mode. Without anyone thinking about it, and without arming it on somebody still
watching a film.

| Setting | Value |
|---|---|
| **Name** | Automatic night mode |
| **When** | At a fixed time — 21:30, 22:00, 22:30, 23:00, 00:00, 00:30 |
| **After** | 0 minutes |
| **Retry** | every 5 minutes, for 25 minutes (optional) |
| **No more often than** | 0 minutes |
| **Time range** | none |
| **Days** | all seven |
| **Conditions** | `[Home][Household][Presence]` `==` `1` |
| | `[Home][Alarm][Armed]` `==` `0` |
| | `[Living room][TV][On]` `==` `0` |
| | `[Living room][Lamps][State]` `==` `0` (one line per lamp) |
| **Action** | `[Home][Alarm][Night mode]` |

With a [linked alarm](#linking-a-real-alarm), the two alarm lines are written
with the household: condition `[Home][Household][Alarm armed]` `==` `0`, action
`[Home][Household][Night mode]` — and night mode then goes through the
household's simulation and "Alarm enabled".

What each line does:

- **Six times rather than one.** Nobody goes to bed at the same time every
  night. At 21:30, if everything is off, the alarm switches to night mode;
  otherwise the rule comes back at 22:00, at 22:30… until 00:30. The first
  appointment where the house is ready is the right one.
- **"Household presence == 1"** keeps an empty house out of night mode — that
  one must be armed for real, by the *Arm when leaving* rule. It is also the
  line that says no if the house empties during a delay: a time does not
  reverse, the conditions decide.
- **"Armed == 0"** means that once the alarm is in night mode, the following
  times do not command it again: they stop on *Conditions not met*, and the
  log says it was the already-armed alarm that said no. That is why no cooldown
  is needed — a cooldown would even get in the way: disarmed at 22:10 to walk
  the dog, the alarm must be able to go back to night mode at 22:30.
- **The TV and the lamps** are the sign that someone is still up. If the living
  room has no state command per lamp, a single line on a command that sums them
  up (a virtual "Living room lamps on") will do.
- **The retry (optional)** keeps the rule watching between two times: at 21:30
  a lamp is still on, the rule tries again at 21:35, 21:40… and switches to
  night mode as soon as it is off, instead of waiting for 22:00. Twenty-five
  minutes, not sixty: the series ends before the next time, which starts a
  series of its own. Without a retry, the rule gives up at the first refusal
  and simply waits for the next time — simpler, and half an hour late at worst.

The log then reads like the evening: "At 21:30 — Retry scheduled (Lamps = 1)",
"At 21:30 — Retry scheduled", "At 21:30 — Fired" at 21:45, then "At 22:00 —
Conditions not met (Armed = 1)", and so on. For this rule even more than for
the others, leave it in simulation for a few evenings: that is when you find
the bedside lamp you had forgotten, or the TV that reports "0" on standby.

The same rule, written directly into the household's configuration (what the
editor does when you click *Apply*, then *Save*):

```json
{
    "id": "r-nuit01",
    "nom": "Automatic night mode",
    "actif": 1,
    "declencheur": "heure",
    "personne": 0,
    "minutes": 0,
    "heures_fixes": ["00:00", "00:30", "21:30", "22:00", "22:30", "23:00"],
    "attente": 0,
    "repos": 0,
    "relance": 5,
    "relance_max": 25,
    "simulation": 1,
    "conditions": {
        "heures": {"actif": 0, "de": "00:00", "a": "23:59"},
        "jours": [1, 2, 3, 4, 5, 6, 7],
        "lignes": [
            {"cmd": 101, "operateur": "==", "valeur": "1", "nom": "[Home][Household][Presence]"},
            {"cmd": 102, "operateur": "==", "valeur": "0", "nom": "[Home][Alarm][Armed]"},
            {"cmd": 103, "operateur": "==", "valeur": "0", "nom": "[Living room][TV][On]"},
            {"cmd": 104, "operateur": "==", "valeur": "0", "nom": "[Living room][Lamps][State]"}
        ]
    },
    "actions": [
        {"cmd": "#[Home][Alarm][Night mode]#", "cmd_id": 105, "options": {}}
    ]
}
```

The `cmd` and `cmd_id` numbers are those of your installation. The times can be
written in any order: they are sorted and de-duplicated when saved.

And the advice that holds for all three: write them with the household's
**simulation** turned on, let them live for three days, read the log. An arming
rule put into production without having been read is a rule that will introduce
itself to you one evening, at 10 p.m., with a siren.

## What the plugin does not do

**It does not tell you which room you are in.** The tags this plugin feeds on
also publish the nearest room and a signal strength per receiver; the plugin
does not touch them. Room-level presence is another matter entirely, and it will
be built on foundations that hold rather than bolted alongside them.

**It talks to no hardware.** No Bluetooth, no MQTT, no Wi-Fi, no network at all.
It reads information commands that other plugins publish, and that is a
strength: you can change detection technology without rebuilding anything, just
by naming another source.

**It is not an alarm.** No siren, no exit delay, no code to type, no list of
watched detectors. It carries two states — enabled, armed — and the actions that
go with them; when a real alarm is there, it links to it, passes its orders on
and follows its state.

**It does not do geolocation.** No radius around the house, no phone tracked
outdoors. It looks at what your sensors see, at your place.

**It does not replace scenarios.** What repeats every day around presence lives
in the plugin; what depends on a complicated event stays a scenario — and that
scenario has all it needs, since the household's and the people's commands are
ordinary Jeedom commands, readable and drivable.

**It has no daemon, no dependency and no network call.** Everything is in PHP,
in the core's cron, plus a listener on the source commands.
