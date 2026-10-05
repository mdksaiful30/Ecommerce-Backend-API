# Taste

## Environment / stack
- Builds PHP **Laravel** backends (e.g. an `ecommerce-api` project); runs them locally on **Windows + Laragon**, with projects checked out under `G:\laragon\www\<project>` — so absolute Windows paths like that in a prompt are normal working paths, not typos. Confidence: 0.75
- Database target is **MySQL**. Confidence: 0.6

## Workflow
- Designs the database schema **visually first, in drawSQL**, then exports it as `drawSQL-mysql-export-<YYYY-MM-DD>.sql` (often dropped straight into `database/migrations/`) and asks for the code to be generated *from that diagram/export* rather than from a prose spec. Treat the SQL diagram as the source of truth and mirror it faithfully. Confidence: 0.7
- When asking for schema codegen, expects the **full set of artifacts** — "create respective model, tables, migration etc" — i.e. Eloquent models + migrations (+ factories/seeders and other obvious companions) for every table in the design, not a single sample file. Confidence: 0.65
- Issues terse, imperative instructions that reference attached files by path and assumes the agent will infer the rest (including matching the project's existing conventions) without further briefing. Confidence: 0.6
