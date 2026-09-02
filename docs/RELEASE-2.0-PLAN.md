# Release-Plan `hardcastle/buffer` 2.0.0

Stand: 2026-09-02 · Basis: Handover in `CLAUDE.md` (Abschnitt 5a), verifiziert gegen `src/Buffer.php` @ `22d8c14`
Anlass: anstehender Release von `XRPL-PHP`, das `hardcastle/buffer: ^1.0.1` in 47 Dateien nutzt.

---

## 1. Verifizierter Ausgangszustand

| | |
|---|---|
| Quellcode | 1237 Zeilen, eine Klasse (`src/Buffer.php`) |
| Tests | 197 Zeilen / 14 Tests / 49 Assertions — **grün** |
| Psalm | **0 Errors** (25 Infos), Typinferenz 100 % |
| CI | PHPUnit-Matrix 8.2–8.5 (+ `lowest` auf 8.2), Psalm-Job |
| Abhängigkeit | `brick/math: ^0.11\|^0.12\|^0.13\|^0.14`, PHP `^8.2` |

Die grüne Baseline ist der Referenzpunkt: Jede Phase unten muss sie halten.

## 2. Korrekturen am Handover

Drei Aussagen aus `CLAUDE.md` halten der Prüfung am Code nicht stand. Sie ändern den Zuschnitt des Releases:

**(1) Der Umbau auf String-Backing ist *nicht* API-neutral.**
Das Handover argumentiert, `$bytesArray` sei `private`, der Umbau damit intern. Tatsächlich leaken zwei **öffentliche** Methoden die interne Repräsentation:

```php
public function getBytesArray(): SplFixedArray
public function setBytesArray(SplFixedArray $bytesArray): void
```

Damit ist `SplFixedArray` Teil des öffentlichen Vertrags — Punkt A ist ein Breaking Change und gehört in dasselbe Fenster wie Punkt E.
**Entwarnung:** `XRPL-PHP` ruft **keine** der beiden Methoden auf (verifiziert über `src/` und `tests/`). Der Umbau ist für den einzigen bekannten Konsumenten folgenlos.

**(2) Der `subArray()`-Absturz sitzt eine Zeile weiter unten als beschrieben.**
Die Klemme auf `max(0, …)` ist bereits vorhanden — die *nachfolgende* Zeile macht sie wieder kaputt:

```php
$length = max(0, $end - $start);              // ✔ klemmt korrekt auf 0
$length = min($length, $this->length - $start); // ✘ min(0, -5) = -5
```

Verifiziert: `Buffer::from('0102030405','hex')->subArray(10)` wirft `ValueError` aus dem `SplFixedArray`-Konstruktor (nicht die im Handover genannte generische Exception). Der Fix gehört in die **zweite** Zeile.

**(3) `toInt()` liefert bei Überlänge keinen Zufallswert, sondern sättigt.**
`Buffer::from(str_repeat('FF',32),'hex')->toInt()` ergibt exakt `9223372036854775807` (`PHP_INT_MAX`). Deterministisch falsch statt zufällig falsch — das macht den Fehler in Aufrufercode unauffälliger, nicht harmloser.

*Nebenbefund:* OOB-Lesen wirft `OutOfBoundsException` (aus `SplFixedArray`), nicht `RuntimeException`. Für die Vereinheitlichung in Phase 2 relevant.

### Alle Bugs reproduziert

```
subArray(10) auf 5 Bytes  → ValueError: size must be >= 0
$buf[9] = 0xFF auf 5 Bytes → Länge wächst still auf 10   (Node: No-Op)
unset($buf[2]); ->toString() → TypeError: dechex(null)   (Node: No-Op)
toInt() auf 32 Bytes       → 9223372036854775807         (still falsch)
$buf->length = 2           → Buffer inkonsistent zum Bytes-Speicher
```

## 3. Versionsentscheidung: **2.0.0**

Punkt E (`public $length` schließen) *und* Punkt A (`get/setBytesArray` entfernen) sind beide semver-breaking. Damit ist 1.x nicht haltbar.

**Das ist hier billig:** `XRPL-PHP` pinnt `^1.0.1` und müsste die Constraint ohnehin anfassen — es steht selbst vor einem Release. Ein 2.0 kostet dort **eine Zeile in `composer.json`**, weil keine der brechenden Stellen dort benutzt wird. Dasselbe Fenster später noch einmal zu öffnen, kostet ein weiteres Major.

→ **Empfehlung: jetzt 2.0.0 schneiden.** Die einzige Entscheidung, die vor Phase 3 fallen muss.

## 4. Phasen

Reihenfolge folgt dem Handover; Phase 0 ist ergänzt, weil Phase 3 sonst unbelegt bleibt.

### Phase 0 — Messlatte (neu)
**Warum:** Der gesamte Nutzen von Phase 3 ist Performance. Ohne Vorher-Messung ist der Umbau eine Behauptung.
- Micro-Benchmark-Skript (kein Framework nötig) für die heißen Pfade: `from` (73 Aufrufe in XRPL-PHP), `toString` (32), `slice` (23), `alloc`/`getLength`/`toArray` (je 22), `toInt` (16), `toUtf8` (14), `concat` (12), `readUInt8` (10).
- Zahlen als Baseline im PR festhalten.
**DoD:** Reproduzierbare Vorher-Zahlen, eingecheckt unter `tests/Benchmark/`.

### Phase 1 — Testabdeckung (Handover F)
**Warum:** Voraussetzung für alles Weitere. 197 Zeilen Test gegen 1237 Zeilen Code, unter dem Signierpfad — zu dünn zum Refactoren.
- Characterization-Tests, die das **heutige** Verhalten festschreiben: Round-Trips hex/base64/utf8, alle read/write-Breiten, Grenzfälle (leerer Buffer, negative Offsets, überlange Offsets, ungerade Hex-Länge).
- Die fünf Bugs aus §2 als `markTestIncomplete`/erwartetes-Fehlverhalten dokumentieren — in Phase 2 werden daraus grüne Assertions.
- *Nice-to-have:* Fixtures aus echtem Node-`Buffer`-Output als Paritätsnachweis.
**DoD:** Line-Coverage der öffentlichen API deutlich über heute; Suite grün; keine Verhaltensänderung.

### Phase 2 — Bugfixes (Handover B) · nicht breaking
1. `subArray()` — Klemme in die `min()`-Zeile; Node-Verhalten ist ein leerer Buffer.
2. `offsetSet()` jenseits der Länge — stiller No-Op statt Realloc (Node: Buffer sind fixer Größe).
3. `offsetUnset()` — No-Op statt `null`-Loch, das `toUtf8()`/`toString()` später zerlegt.
4. Einheitliche Fehlerstrategie: `readUInt8`/`writeUInt8` etc. prüfen Grenzen selbst und werfen dieselbe, dokumentierte Exception wie `offsetGet` — statt einmal `OutOfBoundsException`, einmal `Exception`.
5. `toInt()` — Guard auf ≤ 8 Byte; darüber Exception statt stiller Sättigung. Sauberer Ersatzweg entsteht in Phase 5 (`readBigUInt64BE`).
**DoD:** Alle fünf Reproduktionen aus §2 verhalten sich wie Node; Phase-1-Tests bleiben grün.

### Phase 3 — Breaking-Change-Fenster (Handover E + A-Vorbereitung)
- `public int $length` → `private`, Zugriff ausschließlich über `getLength()`.
- `getBytesArray()`/`setBytesArray()` entfernen oder auf `toArray()`/`from(array)` umbiegen — sie sind der zweite Leak der Interna.
- **Vorher gegenprüfen:** `grep -rn 'getBytesArray\|setBytesArray\|->length' <consumer>/src` — für XRPL-PHP bereits erledigt: **0 Treffer**.
**DoD:** `composer.json`-Version/Tag auf 2.0.0 vorbereitet; `UPGRADING.md` mit 1.x → 2.0-Pfad.

### Phase 4 — String-Backing (Handover A) · der Performance-Hebel
**Warum:** `SplFixedArray` von Integers heißt ein zval pro Byte. PHPs String *ist* bereits ein Byte-Array — kompakter und schneller, und das läuft im Codec pro Feld pro Transaktion.
- `from($hex)`: Regex → `str_split` → `array_map('hexdec')` → Schleife ⟹ **ein `hex2bin()`**.
- `toString('hex')`: byteweise `dechex`+`str_pad`+Konkatenation ⟹ **`strtoupper(bin2hex(...))`**.
- `from(Buffer)`, `subArray`, `toUtf8`: elementweise Schleifen ⟹ `substr`/Zuweisung.
**DoD:** Phase-1-Suite unverändert grün (das ist der ganze Sinn der Characterization-Tests); Phase-0-Benchmark zeigt die Verbesserung in Zahlen.

### Phase 5 — Parität (Handover C + D) · additiv
**Dokumentieren** (bewusste Abweichungen, nicht ändern):
- `slice()`/`subArray()` **kopieren**; in Node sind es Views auf denselben Speicher. Kopie ist die sicherere Wahl — und XRPL-PHP verlässt sich 23× darauf. Muss laut im README stehen.
- `toString()` defaultet auf `'hex'`, Node auf `'utf8'`.
- `toUtf8()` dekodiert nicht, sondern gibt rohe Bytes (`chr()` je Byte) — faktisch `toBinaryString()`. Alias einführen, Verhalten dokumentieren.

**Ergänzen** (fehlende Node-Methoden):
- `readUIntBE`/`readIntBE`/`writeUIntBE` (variable Bytelänge 1–6)
- **BigInt64-Familie**: `readBigInt64BE/LE`, `readBigUInt64BE/LE`, `writeBigInt64*` — für XRPL besonders relevant (Drops, Ledger-Indizes) und der saubere Ersatz für den `toInt()`-Umweg über `BigInteger`.
- `toJSON`, `entries`/`keys`/`values`, `transcode`
**DoD:** README dokumentiert die drei Abweichungen explizit; neue Methoden getestet.

### Phase 6 — Release & Kopplung
- Tag `v2.0.0`, Packagist.
- In `XRPL-PHP`: Constraint auf `^2.0` heben, volle Suite fahren, Buffer-Änderungen im dortigen `CHANGELOG.md` erwähnen.
- *Optional vorab:* Constraint testweise auf `dev-release/2.0-hardening` zeigen lassen, um die 47 Nutzungsstellen vor dem Tag zu verifizieren.

## 5. Abbruchkanten

Falls die Zeit knapp wird, ist die ehrliche Reihenfolge des Handovers maßgeblich: **Buffer funktioniert heute**; den Nutzern von XRPL-PHP fehlen Transaktionstypen, nicht Buffer-Performance.

- **Minimalschnitt:** Phase 1 + 2 + 3 → 2.0.0. Billig, teils sicherheitsrelevant, und schließt das Breaking-Fenster.
- **Phase 4 und 5 sind nachrüstbar** — Phase 4 ohne weiteres Major (nach Phase 3 ist die Repräsentation gekapselt), Phase 5 rein additiv.
- **Nicht abkürzen:** Phase 1 vor Phase 4. Ohne Characterization-Tests wird der String-Umbau ein Refactor ohne Netz — direkt unter dem Signierpfad.
