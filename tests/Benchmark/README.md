# Benchmarks

Measures the hot paths of `Buffer` in absolute terms.

```bash
php tests/Benchmark/bench.php            # method by method, plus memory
php tests/Benchmark/bench-workloads.php  # realistic codec-shaped workloads
```

These were written in phase 0 to decide whether the string backing was worth
building, and kept afterwards as a regression guard. The 1.x comparison that
justified the change is recorded in `docs/SPIKE-string-backing.md`; the
throwaway prototype it was measured against is gone, since `Buffer` itself now
uses that representation.
