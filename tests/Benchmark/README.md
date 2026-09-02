# Spike: String-Backing (Phase 0/4)

Wegwerf-Messcode zur Entscheidung, ob `SplFixedArray` → `string` als interne
Repräsentation den Aufwand lohnt. **Kein Teil der Bibliothek** — `BufferStr`
ist ein Prototyp der heißen Pfade, kein vollständiger Buffer.

```bash
php tests/Benchmark/equivalence.php     # Verhaltensgleichheit alt vs. Prototyp
php tests/Benchmark/bench.php           # Methode für Methode + Speicher
php tests/Benchmark/bench-workloads.php # realistische Codec-Workloads
```

`equivalence.php` ist die Keimzelle der Characterization-Tests aus Phase 1:
Was hier als "expected divergence" gilt, sind genau die 1.x-Bugs, die Phase 2
behebt. Ergebnisse siehe `docs/SPIKE-string-backing.md`.

Nach Abschluss von Phase 4 kann `BufferStr.php` entfallen; die Benchmarks
laufen dann gegen `Buffer` selbst.
