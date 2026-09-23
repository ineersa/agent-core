# OM reranker score calibration

This is a single-corpus calibration for `bge-reranker-base-q8_0.gguf` on 2026-09-22. The [frozen question set](relevance-calibration-questions.json) records 25 target observations, their source sessions, paraphrased questions, and eight negative questions. Fifteen positives and four negatives formed the calibration split. Ten positives and four negatives were held out until the floor was chosen. The target ID identifies one source observation; another observation that answers the question is also relevant.

The search used the existing disposable index of 9,352 source memories and 9,364 chunks, not the live OM database. Each question ran through the production embedding, BM25 and Vektor retrieval, and reranker endpoints. A judge read the first ten collapsed memory results without scores and labeled a result relevant only when it answered or directly supported the question. Those judgments are useful but subjective, and the first ten results do not measure all 20 tool results. The private numeric run files and candidate text remain in ignored `.hatfield/tmp/om-calibration25/`; only questions, source IDs, and aggregate measurements are tracked.

Reranker scores are raw model values, not probabilities. The first exploratory sample ranged from roughly -10.2 to +5.5. Before looking at held-out outcomes, the calibration chose the highest precision among floors that preserved at least 80% of the original positive-question topic coverage and at least 80% of the original exact-target hits. The first tested grid had no eligible floor, so it was extended downward to include -10 through -3 before scoring the held-out split. The chosen floor was **-4**, inclusive.

| Split and floor | Relevant / returned, first ten | Precision | Positive questions with a relevant hit | Exact target hits | Negative questions with any hit |
|---|---:|---:|---:|---:|---:|
| Calibration, no floor | 83 / 190 | 43.7% | 15 / 15 | 8 / 15 | 4 / 4 |
| Calibration, -4 | 77 / 115 | 67.0% | 14 / 15 | 8 / 15 | 2 / 4 |
| Held out, no floor | 21 / 140 | 15.0% | 8 / 10 | 3 / 10 | 4 / 4 |
| Held out, -4 | 11 / 51 | 21.6% | 6 / 10 | 2 / 10 | 2 / 4 |

Precision counts the first ten returned memories for every question, including negative questions. It measures judged results, not final answers.

The held-out result matters more than the calibration improvement: the floor still admits unrelated memories for two of four negative questions, and it loses relevant hits for two positive questions. A floor of 0 removes all four held-out negative hits but retains relevant results for only one of ten positive questions. There is no tested scalar floor that reliably rejects unrelated text and preserves recall. Retrieval itself missed some targets before filtering; a score floor cannot recover them.

Set `semantic.reranker_api.min_score: -4` only for this corpus and model if that trade-off is acceptable. An absent floor retains the old behavior. The filter runs after validating all reranker scores and before collapsing chunks into parent memories. Exact search and hybrid search without a reranker do not use it. Changing the floor does not change stored embeddings or rebuild the index. Reuse the questions and rejudge results when the corpus or model changes. Do not treat a missing result as proof that no relevant memory exists.
