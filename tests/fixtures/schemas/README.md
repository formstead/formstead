# Historical database fixtures

These SQL files preserve the table definitions from distributed Formstead versions. They were extracted from `Schema::statements()` in the archived release sources. Only table-prefix and charset interpolation was replaced with `{{prefix}}` and `{{charset}}`. Keep these fixtures independent of the current schema.

| Schema | Source tag |
| --- | --- |
| 1 | v0.1.1 |
| 2 | v0.2.0 |
| 3 | v0.3.1 |
| 4 | v0.4.1 |
| 5 | v0.5.1 |
| 6 | v0.7.1 |
| 7 | v0.8.1 |
| 8 | v0.9.0 |

The tags refer to the archived history before stages 0–9 were consolidated. Patch versions sharing a schema do not need another DDL fixture. `HistoricalUpgradeTest` installs each fixture, adds representative data, and runs the current migrator. The suite uses a disposable test database; never load these files into a live site.
