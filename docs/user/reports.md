# Reports in the Report Builder

The assistant can show you the custom reports that exist on your site and tell you what the Moodle Report Builder can report on. Every report is built on a **report source**: a data set such as users, courses, badges, or, when the Booking plugin is installed, booking answers and booking options. Which sources exist depends on the plugins your site has installed; the assistant always shows the current state of your site.

Looking things up changes nothing. You see only the reports you are allowed to see; exploring sources needs the permission to create or edit custom reports.

## Finding existing reports

Ask for all reports, for reports by name, or for those built on one source.

Example requests:

- "Which custom reports exist on this site?"
- "Find the report about course completions."
- "Which reports can I edit?"
- "Is there already a report built on the users source?"

The side panel shows one card per report with a link to open it. If several reports match a name, the assistant asks which one you mean.

## Looking into one report

Name the report or give its id.

Example requests:

- "What does the completion report contain?"
- "Who can see the booking answers report?"
- "When is the weekly report sent, and to whom?"
- "How many rows does report 12 currently have?"

The assistant lists the columns, the conditions with their values, the filters, the audiences and the schedules as they are stored. The side panel shows the report itself, live: you can page, sort and use its filters right there.

## What you can ask

- Which report sources exist, and which plugin provides each of them.
- What one source offers: its columns, the filters a reader can use, the conditions an author can set, and the operators each filter understands.
- Which columns and filters a new report on that source would start with.

## Listing the report sources

Ask for the sources in general, or for those of one plugin.

Example requests:

- "Which report sources does the Report Builder offer here?"
- "What can I build reports on?"
- "Show me the report sources of the Booking plugin."
- "Which plugins provide report builder data sources?"

The side panel shows one card per source, grouped by plugin, with the exact source identifier the assistant uses when it builds a report.

## Describing one source

Name the source as it appears in the list.

Example requests:

- "Which columns does the Users source have?"
- "What can I filter on in the booking answers source?"
- "Show me the conditions and their operators for the Courses source."
- "Which aggregations are possible for the columns of the Badges source?"

If the assistant cannot tell which source you mean, it shows the available sources and asks you to pick one. The side panel lists the columns, filters and conditions grouped by the entity they belong to, with the exact identifier of each element.
