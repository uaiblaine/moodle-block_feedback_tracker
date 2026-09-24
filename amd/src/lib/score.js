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
 * Client-side mirror of the Academic Responsiveness Score formula, used by
 * the interactive simulator so the score updates live as sliders move.
 *
 * Keep in step with classes/local/score/responsiveness_calculator.php
 * (`compute`, `load_weights`, `effective_weights`): the maths here must
 * produce the same score the server stores, or the simulator would teach the
 * wrong behaviour.
 *
 * @module    block_feedback_tracker/lib/score
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {bandForScore} from 'block_feedback_tracker/lib/bands';

/** The five score terms, in display order. */
export const TERMS = ['compliance', 'median', 'critical', 'pending', 'trend'];

/** Default weights — mirror responsiveness_calculator::DEFAULT_WEIGHT_*. */
export const DEFAULT_WEIGHTS = {
    compliance: 0.40,
    median:     0.25,
    critical:   0.15,
    pending:    0.10,
    trend:      0.10,
};

/**
 * Clamp into [0, 1].
 *
 * @param {number} v
 * @returns {number}
 */
const clamp01 = (v) => Math.min(1, Math.max(0, v));

/**
 * Normalise the five weights to sum 1.0 if the stored sum falls outside
 * [0.95, 1.05] — mirrors responsiveness_calculator::load_weights(). Negative
 * or non-numeric weights fall back to their default; an all-zero set returns
 * the defaults.
 *
 * @param {object} weights  {compliance, median, critical, pending, trend}
 * @returns {object} normalised weights, same keys
 */
export const normalizeWeights = (weights) => {
    const w = {};
    TERMS.forEach((k) => {
        const v = Number((weights || {})[k]);
        w[k] = (Number.isFinite(v) && v >= 0) ? v : DEFAULT_WEIGHTS[k];
    });
    const sum = TERMS.reduce((acc, k) => acc + w[k], 0);
    if (sum <= 0) {
        return {...DEFAULT_WEIGHTS};
    }
    if (sum < 0.95 || sum > 1.05) {
        const out = {};
        TERMS.forEach((k) => {
            out[k] = w[k] / sum;
        });
        return out;
    }
    return w;
};

/**
 * Drop terms flagged unavailable and renormalise the rest to sum 1.0 —
 * mirrors responsiveness_calculator::effective_weights().
 *
 * @param {object} weights    Already-normalised weights.
 * @param {object} available  Per-term boolean flags.
 * @returns {object} effective weights, same keys
 */
export const effectiveWeights = (weights, available) => {
    let keepsum = 0;
    TERMS.forEach((k) => {
        if (available[k]) {
            keepsum += weights[k];
        }
    });
    const out = {};
    TERMS.forEach((k) => {
        out[k] = (!available[k] || keepsum <= 0) ? 0 : weights[k] / keepsum;
    });
    return out;
};

/**
 * Compute the score for one scenario.
 *
 * Metric keys mirror the PHP `compute()` input; `compliancePct`, `medianEffH`
 * and `trendPct` may be null (treated charitably / term dropped exactly as the
 * server does). A scenario with no graded and no pending work returns the
 * neutral "nodata" state (null score) rather than a misleading ~100.
 *
 * @param {object} metrics    {compliancePct, medianEffH, critical, pending, numgraded30d, trendPct}
 * @param {object} weights    Raw (un-normalised) weights from the sliders.
 * @param {number} slaGoal    SLA goal hours (>0; falls back to 24).
 * @param {object} thresholds {excellent, good, regular} score-band cutoffs.
 * @returns {{score: number|null, band: string, nodata: boolean,
 *            normalized: object, effective: object,
 *            terms: Object<string, {value: number|null, weight: number, points: number}>}}
 */
export const computeScore = (metrics, weights, slaGoal, thresholds) => {
    const numgraded = Math.max(0, Math.trunc(Number(metrics.numgraded30d) || 0));
    const pending = Math.max(0, Math.trunc(Number(metrics.pending) || 0));
    const critical = Math.max(0, Math.trunc(Number(metrics.critical) || 0));
    const compliancePct = metrics.compliancePct === null || metrics.compliancePct === undefined
        ? null : Number(metrics.compliancePct);
    const medianEffH = metrics.medianEffH === null || metrics.medianEffH === undefined
        ? null : Number(metrics.medianEffH);
    const trendPct = metrics.trendPct === null || metrics.trendPct === undefined
        ? null : Number(metrics.trendPct);
    const slagoal = Number(slaGoal) > 0 ? Number(slaGoal) : 24;

    const normalized = normalizeWeights(weights);

    // No submitted work at all → neutral "no data", never a charitable ~100.
    if (numgraded === 0 && pending === 0) {
        const terms = {};
        TERMS.forEach((k) => {
            terms[k] = {value: null, weight: 0, points: 0};
        });
        return {score: null, band: 'nodata', nodata: true, normalized, effective: {...terms}, terms};
    }

    const compliance = (numgraded === 0 || compliancePct === null)
        ? 1.0 : clamp01(compliancePct / 100.0);
    const median = medianEffH === null ? 1.0 : clamp01(1.0 - medianEffH / (2.0 * slagoal));
    const criticalterm = clamp01(1.0 - critical / Math.max(pending, 1));
    const softcap = Math.max(numgraded, 20);
    const pendingterm = clamp01(1.0 - pending / softcap);
    const trend = trendPct === null ? null : clamp01(0.5 - trendPct / 200.0);

    const values = {
        compliance,
        median,
        critical: criticalterm,
        pending: pendingterm,
        trend,
    };
    const available = {
        compliance: true,
        median: true,
        critical: true,
        pending: true,
        trend: trend !== null,
    };
    const effective = effectiveWeights(normalized, available);

    let score = 0;
    TERMS.forEach((k) => {
        score += effective[k] * (values[k] === null ? 0 : values[k]);
    });
    score = Math.round(Math.max(0, Math.min(100, 100 * score)) * 100) / 100;

    const terms = {};
    TERMS.forEach((k) => {
        const value = values[k];
        terms[k] = {
            value,
            weight: effective[k],
            points: value === null ? 0 : effective[k] * value * 100,
        };
    });

    return {
        score,
        band: bandForScore(score, thresholds),
        nodata: false,
        normalized,
        effective,
        terms,
    };
};
