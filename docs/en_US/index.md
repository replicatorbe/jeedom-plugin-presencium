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
also accepts `on`, `true` and `present`, because gateways do not all publish the
same thing.

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
| **Enable** / **Disable** | The two actions of the master switch. Created hidden. |

Those four commands carry the **core's alarm generic types**, which do still
exist. Two concrete consequences: voice assistants, widgets and the Home view
file them in the right place as of today; and the day a real alarm is installed,
your rules will not have to change shape — they will drive its arming commands
through their **actions**, and read its state through their **conditions**, just
as they do today with the household's own.

Both states are saved in the device configuration, and not only in the command:
an alarm that disarmed itself on a cache flush would be worse than no alarm at
all.

**"Enabled" does not enforce itself**: it is a state you drive and your rules
read. The good habit is to put the condition "Alarm enabled == 1" on every rule
that arms. That is what the example further down does, and it is what lets you
suspend the whole machinery with one click — a friend sleeping over, building
work, a Saturday of going in and out twenty times.

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

The first five are **transitions**: they only fire at the moment the state
changes, never while it lasts. The last two are **durations**: they fire once,
when the counter reaches the value.

And all of them are based on the **confirmed** presence, not on the raw signal.
That is what everything above exists for: "the last one leaves" means fifteen
minutes of silence have gone by, not that a tag hiccuped.

### If — the conditions

Three filters, which add up, all optional:

- **A time range.** "Between 22:00 and 06:00". Nothing to fill in if the time of
  day does not matter.
- **Days of the week.** Seven boxes; unticking a day suspends the rule that day.
- **Condition lines.** Each one compares an information command of your
  installation with a value, using `==`, `!=`, `>`, `>=`, `<` or `<=`. **Every
  line must be true** for the rule to act.

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
test button making an exception would empty it of its meaning.

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
you scroll through.

It is written to a file of the plugin's own, not to Jeedom's logs: it survives a
reboot, it does not get drowned by the rest of the installation, and it does not
grow without end — the oldest entries fall off by themselves.

A rule entry carries the time, the rule's name, the trigger in plain words, the
verdict, the figures that explain it, the list of conditions with their result
one by one, and the list of actions with theirs.

| Verdict | What it means |
|---|---|
| **Fired** | the rule acted. The actions read "executed", or "simulated" if the plugin was only watching. |
| **Conditions not met** | the trigger fired, a condition line said no. The log says which one. |
| **Outside the time range** | the time range or the day of the week did not allow it. |
| **Waiting** | the delay's countdown has started. |
| **Delay canceled** | the trigger reversed before the end: somebody came back. |
| **Cooldown** | the rule acted less than its cooldown ago. |
| **Disabled** | the rule exists but its box is unticked. |
| **Failed** | an action did not go through. The error message is there. |

The **presence** entries are the other half of the interest: arrivals, confirmed
departures, and above all **bounces absorbed**. The **alarm** entries record
armings, disarmings and enablings, together with what asked for them.

Reading that log now and then is the only maintenance the plugin asks for.

## The Health page

Jeedom's **Health** page answers "is everything all right?" at a glance. The
plugin counts only things there that do not show anywhere else — fourteen checks,
not one of them decorative:

| Check | What it catches |
|---|---|
| Last evaluation | the core cron no longer runs. This is the failure that stops everything: departure delays never expire, rule waits never end, and the commands keep their last value — which looks right. While this line is red, the other thirteen mean nothing |
| People tracked, Households | the head count, to spot a forgotten device |
| People with no source | a person created and then never finished. It looks perfectly normal and stays absent for life |
| Missing sources | the command has been deleted since |
| Sources that are not info commands | a button picked instead of a state: the person stays absent for ever |
| Stalled departures | a person stuck in "Departure pending" past their delay. It is the symptom of a source with no usable date — and, while it shows, the house can no longer become empty |
| Listeners installed | without a listener everything still works, but one minute late. It is the plugin's hardest failure to see |
| Households with no person | a household that is empty for good, whose departure rules fire into the void |
| Data folder writable | without it the log is not written — and a simulation campaign leaves no trace at all |
| Dead references in the rules | an action that no longer points at anything. Those fail silently |
| Silent tags | a tag that claims to be present but has not emitted for a long time. See below: this is the failure that freezes a house on “occupied” forever |
| People outside any household | they are followed, but no rule can fire on them |
| Simulation mode | what is running without acting right now |

The last two lines are the most useful in the long run. A dead action says
nothing: the rule fires, the log records "Fired", and nothing happens. And a
forgotten simulation is the plugin's quietest failure: everything works, the log
fills up, the states are right, and nothing acts.

## Two complete examples

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

And the advice that holds for both: write them with the household's
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
go with them, until a real alarm takes over; it will then be able to drive it
through its actions and read it through its conditions.

**It does not do geolocation.** No radius around the house, no phone tracked
outdoors. It looks at what your sensors see, at your place.

**It does not replace scenarios.** What repeats every day around presence lives
in the plugin; what depends on a complicated event stays a scenario — and that
scenario has all it needs, since the household's and the people's commands are
ordinary Jeedom commands, readable and drivable.

**It has no daemon, no dependency and no network call.** Everything is in PHP,
in the core's cron, plus a listener on the source commands.
