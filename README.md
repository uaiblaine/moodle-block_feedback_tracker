# Feedback Flow

**Feedback Flow** helps teaching teams visualize — and value — how efficiently students receive feedback on their work, measured fairly in *working time* rather than raw calendar days.

## Why Feedback Flow?

Timely feedback is one of the biggest drivers of student learning, but it is often hard to evaluate at a glance how a course is doing. Counting raw elapsed time can be misleading: it inadvertently penalizes educators for nights, weekends, holidays, and term breaks when grading is naturally paused.

Feedback Flow measures the turnaround time between a student submitting work and a teacher evaluating it using **academic business time**. It automatically pauses the clock outside of working hours, ensuring the resulting **Academic Responsiveness Score** is an encouraging indicator that rewards consistency, rather than a rigid stopwatch that adds unnecessary pressure.

## What it does

- **Academic Responsiveness Score (0–100)** for each class or group, featuring a clear status band (Excellent, Good, …) and a plain-language explanation of how the score was calculated.
- **A fair "working-time" clock** — pauses automatically on weekends, holidays, recesses, and outside configured hours, following an academic calendar you control.
- **A course block** showing each group's score, typical turnaround time, and the volume of submissions currently awaiting review.
- **A teacher dashboard** with a clean overview across all courses, a priority list for upcoming grading, and supportive insights (e.g., most improved, items requiring attention).
- **A pending-grading report** outlining what is in the queue, what has been completed, and a 30-day historical view of grading momentum.
- **Optional peer context** allowing teachers to anonymously compare their responsiveness against department averages.
- **Optional score simulator** — a safe sandbox to understand how different scenarios affect the score, without altering real data.

Only work that students have explicitly **submitted** triggers the system, and Feedback Flow only runs in courses where you have added the block — giving you total control over when to opt in.

## Screenshots

*Moodle with the Boost theme and a demo term: five courses, one teacher, about four hundred submissions.*

### Teacher dashboard

![Teacher dashboard: overall score, upcoming pause, insights, the three submissions to grade first and a table of the teacher's courses](docs/screenshots/teacher-dashboard.png)

One page across every course the teacher teaches. The top card gives their Academic Responsiveness Score with the typical feedback time in working hours, the wait as students perceive it in calendar days, and the trend against the previous week. A pause from the academic calendar is announced a few days ahead. **Insights** name the group doing best, the one improving fastest and the one that needs a look; **Grade now** picks the three submissions that have waited longest in working time, each with a link straight to it. The course table ends in a 14-day sparkline per course.

### The block in a course

<p>
<img src="docs/screenshots/course-page.png" alt="A course page with the Feedback Flow block open in the block drawer" width="68%">
&nbsp;
<img src="docs/screenshots/course-block.png" alt="Close-up of the block: course score, upcoming pause and the card of one group" width="28%">
</p>

Added to a course, the block shows the course score and one card per group. A card holds the group's score, effective and perceived feedback time, the share answered within the goal, the 14-day trend, and the waiting, attention and priority counts. Below them are the group's assignments with their open and due dates; a teacher who can manage overrides gets a link to give the group dates of its own. **Peer context** compares the group's score with the median of every group on the site and with the top 10%, and **Open pending list** leads to the course report.

### Course report

![Pending grading report for one course: score with SLA chips, the last 30 academic days, distribution by status and the list of submissions waiting](docs/screenshots/course-report.png)

The pending-grading report for one course or group. The score card adds how many submissions were answered within the goal and how many are at risk or critical; the strip of the last 30 academic days shows which days met the goal and which were paused (weekends, holidays, recesses). The status bands filter the table, which lists every submission still waiting, longest wait first, with its effective and perceived time and a **Grade** button. **Graded** switches the table to the work already answered.

## What counts as a response

The clock stops when the feedback **reaches the student**, not when a teacher types it. Two things follow from that, and both are worth knowing before you read the numbers.

**Grading in the gradebook counts.** A mark entered straight into the grader report, single view, or a grade import closes the clock just as marking inside the assignment does — the student sees it either way. Whichever came first is the one recorded, and it is never taken back: deleting the grade afterwards does not re-open a response the student already received. Rows answered this way carry a **"Graded in gradebook"** tag on the pending report, because the assignment's own grading screen shows nothing that would explain them.

**A hidden grade has not reached anybody.** If the gradebook hides the grade — the item hidden, or a *hidden until* date still in the future — the student sees no grade, no feedback, and gets no notification. So a grade entered in the gradebook while hidden does not count as a response at all.

There is one exception, and it is deliberate: **a mark made inside the assignment still stops the clock even while the gradebook hides it.** That is how the plugin has always behaved, and changing it would move figures that have already been reported. Instead those rows are flagged on the pending report with a **"Hidden from student"** tag telling you the grade still needs to be released. If your courses hold results back by hiding a column, expect to see that tag — it is pointing at real work left to do, not at an error.

## Requirements

- Moodle 5.1 (this branch, `MOODLE_501_STABLE`; `main` and `MOODLE_502_STABLE` are the Moodle 5.2 release)
- PHP 8.2 or later
- PostgreSQL or MariaDB

## Installation

1. Copy the plugin into `<moodle>/blocks/feedback_tracker`.
2. Visit *Site administration → Notifications* (or run `php admin/cli/upgrade.php`) to complete the installation.
3. Review the settings under *Site administration → Plugins → Blocks → Feedback Flow* — especially the **Calendar behaviour** section, where you set working hours, weekends, holidays, and term breaks.

## Getting started

1. Turn editing on in a course and add the **Feedback Flow** block.
2. Teachers in that course open their dashboard from the block to see scores, priorities, and the pending-grading report.
3. (Optional) Fine-tune the academic calendar and the scoring weights in the admin settings.

## Privacy

Feedback Flow ships with a full privacy (GDPR) provider: the data it stores is described for subject-access exports and removed on request through Moodle's standard privacy tools.

## License

GNU GPL v3 or later.