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
 * Interactive Academic Responsiveness Score simulator. A sandbox where a
 * site admin moves sliders for a hypothetical group (and the five score
 * weights) and watches the score + band + term breakdown update live — so
 * they can tune the weights and build intuition before touching the real
 * settings. Pure client-side: nothing is saved.
 *
 * @module    block_feedback_tracker/views/SimulatorView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo} from 'block_feedback_tracker/lib/preact';
import ScoreGauge from 'block_feedback_tracker/components/ScoreGauge';
import Badge from 'block_feedback_tracker/components/Badge';
import {computeScore, TERMS} from 'block_feedback_tracker/lib/score';
import {classifySpeed, speedLabel} from 'block_feedback_tracker/lib/trend';

/** Built-in scenarios — each sets the hypothetical group's metrics. */
const SCENARIOS = [
    {key: 'exemplary', trendna: false,
        inputs: {compliancePct: 96, medianEffH: 6, critical: 0, pending: 3, numgraded30d: 42, trendPct: -12}},
    {key: 'steady', trendna: false,
        inputs: {compliancePct: 82, medianEffH: 18, critical: 1, pending: 8, numgraded30d: 30, trendPct: -3}},
    {key: 'recovering', trendna: false,
        inputs: {compliancePct: 60, medianEffH: 30, critical: 3, pending: 14, numgraded30d: 18, trendPct: -35}},
    {key: 'backlog', trendna: false,
        inputs: {compliancePct: 48, medianEffH: 46, critical: 9, pending: 34, numgraded30d: 22, trendPct: 28}},
    {key: 'starting', trendna: true,
        inputs: {compliancePct: 100, medianEffH: 8, critical: 0, pending: 6, numgraded30d: 3, trendPct: 0}},
    {key: 'empty', trendna: false,
        inputs: {compliancePct: 0, medianEffH: 0, critical: 0, pending: 0, numgraded30d: 0, trendPct: 0}},
];

/** Slider definitions for the scenario metrics. */
const INPUT_FIELDS = [
    {key: 'compliancePct', min: 0, max: 100, step: 1, unit: '%', i18n: 'sim_in_compliance'},
    {key: 'medianEffH', min: 0, max: 120, step: 1, unit: 'h', i18n: 'sim_in_median'},
    {key: 'numgraded30d', min: 0, max: 100, step: 1, unit: '', i18n: 'sim_in_numgraded'},
    {key: 'pending', min: 0, max: 200, step: 1, unit: '', i18n: 'sim_in_pending'},
    {key: 'critical', min: 0, max: 100, step: 1, unit: '', i18n: 'sim_in_critical'},
    {key: 'trendPct', min: -100, max: 100, step: 1, unit: '%', i18n: 'sim_in_trend'},
];

/**
 * A labelled range slider with a live read-out.
 *
 * @param {object} props
 * @param {string} props.label     Field label.
 * @param {number} props.value     Current value.
 * @param {number} props.min       Range minimum.
 * @param {number} props.max       Range maximum.
 * @param {number} props.step      Step increment.
 * @param {string} props.unit      Unit suffix shown next to the value ('' for none).
 * @param {boolean} props.disabled Greys out and shows '—' when true.
 * @param {Function} props.onInput Receives the new numeric value.
 * @param {object} [props.valuenode] Optional custom read-out vnode (e.g. a
 *     directional trend cue), rendered in place of the plain numeric value.
 * @returns {object} vnode
 */
const Slider = ({label, value, min, max, step, unit, disabled, onInput, valuenode}) => html`
    <label class=${'bft-sim-slider' + (disabled ? ' bft-sim-slider-off' : '')}>
        <span class="bft-sim-slider-label">${label}</span>
        <input type="range" min=${min} max=${max} step=${step}
               value=${value} disabled=${disabled}
               onInput=${(e) => onInput(Number(e.target.value))} />
        ${valuenode
            || html`<span class="bft-sim-slider-val bft-mono">${disabled ? '—' : value + (unit ? ' ' + unit : '')}</span>`}
    </label>
`;

/**
 * @param {object} props
 * @param {object} props.initial  Mount payload: {config, i18n}.
 * @returns {object} vnode
 */
// Branch count over the lint cap is acknowledged debt (refactor pass pending).
// eslint-disable-next-line complexity
export default function SimulatorView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const thresholds = config.score_thresholds || null;
    const startWeights = config.weights || {};

    const [weights, setWeights] = useState({
        compliance: Number(startWeights.compliance ?? 0.40),
        median: Number(startWeights.median ?? 0.25),
        critical: Number(startWeights.critical ?? 0.15),
        pending: Number(startWeights.pending ?? 0.10),
        trend: Number(startWeights.trend ?? 0.10),
    });
    const [inputs, setInputs] = useState({...SCENARIOS[1].inputs});
    const [trendNa, setTrendNa] = useState(false);
    const [slaGoal, setSlaGoal] = useState(Number(config.sla_goal_hours ?? 24));
    const [activeScenario, setActiveScenario] = useState('steady');

    const result = useMemo(
        () => computeScore(
            {
                compliancePct: inputs.compliancePct,
                medianEffH: inputs.medianEffH,
                critical: inputs.critical,
                pending: inputs.pending,
                numgraded30d: inputs.numgraded30d,
                trendPct: trendNa ? null : inputs.trendPct,
            },
            weights,
            slaGoal,
            thresholds
        ),
        [inputs, weights, slaGoal, trendNa, thresholds]
    );

    const bandLabel = (i18n.bands || {})[result.band] || '';
    const rawsum = TERMS.reduce((acc, k) => acc + Number(weights[k] || 0), 0);
    const wasrenormalised = rawsum < 0.95 || rawsum > 1.05;

    const applyScenario = (scn) => {
        setInputs({...scn.inputs});
        setTrendNa(scn.trendna);
        setActiveScenario(scn.key);
    };
    const setInput = (key, val) => {
        setInputs((prev) => ({...prev, [key]: val}));
        setActiveScenario(null);
    };
    const setWeight = (key, val) => setWeights((prev) => ({...prev, [key]: val}));
    const resetWeights = () => setWeights({
        compliance: Number(startWeights.compliance ?? 0.40),
        median: Number(startWeights.median ?? 0.25),
        critical: Number(startWeights.critical ?? 0.15),
        pending: Number(startWeights.pending ?? 0.10),
        trend: Number(startWeights.trend ?? 0.10),
    });

    const termName = (k) => (i18n['sim_term_' + k] || k);
    const fmt = (n, d = 2) => (n === null || n === undefined ? '—' : Number(n).toFixed(d));

    return html`
        <div class="bft-sim">
            <section class="bft-sim-intro">
                <div class="bft-sim-intro-eyebrow">${i18n.sim_intro_eyebrow || 'About this score'}</div>
                <h2 class="bft-sim-intro-heading">${i18n.sim_intro_heading || ''}</h2>
                <p class="bft-sim-intro-body">${i18n.sim_intro_body || ''}</p>
                <ul class="bft-sim-criteria">
                    ${TERMS.map((k) => html`
                        <li key=${k}>
                            <strong>${termName(k)}</strong>
                            <span>${i18n['sim_crit_' + k] || ''}</span>
                        </li>
                    `)}
                </ul>
            </section>

            <div class="bft-sim-grid">
                <div class="bft-sim-controls">
                    <section class="bft-sim-panel">
                        <h3 class="bft-sim-panel-title">${i18n.sim_scenarios_heading || 'Scenarios'}</h3>
                        <div class="bft-sim-scenarios">
                            ${SCENARIOS.map((scn) => html`
                                <button type="button" key=${scn.key}
                                        class=${'bft-sim-scn' + (activeScenario === scn.key ? ' is-active' : '')}
                                        onClick=${() => applyScenario(scn)}>
                                    ${i18n['sim_scn_' + scn.key] || scn.key}
                                </button>
                            `)}
                        </div>
                    </section>

                    <section class="bft-sim-panel">
                        <h3 class="bft-sim-panel-title">${i18n.sim_inputs_heading || 'Group situation'}</h3>
                        ${INPUT_FIELDS.map((f) => {
                            const istrend = f.key === 'trendPct';
                            const trenddisabled = istrend && trendNa;
                            // The trend slider runs −100…+100 where the sign is
                            // not self-evident; show the shared speed cue
                            // (▲ faster / ▼ slower / → stable) instead of a bare
                            // signed number, matching the dashboard hero.
                            let valuenode = null;
                            if (istrend && !trenddisabled) {
                                const sp = classifySpeed(inputs.trendPct);
                                const tone = ' bft-overall-score-tone-' + sp.colour;
                                valuenode = html`
                                    <span class="bft-sim-slider-valwrap">
                                        <span class=${'bft-sim-slider-val bft-mono' + tone}>
                                            ${sp.arrow} ${sp.magnitude}
                                        </span>
                                        <span class=${'bft-sim-slider-hint' + tone}>
                                            ${speedLabel(sp.tone, i18n)}
                                        </span>
                                    </span>`;
                            }
                            return html`
                                <${Slider} key=${f.key}
                                    label=${i18n[f.i18n] || f.key}
                                    value=${inputs[f.key]}
                                    min=${f.min} max=${f.max} step=${f.step} unit=${f.unit}
                                    disabled=${trenddisabled}
                                    valuenode=${valuenode}
                                    onInput=${(v) => setInput(f.key, v)} />`;
                        })}
                        <label class="bft-sim-check">
                            <input type="checkbox" checked=${trendNa}
                                   onChange=${(e) => {
                                       setTrendNa(e.target.checked);
                                       setActiveScenario(null);
                                   }} />
                            <span>${i18n.sim_in_trend_unavailable || 'Trend unavailable (course just started)'}</span>
                        </label>
                        <${Slider}
                            label=${i18n.sim_in_slagoal || 'SLA goal (hours)'}
                            value=${slaGoal} min=${1} max=${120} step=${1} unit="h"
                            disabled=${false}
                            onInput=${(v) => {
                                setSlaGoal(v);
                                setActiveScenario(null);
                            }} />
                    </section>

                    <section class="bft-sim-panel">
                        <h3 class="bft-sim-panel-title">${i18n.sim_weights_heading || 'Weights'}</h3>
                        ${TERMS.map((k) => html`
                            <${Slider} key=${k}
                                label=${termName(k)}
                                value=${Number(weights[k].toFixed(2))}
                                min=${0} max=${1} step=${0.01} unit=""
                                disabled=${false}
                                onInput=${(v) => setWeight(k, v)} />
                        `)}
                        <div class="bft-sim-weights-foot">
                            <span class="bft-mono">Σ ${fmt(rawsum, 2)}</span>
                            ${wasrenormalised && html`
                                <span class="bft-sim-weights-note">
                                    ${(i18n.sim_weights_normalized || 'Normalised to 1.0 for the score')}
                                </span>
                            `}
                            <button type="button" class="bft-sim-reset" onClick=${resetWeights}>
                                ${i18n.sim_weights_reset || 'Reset to current settings'}
                            </button>
                        </div>
                    </section>
                </div>

                <aside class="bft-sim-result">
                    <div class="bft-sim-result-sticky">
                        <div class=${'bft-sim-gauge bft-sim-tone-' + (result.band || 'pending')}>
                            <${ScoreGauge} score=${result.score} band=${result.band} size=${168}
                                arialabel=${i18n.gauge_aria || ''} />
                            <${Badge} band=${result.band} label=${bandLabel} />
                        </div>

                        ${result.nodata
                            ? html`<p class="bft-sim-nodata">${i18n.sim_nodata || ''}</p>`
                            : html`
                                <table class="bft-sim-breakdown">
                                    <thead>
                                        <tr>
                                            <th>${i18n.breakdown_term || 'Term'}</th>
                                            <th class="bft-breakdown-num">${i18n.breakdown_value || 'Value'}</th>
                                            <th class="bft-breakdown-num">${i18n.breakdown_weight || 'Weight'}</th>
                                            <th class="bft-breakdown-num">${i18n.breakdown_pts || 'Points'}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${TERMS.map((k) => {
                                            const t = result.terms[k];
                                            const dropped = t.value === null;
                                            return html`
                                                <tr key=${k} class=${dropped ? 'bft-sim-term-off' : ''}>
                                                    <td>${termName(k)}</td>
                                                    <td class="bft-breakdown-num bft-mono">${fmt(t.value, 2)}</td>
                                                    <td class="bft-breakdown-num bft-mono">
                                                        ${(t.weight * 100).toFixed(0)}%
                                                    </td>
                                                    <td class="bft-breakdown-num bft-mono">${fmt(t.points, 1)}</td>
                                                </tr>
                                            `;
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="3">${i18n.breakdown_total || 'Total'}</th>
                                            <th class="bft-breakdown-num bft-mono">${fmt(result.score, 0)}</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            `}
                        ${!result.nodata && trendNa && html`
                            <p class="bft-sim-foot-note">${i18n.sim_trend_dropped_note || ''}</p>
                        `}
                    </div>
                </aside>
            </div>
        </div>
    `;
}
