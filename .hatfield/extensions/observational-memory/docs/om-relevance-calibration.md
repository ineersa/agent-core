# OM retrieval calibration

This report covers two calibrations on the same disposable index of 9,352 memories and 9,364 chunks (`var/tmp/om-semantic-benchmark-5eb55cac6288c6e3/`), not the live OM database. Private candidate texts, endpoint bodies, and numeric run files stay under ignored `.hatfield/tmp/`. Tracked files keep questions, source IDs, and aggregate measurements only.

The frozen question set is [relevance-calibration-questions.json](relevance-calibration-questions.json). Cases 1–33 are unchanged from the first calibration. Cases 34–50 add 13 observation paraphrase questions and 4 hard negatives. Splits were frozen before scoring: 22 calibration / 16 held-out positives, and 6 / 6 negatives.

Judging reads the first ten collapsed parent memories without scores. A result counts as relevant only when it answers or directly supports the question. Session or target ID alone is not enough. Precision counts those first-ten slots for every question, including negatives.

## Historical baseline (33 questions, 2026-09-22)

The first calibration chose reranker floor **-4** on 25 positives and 8 negatives (15+4 calibration, 10+4 held out) using production retrieval (`maxItems=100`, fused top 100, CombinedStore `rrfK=60`).

| Split and floor | Relevant / returned, first ten | Precision | Positive questions with a relevant hit | Exact target hits | Negative questions with any hit |
|---|---:|---:|---:|---:|---:|
| Calibration, no floor | 83 / 190 | 43.7% | 15 / 15 | 8 / 15 | 4 / 4 |
| Calibration, -4 | 77 / 115 | 67.0% | 14 / 15 | 8 / 15 | 2 / 4 |
| Held out, no floor | 21 / 140 | 15.0% | 8 / 10 | 3 / 10 | 4 / 4 |
| Held out, -4 | 11 / 51 | 21.6% | 6 / 10 | 2 / 10 | 2 / 4 |

That floor raised judged precision and cut some negative hits, but held-out topic coverage fell from 8/10 to 6/10. No tested scalar floor both rejected unrelated text and preserved recall. Retrieval misses before the floor cannot be repaired by the floor.

## Candidate-stage results (50 questions)

Symfony AI `CombinedStore` uses reciprocal rank fusion with default `rrfK=60`. Production code passes `maxItems => 100` into each store adapter, then slices the fused list to 100 before optional reranking. RRF scores are ranking scores, not confidence. Do not threshold them.

Exact-target recall before reranking:

| Split | Per-store budget | Vector | BM25 | RRF top 50 | RRF top 100 | RRF top 200 |
|---|---:|---:|---:|---:|---:|---:|
| Calibration | 50 | 19/22 | 15/22 | 19/22 | 20/22 | — |
| Calibration | 100 | 21/22 | 17/22 | 21/22 | 22/22 | 22/22 |
| Calibration | 200 | 22/22 | 21/22 | 21/22 | 22/22 | 22/22 |
| Held out | 50 | 8/16 | 9/16 | 9/16 | 11/16 | — |
| Held out | 100 | 11/16 | 12/16 | 9/16 | 11/16 | 16/16 |
| Held out | 200 | 11/16 | 12/16 | 9/16 | 11/16 | 16/16 |

At production budget 100 / fused 100, five held-out targets sit in one store’s top 100 but outside the fused top 100 (`fork_resume`, `jbcontext`, `unicode`, `human_input`, `persistence`). Raising only the fused cutoff to 200 with the same per-store budget recovers all five as candidates. Raising per-store budget from 100 to 200 without raising fused N does not. Changing `rrfK` among 20 / 60 / 100 did not change fused-100 exact recall on this set.

Miss classes at production fused 100:

- No lexical and no vector hit inside the store budget: none on the calibration positives; held-out gaps are dominated by fused truncation and later rerank/floor loss.
- Present in BM25 or vector top 100, dropped by fused top 100: the five held-out cases above.
- Present after fusion, lost to reranker floor -4: several held-out exact targets (for example `child_status`, `logging`, `compact_castor`).

## Reranker floors on the 50-question set

Exact-target retention after reranking (not topical precision):

| Split | Candidates | No floor | Floor -4 | Floor -3 | Floor 0 |
|---|---|---:|---:|---:|---:|
| Calibration | 100 / 100 | 22/22 | 18/22 | 14/22 | 8/22 |
| Held out | 100 / 100 | 11/16 | 8/16 | 7/16 | 5/16 |
| Held out | 200 / 200 | 16/16 | 10/16 | 9/16 | 5/16 |

Judged first-ten topical metrics on production 100 / 100 (new 50-case judgments; not comparable 1:1 to the 33-case table because the question set and retrieval snapshot differ):

| Split and floor | Relevant / returned, first ten | Precision | Positive questions with a relevant hit | Exact target hits | Negative questions with any hit |
|---|---:|---:|---:|---:|---:|
| Calibration, no floor | 88 / 280 | 31.4% | 22 / 22 | 15 / 22 | 6 / 6 |
| Calibration, -4 | 83 / 168 | 49.4% | 21 / 22 | 15 / 22 | 2 / 6 |
| Held out, no floor | 25 / 220 | 11.4% | 13 / 16 | 9 / 16 | 6 / 6 |
| Held out, -4 | 20 / 111 | 18.0% | 11 / 16 | 8 / 16 | 2 / 6 |

Floor -4 still improves precision and cuts negatives with hits from 6/6 to 2/6 on both splits, while held-out topical coverage falls from 13/16 to 11/16. Floor 0 removes remaining negative hits and destroys recall.

## Latency

On this machine and local endpoints, mean rerank time was about 0.29s for 100 fused chunks (13 HTTP batches at `batch_size=8`) and about 0.56s for 200 chunks (25 batches). Stage A candidate probes across budgets 50/100/200 finished in about 36s for 50 questions. Stage B with rerank for budgets 100 and 200 finished in about 127s. Individual cases stayed under 2s.

## Recommendation for parent decision

Evidence favors keeping CombinedStore `rrfK=60`. The clear candidate-budget lever is fused N, not RRF k.

1. Prefer **fused candidate N=200** with per-store `maxItems=100` if recovering the five held-out fused-truncation misses matters. Cost: about +0.27s mean rerank latency and 25 instead of 13 rerank requests. Exact held-out targets after rerank rise from 11/16 to 16/16 before a floor; with floor -4 they rise only to 10/16 because the floor still drops some recovered targets.
2. Keep **per-store budget 100** unless date-filtered overfetch needs separate treatment; budget 200 without fused 200 did not fix the fused-truncation misses.
3. Treat **min_score: -4** as still optional and model/corpus-specific. On the expanded set it remains a precision/noise trade, not an absence detector. Do not raise the floor blindly after widening candidates; re-judge if N changes in production.
4. Do not threshold RRF scores.

No production code, tests, or settings were changed by this calibration pass.

## Limits

Judgments are subjective and use short candidate excerpts. Topical ID sets were frozen from the production N=100 first-ten lists; expanding N can surface additional relevant parents not counted in the 50-case topical precision table. Exact-target tables do not have that bias. Hard negatives stress unrelated domains; they do not model near-miss engineering questions. Reuse the questions when the corpus or embedding/reranker models change.
