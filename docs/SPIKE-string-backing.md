# Spike: Lohnt sich String-Backing?

**Ergebnis: ja, deutlich.** Gemessen am 2026-09-02, PHP 8.4.14 (JIT off, arm64), Prototyp
`tests/Benchmark/BufferStr.php` gegen `src/Buffer.php` @ `22d8c14`. Drei Läufe, Streuung < 10 %.

## Zuerst: Verhaltensgleichheit

Ohne die ist jede Messung wertlos. `tests/Benchmark/equivalence.php` vergleicht beide
Implementierungen über die volle Matrix — Hex/Base64/UTF-8-Round-Trips, `subArray`/`slice`
über alle Kombinationen aus negativen, Null-, In-Range- und Out-of-Range-Indizes,
`concat` mit und ohne `totalLength`, alle `read*`-Breiten, `alloc`, `append`, `set/getBytesArray`:

```
230 identical, 11 expected divergences (1.x bugs fixed), 0 unexpected mismatches
```

Die 11 Divergenzen sind ausschließlich Stellen, an denen 1.x abstürzt und der Prototyp
Node-konform antwortet (`subArray` out of range → leerer Buffer; `concat` mit zu großem
`totalLength` → Null-Padding statt `null`-Löchern).

## Methode für Methode

| Operation | Iter. | SplFixedArray | String | Faktor |
|---|---:|---:|---:|---:|
| `toUtf8` (256 B) | 50 k | 355,3 ms | 1,7 ms | **210,8x** |
| `toString('hex')` (256 B) | 50 k | 608,2 ms | 12,0 ms | **50,5x** |
| `toString('hex')` (32 B) | 200 k | 330,6 ms | 22,3 ms | **14,9x** |
| `from(hex)` (256 B) | 50 k | 595,6 ms | 55,1 ms | **10,8x** |
| `concat` (3 Buffer, 60 B) | 100 k | 169,4 ms | 21,9 ms | **7,7x** |
| `from(Buffer)` Clone (32 B) | 200 k | 184,5 ms | 28,0 ms | **6,6x** |
| `from(hex)` (32 B) | 200 k | 360,8 ms | 69,8 ms | **5,2x** |
| `alloc(32)` | 200 k | 114,6 ms | 24,5 ms | **4,7x** |
| `from(hex)` (20 B) | 200 k | 243,9 ms | 60,5 ms | **4,0x** |
| `slice(4,20)` (32 B) | 200 k | 110,8 ms | 41,7 ms | **2,7x** |
| `toInt` (8 B) | 100 k | 181,4 ms | 92,1 ms | **2,0x** |
| `getLength` | 500 k | 16,9 ms | 17,2 ms | 1,0x |
| `readUInt8` ×32 | 100 k | 83,7 ms | 92,8 ms | **0,90x** ⚠ |
| `toArray` (32 B) | 200 k | 32,4 ms | 140,4 ms | **0,23x** ⚠ |
| `getBytesArray` *(BC-Adapter)* | 50 k | 1,7 ms | 44,0 ms | **0,04x** ⚠ |

Der Hebel liegt genau dort, wo er liegen soll: `from`, `toString`, `slice`, `concat` sind
laut Handover die meistgerufenen Methoden in XRPL-PHP (73/32/23/12 Aufrufe).

### Speicher

1000 Buffer à 32 Byte: **699,9 KB → 160,7 KB, Faktor 4,4x**. 717 Byte pro 32-Byte-Buffer
sind der zval-Overhead, den das Handover vermutet hat — er ist real und bestätigt.

## Die drei Regressionen, ehrlich

**`toArray()` ist ~4,3x langsamer und das lässt sich nicht schließen.** Alle Varianten getestet:

| Variante | 200 k × 32 B |
|---|---:|
| `SplFixedArray->toArray()` (heute) | 32,0 ms |
| `array_values(unpack('C*'))` | 139,5 ms |
| `unpack('C*')` roh | 126,5 ms |
| `array_map('ord', str_split())` | 146,3 ms |

Das ist inhärent: `SplFixedArray` *hält* bereits ein Int-Array, `toArray()` ist ein C-Copy.
Aus einem String muss jedes Byte erst zu einem zval materialisiert werden. Absolut sind es
0,54 µs pro Aufruf — bei ~17 Buffer-`toArray()`-Stellen im Codec rund 10 µs pro Transaktion.

**`readUInt8` ist ~10 % langsamer** (String-Offset erzeugt einen temporären 1-Zeichen-String,
`SplFixedArray` liefert direkt ein Int-zval). Ohne Methodenaufruf-Overhead wäre `ord($s[$i])`
mit 39,6 ms sogar **2,1x schneller** als der heutige Weg — der Verlust entsteht erst in der
Methodenhülle und ist entsprechend klein.

**`getBytesArray()` ist 25x langsamer** — das ist der BC-Adapter, siehe unten. XRPL-PHP ruft
ihn nicht auf.

## Entscheidend: Auf Workload-Ebene verliert nichts

Einzelmethoden sind nicht die Frage — die Frage ist, was der Codec insgesamt tut.
Deshalb ein bewusst **gegen** String-Backing konstruierter Fall:

| Workload | SplFixedArray | String | Faktor |
|---|---:|---:|---:|
| **Worst Case:** `toArray`-lastiger Address-Decode | 138,3 ms | 100,1 ms | **1,38x** |
| `Amount::from(array_merge(toArray ×3))`, wie heute | 371,2 ms | 183,9 ms | **2,02x** |
| …dasselbe, als `concat()` geschrieben | 370,9 ms | 68,3 ms | **5,43x** |
| BinaryParser-artige Leseschleife (64 Felder) | 1652,4 ms | 478,2 ms | **3,46x** |
| 12-Feld-Transaktion serialisieren + hexen | 653,8 ms | 114,9 ms | **5,69x** |

**Selbst der Worst Case ist 1,38x schneller** — die Gewinne bei `from(hex)` und `slice`
überkompensieren den `toArray`-Verlust. Es gibt keinen gemessenen Workload, der langsamer wird.

Die dritte Zeile ist der interessanteste Nebenbefund: `Amount.php:156` schreibt heute
`Buffer::from(array_merge($amount->toArray(), $currency->toArray(), $issuer->toArray()))`.
Als `Buffer::concat([...])` formuliert — identisches Ergebnis — springt derselbe Code von
2,0x auf 5,4x. Mehrere der `toArray()`-Stellen in XRPL-PHP sind solche Array-Umwege, die
String-Backing überflüssig macht. Der Regressionspfad ist also teilweise auf der
Aufruferseite auflösbar, ohne dass Buffer etwas versprechen muss.

## `getBytesArray()`/`setBytesArray()`: Cast hält die Signatur

Der Vorschlag funktioniert. Beide Methoden bleiben signatur- und verhaltensgleich:

```php
public function getBytesArray(): SplFixedArray
{
    return SplFixedArray::fromArray($this->toArray());
}

public function setBytesArray(SplFixedArray $bytesArray): void
{
    $raw = '';
    foreach ($bytesArray as $b) { $raw .= chr((int)$b & 0xFF); }
    $this->bytes = $raw;
    $this->length = strlen($raw);
}
```

Im Prototyp implementiert und in der Äquivalenzprüfung mit abgedeckt (identische Ausgabe).
Preis ist der 25x-Faktor auf genau diesen beiden Methoden — vertretbar, weil sie nach dem
Umbau reine Kompatibilitätsbrücken sind und XRPL-PHP sie nicht benutzt.

**Folge für den Release-Zuschnitt:** Punkt A fällt damit aus der Menge der Breaking Changes
heraus. Übrig bleibt allein `public int $length` (Punkt E).

## Neuer Bug, nicht im Handover

`concat()` mit einem `$totalLength` **größer** als die Summe der Eingaben lässt die
überzähligen Slots als `null` stehen:

```php
Buffer::concat([Buffer::from('AABB','hex'), Buffer::from('CC','hex')], 6)->toArray();
// [170, 187, 204, null, null, null]
// ->toString('hex') wirft: TypeError: dechex(): Argument #1 ($num) must be of type int, null given
```

Node füllt mit Nullbytes auf. Gleiche Fehlerklasse wie `offsetUnset()` — ein `null`-Loch,
das erst später und an anderer Stelle knallt. Gehört zu Phase 2.

## Fazit

Der Umbau lohnt sich: **4–7x auf realistischen Codec-Workloads, 4,4x weniger Speicher**,
bei drei benannten und in ihrer Wirkung vermessenen Regressionen, von denen keine auf
Workload-Ebene durchschlägt. Die Voraussetzung bleibt unverändert: Phase 1 zuerst.
Die Äquivalenz-Matrix aus diesem Spike ist der Anfang davon.

---

## Nachtrag: Verifikation an der echten Klasse (Phase 4)

Der Umbau ist ausgeführt. Gemessen wurde `Buffer` nach Phase 4 gegen die
Implementierung aus `d2f1491` (Stand nach Phase 3, also inklusive der
Bugfixes und Grenzprüfungen — der Vergleich isoliert damit wirklich nur die
Repräsentation).

| Operation | 1.x | 2.0 | Faktor | Spike-Prognose |
|---|---:|---:|---:|---:|
| `toUtf8` (256 B) | 416,5 ms | 1,7 ms | **247,1x** | 210,8x |
| `toString('hex')` (256 B) | 657,8 ms | 16,6 ms | **39,6x** | 50,5x |
| `toString('hex')` (32 B) | 331,7 ms | 22,9 ms | **14,5x** | 14,9x |
| `concat` | 166,3 ms | 26,8 ms | **6,2x** | 7,7x |
| `from(hex)` (32 B) | 361,1 ms | 76,5 ms | **4,7x** | 5,2x |
| `alloc(32)` | 116,3 ms | 26,6 ms | **4,4x** | 4,7x |
| `slice(4,20)` | 145,2 ms | 47,6 ms | **3,1x** | 2,7x |
| `readUInt8` ×32 | 148,3 ms | 156,6 ms | 0,95x | 0,90x |
| `toArray` (32 B) | 33,3 ms | 147,0 ms | 0,23x | 0,23x |
| **12-Feld-Transaktion serialisieren** | 701,8 ms | 112,0 ms | **6,3x** | 5,7x |

Speicher: **699,9 KB → 160,7 KB** für 1000 Buffer à 32 Byte, Faktor **4,36x** —
exakt der Spike-Wert.

Die Prognose hat gehalten. Der Codec-Workload liegt mit 6,3x sogar leicht über
der vorhergesagten 5,7x, die beiden Regressionen exakt auf den erwarteten
Werten. Verifiziert durch die 254 Tests aus Phase 1, die den Umbau ohne eine
einzige Änderung an den Erwartungen überstanden haben — genau der Zweck, zu dem
sie geschrieben wurden.
