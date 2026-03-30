<script setup>
import { ref, nextTick, watch } from 'vue';
import Papa from 'papaparse';
import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';

const errorMsg = ref('');
const fullData = ref([]);
const availableLaps = ref([]);
const selectedLap = ref(null);

// MODO COMPARACIÓN
const compareMode = ref(false);
const compareLap = ref(null);

// Contenedores HTML
const speedChartContainer = ref(null);
const rpmChartContainer = ref(null);
const throttleChartContainer = ref(null);
const brakeChartContainer = ref(null);
const mapCanvas = ref(null); 

// Instancias uPlot
let uplotSpeed = null;
let uplotRPM = null;
let uplotThrottle = null;
let uplotBrake = null;
const syncKey = uPlot.sync("telemetrySync");

// Variables del Mapa
let trackPath = [];
let visibleTrackPath = [];
let mapMinX = 0, mapMinZ = 0, mapScaleVal = 1, mapPad = 20;
let mapCanvasW = 220, mapCanvasH = 220;
const mapAxisLabel = ref('');

const formatTime = (totalSeconds) => {
    if (!totalSeconds) return "--:--.---";
    const m = Math.floor(totalSeconds / 60);
    const s = Math.floor(totalSeconds % 60);
    const ms = Math.floor((totalSeconds % 1) * 1000);
    if (m === 0) return `${s.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
    return `${m}:${s.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
};

const handleFileUpload = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    errorMsg.value = '';
    compareMode.value = false;
    compareLap.value = null;
    
    const reader = new FileReader();
    reader.onload = (e) => {
        const text = e.target.result;
        const lines = text.split('\n');
        
        let headerIndex = -1;
        let rawLapTimes = [];

        for (let i = 0; i < lines.length; i++) {
            if (lines[i].startsWith('lapTimes,')) {
                const timesStr = lines[i].replace('lapTimes,', '').replace(/"/g, '').trim();
                rawLapTimes = timesStr.split(',').map(Number);
            }
            if (lines[i].startsWith('time,Last Sector Time')) {
                headerIndex = i;
                break;
            }
        }

        if (headerIndex === -1) {
            errorMsg.value = "No se encontró la cabecera de telemetría.";
            return;
        }

        const headerLine = lines[headerIndex];
        const dataLines = lines.slice(headerIndex + 2).join('\n');
        
        Papa.parse(headerLine + '\n' + dataLines, {
            header: true,
            dynamicTyping: true,
            skipEmptyLines: true,
            complete: (results) => {
                if (results.data && results.data.length > 0) {
                    processTelemetry(results.data, rawLapTimes);
                } else {
                    errorMsg.value = "No se pudieron extraer los datos.";
                }
            }
        });
    };
    reader.readAsText(file);
};

const processTelemetry = async (data, rawLapTimes) => {
    const validData = data.filter(r => r['Lap Number'] !== null && r['Lap Number'] !== undefined);
    fullData.value = validData;

    const lapNumbers = [...new Set(validData.map(r => Math.floor(r['Lap Number'])))].sort((a, b) => a - b);
    
    availableLaps.value = lapNumbers.map((lapNum, index) => {
        const timeInSeconds = rawLapTimes[index];
        return { number: lapNum, timeRaw: timeInSeconds, timeFormatted: formatTime(timeInSeconds) };
    });

    if (availableLaps.value.length > 0) {
        selectedLap.value = availableLaps.value.length > 1 ? availableLaps.value[1] : availableLaps.value[0];
        await nextTick();
        drawCharts();
    }
};

// Extractor robusto de columnas
const getVal = (row, cols) => {
    for (let c of cols) {
        if (row[c] !== undefined && row[c] !== null && row[c] !== '') return Number(row[c]);
    }
    return null;
};

const normalizeKey = (value) => String(value || '').trim().toLowerCase().replace(/[\s_-]+/g, ' ');

const findColumnByAliases = (keys, aliases) => {
    const normalizedMap = new Map(keys.map(key => [normalizeKey(key), key]));

    for (const alias of aliases) {
        const exactMatch = normalizedMap.get(normalizeKey(alias));
        if (exactMatch) return exactMatch;
    }

    for (const key of keys) {
        const normalizedKey = normalizeKey(key);
        if (aliases.some(alias => normalizedKey.includes(normalizeKey(alias)))) {
            return key;
        }
    }

    return null;
};

const buildCoordinateCandidates = (keys) => ({
    x: findColumnByAliases(keys, ['Car Coord X', 'World Position X', 'Pos X', 'Position X', 'Local Position X', 'X']),
    y: findColumnByAliases(keys, ['Car Coord Y', 'World Position Y', 'Pos Y', 'Position Y', 'Local Position Y', 'Y']),
    z: findColumnByAliases(keys, ['Car Coord Z', 'World Position Z', 'Pos Z', 'Position Z', 'Local Position Z', 'Z']),
    lat: findColumnByAliases(keys, ['Latitude', 'Lat', 'GPS Lat']),
    lon: findColumnByAliases(keys, ['Longitude', 'Long', 'Lon', 'GPS Lon', 'GPS Long']),
});

const getStepStats = (points) => {
    const steps = [];

    for (let i = 1; i < points.length; i++) {
        const dx = points[i].x - points[i - 1].x;
        const dz = points[i].z - points[i - 1].z;
        const step = Math.sqrt(dx ** 2 + dz ** 2);
        if (Number.isFinite(step) && step > 0) steps.push(step);
    }

    if (steps.length === 0) return { median: 0, p90: 0 };

    const sorted = [...steps].sort((a, b) => a - b);
    const pick = (ratio) => sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * ratio))];

    return {
        median: pick(0.5),
        p90: pick(0.9),
    };
};

const simplifyTrackPath = (points) => {
    if (points.length <= 2) return points;

    const { median, p90 } = getStepStats(points);
    const maxJump = Math.max(median * 12, p90 * 4, 5);
    const filtered = [points[0]];

    for (let i = 1; i < points.length; i++) {
        const last = filtered[filtered.length - 1];
        const current = points[i];
        const jump = Math.sqrt((current.x - last.x) ** 2 + (current.z - last.z) ** 2);

        if (!Number.isFinite(jump) || jump > maxJump) continue;
        filtered.push(current);
    }

    return filtered;
};

const scoreTrackShape = (points) => {
    if (points.length < 20) return -Infinity;

    const xs = points.map(p => p.x);
    const zs = points.map(p => p.z);
    const rangeX = Math.max(...xs) - Math.min(...xs);
    const rangeZ = Math.max(...zs) - Math.min(...zs);

    if (!Number.isFinite(rangeX) || !Number.isFinite(rangeZ) || rangeX <= 0 || rangeZ <= 0) {
        return -Infinity;
    }

    const aspectRatio = Math.max(rangeX, rangeZ) / Math.max(Math.min(rangeX, rangeZ), 1e-6);
    const { median } = getStepStats(points);

    let turns = 0;
    for (let i = 2; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];
        const c = points[i - 2];
        const cross = (a.x - c.x) * (b.z - a.z) - (a.z - c.z) * (b.x - a.x);
        if (Math.abs(cross) > median * median * 0.2) turns++;
    }

    return Math.log10(rangeX * rangeZ + 1) * 20 + Math.min(turns, 300) - Math.max(0, aspectRatio - 6) * 25;
};

const buildTrackPathForPair = (lapRows, horizontalCol, verticalCol) => {
    const rawPoints = [];

    lapRows.forEach(row => {
        const x = Number(row[horizontalCol]);
        const z = Number(row[verticalCol]);
        const dist = Number(row['Lap Distance'] || 0);

        if (!Number.isFinite(x) || !Number.isFinite(z)) return;
        if (x === 0 && z === 0) return;

        rawPoints.push({ dist, x, z });
    });

    return simplifyTrackPath(rawPoints);
};

const resolveBestTrackPath = (lapRows) => {
    if (!lapRows.length) return { path: [], label: '' };

    const keys = Object.keys(lapRows[0] || {});
    const candidateColumns = buildCoordinateCandidates(keys);
    const pairs = [
        ['x', 'z'],
        ['x', 'y'],
        ['y', 'z'],
        ['lon', 'lat'],
    ];

    let best = { score: -Infinity, path: [], label: '' };

    pairs.forEach(([firstAxis, secondAxis]) => {
        const firstCol = candidateColumns[firstAxis];
        const secondCol = candidateColumns[secondAxis];
        if (!firstCol || !secondCol) return;

        const path = buildTrackPathForPair(lapRows, firstCol, secondCol);
        const score = scoreTrackShape(path);

        if (score > best.score) {
            best = {
                score,
                path,
                label: `${firstCol} / ${secondCol}`,
            };
        }
    });

    return best;
};

// --- LÓGICA DEL MAPA 2D ---
const updateMapViewport = (minDist = null, maxDist = null) => {
    if (!trackPath.length) {
        visibleTrackPath = [];
        return;
    }

    let nextPath = trackPath;

    if (Number.isFinite(minDist) && Number.isFinite(maxDist) && maxDist > minDist) {
        const filtered = trackPath.filter(point => point.dist >= minDist && point.dist <= maxDist);

        if (filtered.length >= 2) {
            let startPoint = filtered[0];
            let endPoint = filtered[filtered.length - 1];

            for (const point of trackPath) {
                if (point.dist <= minDist) startPoint = point;
                if (point.dist >= maxDist) {
                    endPoint = point;
                    break;
                }
            }

            nextPath = [startPoint, ...filtered, endPoint];
        }
    }

    visibleTrackPath = nextPath;

    const xs = visibleTrackPath.map(p => p.x);
    const zs = visibleTrackPath.map(p => p.z);
    mapMinX = Math.min(...xs);
    mapMinZ = Math.min(...zs);
    const rangeX = Math.max(...xs) - mapMinX;
    const rangeZ = Math.max(...zs) - mapMinZ;

    if (rangeX === 0 || rangeZ === 0) {
        mapScaleVal = 1;
        return;
    }

    mapPad = 20;
    mapScaleVal = Math.min(
        (mapCanvasW - mapPad * 2) / rangeX,
        (mapCanvasH - mapPad * 2) / rangeZ
    );
};

const prepareMapData = (lapNumber) => {
    if (!mapCanvas.value) return;

    // Fijamos el tamaño real del canvas en píxeles
    mapCanvasW = mapCanvas.value.offsetWidth || 220;
    mapCanvasH = mapCanvas.value.offsetHeight || 220;
    mapCanvas.value.width  = mapCanvasW;
    mapCanvas.value.height = mapCanvasH;

    const rawLap = fullData.value
        .filter(r => Math.floor(r['Lap Number']) === lapNumber)
        .sort((a, b) => Number(a.time || 0) - Number(b.time || 0));

    const bestTrack = resolveBestTrackPath(rawLap);
    trackPath = bestTrack.path;
    mapAxisLabel.value = bestTrack.label;

    if (trackPath.length === 0) {
        visibleTrackPath = [];
        return;
    }

    updateMapViewport();

    renderMap();
};

const renderMap = (dotDist = null) => {
    if (!mapCanvas.value || visibleTrackPath.length === 0) return;
    const ctx = mapCanvas.value.getContext('2d');
    const w = mapCanvas.value.width;
    const h = mapCanvas.value.height;
    ctx.clearRect(0, 0, w, h);

    // Centramos el dibujo dentro del canvas
    const rangeX = (Math.max(...visibleTrackPath.map(p => p.x)) - mapMinX) * mapScaleVal;
    const rangeZ = (Math.max(...visibleTrackPath.map(p => p.z)) - mapMinZ) * mapScaleVal;
    const offX = (w - rangeX) / 2;
    const offZ = (h - rangeZ) / 2;

    // (x - minX) * scale + offset → coordenada canvas correcta
    // Invertimos Z para que el norte quede arriba
    const toCanvas = (x, z) => [
        offX + (x - mapMinX) * mapScaleVal,
        h - offZ - (z - mapMinZ) * mapScaleVal
    ];

    // Dibujar pista
    ctx.beginPath();
    ctx.strokeStyle = '#374151';
    ctx.lineWidth = 4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    visibleTrackPath.forEach((pt, idx) => {
        const [px, py] = toCanvas(pt.x, pt.z);
        if (idx === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
    });
    ctx.stroke();

    // Punto del coche
    if (dotDist !== null) {
        const closest = visibleTrackPath.reduce((prev, curr) =>
            Math.abs(curr.dist - dotDist) < Math.abs(prev.dist - dotDist) ? curr : prev
        );
        if (closest) {
            const [px, py] = toCanvas(closest.x, closest.z);
            ctx.beginPath();
            ctx.fillStyle = '#ef4444';
            ctx.arc(px, py, 6, 0, Math.PI * 2);
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();
        }
    }
};

const normalizeLapByDistance = (lapNumber) => {
    let lapData = fullData.value
        .filter(r => Math.floor(r['Lap Number']) === lapNumber)
        .sort((a, b) => Number(a['time']) - Number(b['time']));

    const normalized = {};
    let lastValidDist = 0;
    let maxDist = 0;

    lapData.forEach(row => {
        let dist = Number(row['Lap Distance'] || 0);
        if (dist < lastValidDist && (lastValidDist - dist) > 1000) return; 
        lastValidDist = dist;

        const distInt = Math.floor(dist);
        if (distInt > maxDist) maxDist = distInt;

        if (!normalized[distInt]) {
            normalized[distInt] = {
                speed: Number(row['Ground Speed'] || 0),
                rpm: Number(row['Engine RPM'] || 0),
                throttle: Number(row['Throttle Pos'] || 0),
                brake: Number(row['Brake Pos'] || 0)
            };
        }
    });

    return { data: normalized, maxDist };
};

const drawCharts = () => {
    if (!selectedLap.value || !speedChartContainer.value) return;

    prepareMapData(selectedLap.value.number); 

    const lapA = normalizeLapByDistance(selectedLap.value.number);
    let lapB = null;

    if (compareMode.value && compareLap.value) {
        lapB = normalizeLapByDistance(compareLap.value.number);
    }

    const totalDistance = Math.max(lapA.maxDist, lapB ? lapB.maxDist : 0);
    
    const xDistances = [];
    const speedA = [], speedB = [];
    const rpmA = [], rpmB = [];
    const throttleA = [], throttleB = [];
    const brakeA = [], brakeB = [];

    let lastA = { speed: 0, rpm: 0, throttle: 0, brake: 0 };
    let lastB = { speed: 0, rpm: 0, throttle: 0, brake: 0 };

    for (let i = 0; i <= totalDistance; i++) {
        xDistances.push(i);
        
        if (lapA.data[i]) lastA = lapA.data[i];
        speedA.push(lastA.speed);
        rpmA.push(lastA.rpm);
        throttleA.push(lastA.throttle);
        brakeA.push(lastA.brake);

        if (lapB) {
            if (lapB.data[i]) lastB = lapB.data[i];
            speedB.push(lastB.speed);
            rpmB.push(lastB.rpm);
            throttleB.push(lastB.throttle);
            brakeB.push(lastB.brake);
        }
    }

    const chartWidth = speedChartContainer.value.clientWidth || 800;
    
    const commonOpts = {
        width: chartWidth,
        cursor: { sync: { key: syncKey.key }, drag: { x: true, y: false } },
        scales: { x: { time: false } },
        hooks: {
            setScale: [
                (u, key) => {
                    if (key !== 'x') return;
                    updateMapViewport(u.scales.x.min, u.scales.x.max);
                    const idx = u.cursor.idx;
                    const dist = idx !== null && idx !== undefined ? u.data[0][idx] : null;
                    renderMap(dist);
                }
            ],
            setCursor: [
                (u) => {
                    const idx = u.cursor.idx;
                    if (idx !== null && idx !== undefined && u.data[0][idx] !== undefined) {
                        renderMap(u.data[0][idx]);
                    }
                }
            ]
        }
    };

    const getSeries = (label, colorA, colorB) => {
        const series = [
            { label: "Dist. (m)" },
            { label: `${label} (V${selectedLap.value.number})`, stroke: colorA, width: 2 }
        ];
        if (lapB) series.push({ label: `${label} (V${compareLap.value.number})`, stroke: colorB, width: 2, dash: [5, 5] });
        return series;
    };

    const getData = (arrA, arrB) => lapB ? [xDistances, arrA, arrB] : [xDistances, arrA];

    if (uplotSpeed) uplotSpeed.destroy();
    uplotSpeed = new uPlot({
        ...commonOpts, height: 200,
        series: getSeries("Speed", "#4ade80", "#d1fae5"),
        axes: [{ show: false }, { stroke: "#4ade80", grid: { stroke: "#374151" } }]
    }, getData(speedA, speedB), speedChartContainer.value);

    if (uplotRPM) uplotRPM.destroy();
    uplotRPM = new uPlot({
        ...commonOpts, height: 150,
        series: getSeries("RPM", "#60a5fa", "#dbeafe"),
        axes: [{ show: false }, { stroke: "#60a5fa", grid: { stroke: "#374151" } }]
    }, getData(rpmA, rpmB), rpmChartContainer.value);

    if (uplotThrottle) uplotThrottle.destroy();
    uplotThrottle = new uPlot({
        ...commonOpts, height: 120,
        scales: { ...commonOpts.scales, y: { range: [0, 105] } },
        series: getSeries("Throttle", "#facc15", "#fef08a"),
        axes: [{ show: false }, { stroke: "#facc15", grid: { stroke: "#374151" } }]
    }, getData(throttleA, throttleB), throttleChartContainer.value);

    if (uplotBrake) uplotBrake.destroy();
    uplotBrake = new uPlot({
        ...commonOpts, height: 120,
        scales: { ...commonOpts.scales, y: { range: [0, 105] } },
        series: getSeries("Brake", "#f87171", "#fee2e2"),
        axes: [{ stroke: "#9ca3af", grid: { stroke: "#374151" } }, { stroke: "#f87171", grid: { stroke: "#374151" } }]
    }, getData(brakeA, brakeB), brakeChartContainer.value);
};

watch(selectedLap, () => nextTick(drawCharts));
watch(compareMode, () => nextTick(drawCharts));
watch(compareLap, () => nextTick(drawCharts));

const selectLap = (lap) => { selectedLap.value = lap; };
</script>

<template>
    <div class="min-h-screen bg-[#0f1115] text-white p-6 font-sans flex flex-col">
        <div class="max-w-[1400px] w-full mx-auto mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-black text-white tracking-tight">SimTelemetry <span class="text-orange-500">PRO</span></h1>
                <p class="text-gray-400 text-sm mt-1">Análisis de telemetría avanzado</p>
            </div>
            <div class="relative bg-gray-800 border border-gray-700 rounded-lg px-6 py-3 hover:border-orange-500 transition-colors cursor-pointer group shadow-lg">
                <input type="file" @change="handleFileUpload" accept=".csv" class="absolute inset-0 opacity-0 cursor-pointer" />
                <span class="text-sm font-bold text-gray-300 group-hover:text-orange-400 flex items-center gap-2">
                    Cargar RAW CSV
                </span>
            </div>
        </div>

        <div v-if="errorMsg" class="max-w-[1400px] w-full mx-auto mb-4 p-4 bg-red-900 border border-red-500 rounded text-red-200 text-sm">
            {{ errorMsg }}
        </div>

        <div v-if="availableLaps.length > 0" class="max-w-[1400px] w-full mx-auto flex gap-6 flex-1 min-h-0">
            <div class="w-64 flex flex-col shrink-0 gap-4">
                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl flex flex-col items-center">
                    <h2 class="text-gray-300 font-bold text-sm uppercase mb-3 self-start w-full border-b border-gray-800 pb-2">Track Map</h2>
                    <canvas ref="mapCanvas" width="220" height="220" class="bg-gray-900/50 rounded-lg border border-gray-800"></canvas>
                    <p v-if="mapAxisLabel" class="mt-2 self-start text-[11px] text-gray-500">
                        {{ mapAxisLabel }}
                    </p>
                </div>

                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl overflow-hidden shadow-2xl flex-1 flex flex-col min-h-[200px]">
                    <div class="bg-gray-800/50 p-4 border-b border-gray-800">
                        <h2 class="text-gray-300 font-bold text-sm uppercase tracking-wider">Vuelta Principal</h2>
                    </div>
                    <ul class="flex-1 overflow-y-auto p-2 space-y-1">
                        <li v-for="lap in availableLaps" :key="lap.number" @click="selectLap(lap)"
                            class="flex justify-between items-center p-3 rounded-lg cursor-pointer transition-all duration-200"
                            :class="selectedLap.number === lap.number ? 'bg-orange-600/20 border border-orange-500 text-white' : 'hover:bg-gray-800 border border-transparent text-gray-400'">
                            <span class="font-bold text-sm">Lap {{ lap.number }}</span>
                            <span class="font-mono text-sm">{{ lap.timeFormatted }}</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-gray-300 font-bold text-sm uppercase">Comparar</span>
                        <button @click="compareMode = !compareMode" 
                                :class="compareMode ? 'bg-orange-500 text-white' : 'bg-gray-700 text-gray-300'"
                                class="px-3 py-1 rounded text-xs font-bold transition-colors">
                            {{ compareMode ? 'ON' : 'OFF' }}
                        </button>
                    </div>
                    
                    <select v-if="compareMode" v-model="compareLap" class="w-full bg-gray-900 border border-gray-700 text-white text-sm rounded-lg px-3 py-2 outline-none">
                        <option :value="null" disabled>Elige vuelta a comparar...</option>
                        <option v-for="lap in availableLaps" :key="'comp-'+lap.number" :value="lap" :disabled="lap.number === selectedLap.number">
                            Lap {{ lap.number }} ({{ lap.timeFormatted }})
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex-1 bg-[#1a1d24] border border-gray-800 rounded-xl p-6 shadow-2xl min-w-0">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-200">
                        Vuelta {{ selectedLap.number }} 
                        <span v-if="compareMode && compareLap" class="text-orange-400 ml-2">vs Vuelta {{ compareLap.number }}</span>
                    </h2>
                </div>
                <div class="flex flex-col space-y-2">
                    <div ref="speedChartContainer" class="w-full"></div>
                    <div ref="rpmChartContainer" class="w-full"></div>
                    <div ref="throttleChartContainer" class="w-full"></div>
                    <div ref="brakeChartContainer" class="w-full"></div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.uplot { font-family: ui-monospace, monospace; }
.u-legend { color: #9ca3af; font-size: 12px; padding-bottom: 5px; }
.u-value { color: #fff; font-weight: bold; }
ul::-webkit-scrollbar { width: 6px; }
ul::-webkit-scrollbar-track { background: transparent; }
ul::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 10px; }
</style>

