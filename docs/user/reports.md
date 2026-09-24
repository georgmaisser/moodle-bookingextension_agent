# Exploring report sources

The assistant can tell you what the Moodle Report Builder on your site can report on. Every report is built on a **report source**: a data set such as users, courses, badges, or, when the Booking plugin is installed, booking answers and booking options. Which sources exist depends on the plugins your site has installed; the assistant always shows the current state of your site.

Exploring sources changes nothing. You need the permission to create or edit custom reports to use it.

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
