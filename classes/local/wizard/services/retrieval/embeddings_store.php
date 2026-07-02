<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Engine-agnostic embeddings store contract (Layer 0 of the retrieval foundation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * The single retrieval/persistence contract shared by all embedding areas (docs, skills and,
 * later, site content) and every backend (CSV today, DB next, ANN later).
 *
 * Design rules this contract encodes (see the retrieval-foundation blueprint):
 *  - {@see search_top_k()} is THE public retrieval method; the cosine-vs-ANN choice lives behind it
 *    per area, so a server-side ANN implementation can be swapped in without touching any caller.
 *  - Every row/query carries an {@see \...\retrieval_filter}: docs/skills pass null (global); site
 *    content narrows by allowed context ids. Resolving the filter is NOT a permission grant — the
 *    caller still applies the authoritative per-document access check.
 *  - Rebuilds are atomic via a generation swap: write a new generation, then commit it; readers only
 *    ever see the committed generation, never a half-built one.
 *
 * A variant is the (model, dimensions) pair — embeddings for different models live side by side and a
 * model switch never invalidates the others.
 */
interface embeddings_store {
    // -------------------------------------------------------------------------
    // Retrieval — the ANN-swap seam.

    /**
     * Return the top-k rows for one area/variant, already scored (cosine) and above the minimum score.
     *
     * Resolves the committed generation for (area, model, dims) internally and searches only within it.
     * The default (CSV/DB) implementation streams rows and scores in PHP; an ANN-backed area overrides
     * this with a server-side top-k. Never returns the raw vector.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param float[] $queryvector
     * @param int $k
     * @param float $minscore
     * @param retrieval_filter|null $filter Access/context narrowing; null = no narrowing (global).
     * @return embedding_hit[] Descending by score.
     */
    public function search_top_k(
        string $area,
        string $emodel,
        int $edims,
        array $queryvector,
        int $k,
        float $minscore,
        ?retrieval_filter $filter = null
    ): array;

    // -------------------------------------------------------------------------
    // Presence / readiness.

    /**
     * Whether a committed index exists for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return bool
     */
    public function exists(string $area, string $emodel, int $edims): bool;

    /**
     * Number of committed rows for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function count_rows(string $area, string $emodel, int $edims): int;

    /**
     * Read the stored source fingerprint the index was last built from (empty when unknown).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return string
     */
    public function fingerprint(string $area, string $emodel, int $edims): string;

    /**
     * Store the source fingerprint the index was just built from.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $fingerprint
     * @return void
     */
    public function set_fingerprint(string $area, string $emodel, int $edims, string $fingerprint): void;

    // -------------------------------------------------------------------------
    // Rebuild — atomic generation swap.

    /**
     * Open a new (uncommitted) generation for this area/variant and return its id.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function begin_generation(string $area, string $emodel, int $edims): int;

    /**
     * Add one row to an open generation.
     *
     * @param string $area
     * @param int $generation
     * @param embedding_row $row
     * @return void
     */
    public function upsert(string $area, int $generation, embedding_row $row): void;

    /**
     * Return an existing committed row by its identity key (for hash-based reuse on rebuild), or null.
     *
     * The caller compares the returned row's content hash to decide whether to reuse it (skip re-embed)
     * or embed afresh.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $key Identity key as produced by the area's row mapper.
     * @return embedding_row|null
     */
    public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row;

    /**
     * Commit an open generation: make it the active one for this area/variant and prune older ones.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return int Number of committed rows.
     */
    public function commit_generation(string $area, string $emodel, int $edims, int $generation): int;

    /**
     * Discard an open, uncommitted generation without publishing it.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return void
     */
    public function discard_generation(string $area, string $emodel, int $edims, int $generation): void;

    // -------------------------------------------------------------------------
    // Enumeration — diagnostics / rebuild source (NOT the retrieval path; use search_top_k for that).

    /**
     * Yield each committed row for this area/variant, one at a time (bounded memory).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return \Generator
     */
    public function stream_rows(string $area, string $emodel, int $edims): \Generator;

    // -------------------------------------------------------------------------
    // Invalidation.

    /**
     * Delete all rows for a context (course/module deleted).
     *
     * Applies across ALL areas; docs/skills rows carry a null context id and are therefore never
     * matched, so this only ever affects site content.
     *
     * @param int $contextid
     * @return void
     */
    public function delete_by_context(int $contextid): void;
}
