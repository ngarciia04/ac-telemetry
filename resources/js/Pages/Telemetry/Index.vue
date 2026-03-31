<script setup>
import { ref, nextTick, watch, onMounted, computed } from 'vue'; 
import Papa from 'papaparse';
import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';
import axios from 'axios';

const errorMsg = ref('');
const fullData = ref([]);
const availableLaps = ref([]);
const selectedLap = ref(null);
const savedSessions = ref([]); 

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

// Paleta de colores personalizada
const COLOR_MAIN = "#730F39"; // Cereza oscuro
const COLOR_SEC = "#F1CEDA";  // Rosa claro

const formatTime = (totalSeconds) => {
    if (!totalSeconds || isNaN(totalSeconds)) return "--:--.---";
    const m = Math.floor(totalSeconds / 60);
    const s = Math.floor(totalSeconds % 60);
    const ms = Math.floor((totalSeconds % 1) * 1000);
    if (m === 0) return `${s.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
    return `${m}:${s.toString().padStart(2, '0')}.${ms.toString().padStart(3, '0')}`;
};

const formatDiff = (main, comp) => {
    if (!main || !comp || isNaN(main) || isNaN(comp)) return '';
    const diff = main - comp;
    const sign = diff > 0 ? '+' : '';
    return `${sign}${diff.toFixed(3)}`;
};

const getDiffClass = (main, comp) => {
    if (!main || !comp || isNaN(main) || isNaN(comp)) return 'text-gray-500';
    return main > comp ? 'text-red-400' : 'text-green-400';
};

const idealLap = computed(() => {
    if (!availableLaps.value.length) return null;
    const eligibleLaps = availableLaps.value.filter(l => l.isValid && l.idealEligible);
    const s1s = eligibleLaps.map(l => l.sectors?.s1).filter(s => s > 0);
    const s2s = eligibleLaps.map(l => l.sectors?.s2).filter(s => s > 0);
    const s3s = eligibleLaps.map(l => l.sectors?.s3).filter(s => s > 0);
    
    const bestS1 = s1s.length ? Math.min(...s1s) : 0;
    const bestS2 = s2s.length ? Math.min(...s2s) : 0;
    const bestS3 = s3s.length ? Math.min(...s3s) : 0;
    
    if (bestS1 && bestS2 && bestS3) {
        return { s1: bestS1, s2: bestS2, s3: bestS3, total: bestS1 + bestS2 + bestS3 };
    }
    return null;
});

const fetchSessions = async () => {
    try {
        const response = await axios.get('/telemetry/sessions');
        savedSessions.value = response.data;
    } catch (error) {
        console.error("Error cargando sesiones:", error);
    }
};

onMounted(() => {
    fetchSessions();
});

const loadSession = async (session) => {
    errorMsg.value = `Cargando sesión: ${session.original_filename}...`;
    try {
        const response = await axios.get(`/telemetry/${session.id}`);
        const csvText = response.data;
        
        const lines = csvText.split('\n');
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
            errorMsg.value = "Error al leer el formato del archivo guardado.";
            return;
        }

        const headerLine = lines[headerIndex];
        const dataLines = lines.slice(headerIndex + 2).join('\n');

        Papa.parse(headerLine + '\n' + dataLines, {
            header: true,
            dynamicTyping: true,
            skipEmptyLines: true,
            transformHeader: (h) => h.trim(),
            complete: (results) => {
                processTelemetry(results.data, rawLapTimes);
                errorMsg.value = ""; 
            }
        });
    } catch (error) {
        console.error("Error al cargar sesión:", error);
        errorMsg.value = "No se pudo recuperar el archivo del servidor.";
    }
};

const saveSessionToServer = async (file, bestLap) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('circuit_name', 'Circuito Detectado'); 
    formData.append('car_name', 'Coche Telemetría');
    if (bestLap) formData.append('best_lap_time', bestLap.timeRaw);

    try {
        await axios.post('/telemetry/upload', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            withCredentials: true 
        });
        await fetchSessions(); 
    } catch (error) {
        console.error('❌ Error de subida:', error.message);
    }
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

        Papa.parse(lines[headerIndex] + '\n' + lines.slice(headerIndex + 2).join('\n'), {
            header: true,
            dynamicTyping: true,
            skipEmptyLines: true,
            transformHeader: (h) => h.trim(),
            complete: (results) => {
                if (results.data && results.data.length > 0) {
                    processTelemetry(results.data, rawLapTimes);
                    const validOnly = availableLaps.value.filter(l => l.isValid);
                    const bestLap = validOnly.length > 0 
                        ? validOnly.reduce((prev, curr) => (prev.timeRaw < curr.timeRaw) ? prev : curr)
                        : null;
                    saveSessionToServer(file, bestLap);
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
    
    const lapsRaw = lapNumbers.map((lapNum, index) => {
        const timeInSeconds = rawLapTimes[index];
        const lapRows = validData.filter(r => Math.floor(r['Lap Number']) === lapNum);
        const maxDist = lapRows.length > 0 ? Math.max(...lapRows.map(r => Number(r['Lap Distance'] || 0))) : 0;
        
        return { 
            number: lapNum, 
            timeRaw: timeInSeconds, 
            timeFormatted: formatTime(timeInSeconds),
            maxDist: maxDist
        };
    });

    const distances = lapsRaw.map(l => l.maxDist).filter(d => d > 0).sort((a, b) => a - b);
    const medianDist = distances[Math.floor(distances.length / 2)];
    
    const validatedLaps = lapsRaw.map(lap => {
        const lapRows = validData.filter(r => Math.floor(r['Lap Number']) === lap.number);
        
        const hasOfftrack = lapRows.some(row => 
            Number(row['isLapInvalidated']) === 1 || 
            Number(row['Lap Invalidated']) === 1
        );

        const isNotOutLap = lap.number > 0;
        const hasCorrectDistance = lap.maxDist > (medianDist * 0.95) && lap.maxDist < (medianDist * 1.05);
        const hasRealisticTime = lap.timeRaw > 40; 

        const isActuallyValid = isNotOutLap && hasCorrectDistance && hasRealisticTime && !hasOfftrack;
        
        // --- Cálculo Definitivo de Sectores (Usando los cambios de Last Sector Time) ---
        const sectorData = getSectorTimesForLap(lapRows, lap.timeRaw);
        const lastSectorCol = null;
        let s1 = null, s2 = null, s3 = null;


        if (lastSectorCol && lapRows.length > 0) {
            const sectorTimes = [];
            // Empezamos asumiendo que el valor inicial es un arrastre de la vuelta anterior
            let currentSecVal = Number(lapRows[0][lastSectorCol] || 0);

            for (const row of lapRows) {
                const val = Number(row[lastSectorCol] || 0);
                // Si el valor cambia, significa que acabamos de completar un sector
                if (val !== currentSecVal) {
                    // Solo lo guardamos si dura más de 5 segundos (ignora fantasmas de 0.8s)
                    if (val > 5) {
                        sectorTimes.push(val);
                    }
                    currentSecVal = val;
                }
            }

            // Asignación estricta por el orden en que ocurrieron los cambios
            if (sectorTimes.length >= 1) s1 = sectorTimes[0];
            if (sectorTimes.length >= 2) s2 = sectorTimes[1];
            if (sectorTimes.length >= 3) s3 = sectorTimes[2];
        }

        // Si falta el S3 (a veces se registra en el primer frame de la vuelta siguiente), lo deducimos
        if (s1 && s2 && (!s3 || s3 <= 0) && lap.timeRaw > (s1 + s2)) {
            s3 = lap.timeRaw - s1 - s2;
        }

        return { 
            ...lap, 
            isValid: isActuallyValid,
            isOfftrack: hasOfftrack,
            isIncomplete: !isNotOutLap || !hasCorrectDistance || !hasRealisticTime,
            sectors: { s1: sectorData.s1, s2: sectorData.s2, s3: sectorData.s3 },
            idealEligible: sectorData.isReliable
        };
    });

    const validOnly = validatedLaps.filter(l => l.isValid && l.timeRaw > 0);
    const bestTime = validOnly.length > 0 ? Math.min(...validOnly.map(l => l.timeRaw)) : null;

    availableLaps.value = validatedLaps.map(lap => ({
        ...lap,
        isPurple: bestTime && lap.timeRaw === bestTime && lap.isValid
    }));

    if (availableLaps.value.length > 0) {
        const purple = availableLaps.value.find(l => l.isPurple);
        const firstValid = availableLaps.value.find(l => l.isValid);
        selectedLap.value = purple || firstValid || availableLaps.value[0];
        await nextTick();
        drawCharts();
    }
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
        if (aliases.some(alias => normalizedKey.includes(normalizeKey(alias)))) return key;
    }
    return null;
};

const findSectorColumn = (keys, aliases) => {
    const excludedTokens = ['best', 'optimal', 'ideal', 'theoretical', 'theoretical', 'session best', 'personal best'];

    for (const key of keys) {
        const normalizedKey = normalizeKey(key);
        const isAliasMatch = aliases.some(alias => normalizedKey === normalizeKey(alias));
        const hasExcludedToken = excludedTokens.some(token => normalizedKey.includes(token));

        if (isAliasMatch && !hasExcludedToken) {
            return key;
        }
    }

    return null;
};

const dedupeStableValues = (values, tolerance = 0.01) => {
    const deduped = [];

    values.forEach(value => {
        if (!Number.isFinite(value) || value <= 0) return;

        const last = deduped[deduped.length - 1];
        if (last === undefined || Math.abs(last - value) > tolerance) {
            deduped.push(value);
        }
    });

    return deduped;
};

const getStableColumnValue = (lapRows, columnName) => {
    if (!columnName) return null;

    const values = dedupeStableValues(lapRows
        .map(row => Number(row[columnName]))
        .filter(value => Number.isFinite(value) && value > 0));

    if (!values.length) return null;

    return values[values.length - 1];
};

const getSectorTimesForLap = (lapRows, lapTime) => {
    if (!lapRows.length) return { s1: null, s2: null, s3: null, isReliable: false };

    const keys = Object.keys(lapRows[0] || {});
    const sector1Col = findSectorColumn(keys, ['Sector 1 Time', 'Sector1 Time', 'Sector1Time']);
    const sector2Col = findSectorColumn(keys, ['Sector 2 Time', 'Sector2 Time', 'Sector2Time']);
    const sector3Col = findSectorColumn(keys, ['Sector 3 Time', 'Sector3 Time', 'Sector3Time']);

    let s1 = getStableColumnValue(lapRows, sector1Col);
    let s2 = getStableColumnValue(lapRows, sector2Col);
    let s3 = getStableColumnValue(lapRows, sector3Col);

    const hasDirectSectorColumns = [s1, s2, s3].some(value => value !== null);

    if (!hasDirectSectorColumns) {
        const lastSectorCol = findColumnByAliases(keys, ['Last Sector Time', 'LastSectorTime']);

        if (lastSectorCol) {
            const rawValues = lapRows
                .map(row => Number(row[lastSectorCol]))
                .filter(value => Number.isFinite(value) && value > 0);

            const stableValues = dedupeStableValues(rawValues);
            const sectorValues = stableValues.length > 1 ? stableValues.slice(1) : stableValues;

            s1 = sectorValues[0] ?? null;
            s2 = sectorValues[1] ?? null;
            s3 = sectorValues[2] ?? null;
        }
    }

    if (s1 && s2 && (!s3 || s3 <= 0) && lapTime > (s1 + s2)) {
        s3 = lapTime - s1 - s2;
    }

    const total = [s1, s2, s3].reduce((sum, value) => sum + (value || 0), 0);
    const complete = [s1, s2, s3].every(value => Number.isFinite(value) && value > 0);
    const tolerance = Math.max(0.35, lapTime * 0.005);
    const matchesLapTime = complete && lapTime > 0 && Math.abs(total - lapTime) <= tolerance;

    return {
        s1: complete ? s1 : null,
        s2: complete ? s2 : null,
        s3: complete ? s3 : null,
        isReliable: complete && matchesLapTime,
    };
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
    return { median: pick(0.5), p90: pick(0.9) };
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
    if (!Number.isFinite(rangeX) || !Number.isFinite(rangeZ) || rangeX <= 0 || rangeZ <= 0) return -Infinity;
    const aspectRatio = Math.max(rangeX, rangeZ) / Math.max(Math.min(rangeX, rangeZ), 1e-6);
    const { median } = getStepStats(points);
    let turns = 0;
    for (let i = 2; i < points.length; i++) {
        const a = points[i - 1], b = points[i], c = points[i - 2];
        const cross = (a.x - c.x) * (b.z - a.z) - (a.z - c.z) * (b.x - a.x);
        if (Math.abs(cross) > median * median * 0.2) turns++;
    }
    return Math.log10(rangeX * rangeZ + 1) * 20 + Math.min(turns, 300) - Math.max(0, aspectRatio - 6) * 25;
};

const buildTrackPathForPair = (lapRows, horizontalCol, verticalCol) => {
    const rawPoints = [];
    lapRows.forEach(row => {
        const x = Number(row[horizontalCol]), z = Number(row[verticalCol]);
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
    const pairs = [['x', 'z'], ['x', 'y'], ['y', 'z'], ['lon', 'lat']];
    let best = { score: -Infinity, path: [], label: '' };
    pairs.forEach(([firstAxis, secondAxis]) => {
        const firstCol = candidateColumns[firstAxis], secondCol = candidateColumns[secondAxis];
        if (!firstCol || !secondCol) return;
        const path = buildTrackPathForPair(lapRows, firstCol, secondCol);
        const score = scoreTrackShape(path);
        if (score > best.score) best = { score, path, label: `${firstCol} / ${secondCol}` };
    });
    return best;
};

const updateMapViewport = (minDist = null, maxDist = null) => {
    if (!trackPath.length) { visibleTrackPath = []; return; }
    let nextPath = trackPath;
    if (Number.isFinite(minDist) && Number.isFinite(maxDist) && maxDist > minDist) {
        const filtered = trackPath.filter(point => point.dist >= minDist && point.dist <= maxDist);
        if (filtered.length >= 2) {
            let startPoint = filtered[0], endPoint = filtered[filtered.length - 1];
            for (const point of trackPath) {
                if (point.dist <= minDist) startPoint = point;
                if (point.dist >= maxDist) { endPoint = point; break; }
            }
            nextPath = [startPoint, ...filtered, endPoint];
        }
    }
    visibleTrackPath = nextPath;
    const xs = visibleTrackPath.map(p => p.x), zs = visibleTrackPath.map(p => p.z);
    mapMinX = Math.min(...xs); mapMinZ = Math.min(...zs);
    const rangeX = Math.max(...xs) - mapMinX, rangeZ = Math.max(...zs) - mapMinZ;
    if (rangeX === 0 || rangeZ === 0) { mapScaleVal = 1; return; }
    mapScaleVal = Math.min((mapCanvasW - mapPad * 2) / rangeX, (mapCanvasH - mapPad * 2) / rangeZ);
};

const prepareMapData = (lapNumber) => {
    if (!mapCanvas.value) return;
    mapCanvasW = mapCanvas.value.offsetWidth || 220;
    mapCanvasH = mapCanvas.value.offsetHeight || 220;
    mapCanvas.value.width  = mapCanvasW;
    mapCanvas.value.height = mapCanvasH;
    const rawLap = fullData.value.filter(r => Math.floor(r['Lap Number']) === lapNumber).sort((a, b) => Number(a.time || 0) - Number(b.time || 0));
    const bestTrack = resolveBestTrackPath(rawLap);
    trackPath = bestTrack.path;
    mapAxisLabel.value = bestTrack.label;
    if (trackPath.length === 0) { visibleTrackPath = []; return; }
    updateMapViewport();
    renderMap();
};

const renderMap = (dotDist = null) => {
    if (!mapCanvas.value || visibleTrackPath.length === 0) return;
    const ctx = mapCanvas.value.getContext('2d'), w = mapCanvas.value.width, h = mapCanvas.value.height;
    ctx.clearRect(0, 0, w, h);
    const rangeX = (Math.max(...visibleTrackPath.map(p => p.x)) - mapMinX) * mapScaleVal;
    const rangeZ = (Math.max(...visibleTrackPath.map(p => p.z)) - mapMinZ) * mapScaleVal;
    const offX = (w - rangeX) / 2, offZ = (h - rangeZ) / 2;
    const toCanvas = (x, z) => [offX + (x - mapMinX) * mapScaleVal, h - offZ - (z - mapMinZ) * mapScaleVal];
    ctx.beginPath();
    ctx.strokeStyle = '#374151'; ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    visibleTrackPath.forEach((pt, idx) => {
        const [px, py] = toCanvas(pt.x, pt.z);
        if (idx === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
    });
    ctx.stroke();
    if (dotDist !== null) {
        const closest = visibleTrackPath.reduce((prev, curr) => Math.abs(curr.dist - dotDist) < Math.abs(prev.dist - dotDist) ? curr : prev);
        if (closest) {
            const [px, py] = toCanvas(closest.x, closest.z);
            ctx.beginPath(); ctx.fillStyle = COLOR_MAIN; ctx.arc(px, py, 6, 0, Math.PI * 2); ctx.fill();
            ctx.strokeStyle = COLOR_SEC; ctx.lineWidth = 2; ctx.stroke();
        }
    }
};

const normalizeLapByDistance = (lapNumber) => {
    let lapData = fullData.value.filter(r => Math.floor(r['Lap Number']) === lapNumber).sort((a, b) => Number(a['time']) - Number(b['time']));
    const normalized = {};
    let lastValidDist = 0, maxDist = 0;
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
    let lapB = (compareMode.value && compareLap.value) ? normalizeLapByDistance(compareLap.value.number) : null;
    const totalDistance = Math.max(lapA.maxDist, lapB ? lapB.maxDist : 0);
    const xDistances = [], speedA = [], speedB = [], rpmA = [], rpmB = [], throttleA = [], throttleB = [], brakeA = [], brakeB = [];
    let lastA = { speed: 0, rpm: 0, throttle: 0, brake: 0 }, lastB = { speed: 0, rpm: 0, throttle: 0, brake: 0 };
    for (let i = 0; i <= totalDistance; i++) {
        xDistances.push(i);
        if (lapA.data[i]) lastA = lapA.data[i];
        speedA.push(lastA.speed); rpmA.push(lastA.rpm); throttleA.push(lastA.throttle); brakeA.push(lastA.brake);
        if (lapB) {
            if (lapB.data[i]) lastB = lapB.data[i];
            speedB.push(lastB.speed); rpmB.push(lastB.rpm); throttleB.push(lastB.throttle); brakeB.push(lastB.brake);
        }
    }
    const chartWidth = speedChartContainer.value.clientWidth || 800;
    const commonOpts = {
        width: chartWidth, cursor: { sync: { key: syncKey.key }, drag: { x: true, y: false } },
        scales: { x: { time: false } },
        hooks: {
            setScale: [(u, key) => { if (key !== 'x') return; updateMapViewport(u.scales.x.min, u.scales.x.max); renderMap(u.cursor.idx !== null ? u.data[0][u.cursor.idx] : null); }],
            setCursor: [(u) => { const idx = u.cursor.idx; if (idx !== null && u.data[0][idx] !== undefined) renderMap(u.data[0][idx]); }]
        }
    };
    
    const getSeries = (label) => {
        const series = [{ label: "Dist. (m)" }, { label: `${label} (V${selectedLap.value.number})`, stroke: COLOR_SEC, width: 2 }];
        if (lapB) series.push({ label: `${label} (V${compareLap.value.number})`, stroke: COLOR_MAIN, width: 2 });
        return series;
    };
    
    const getData = (arrA, arrB) => lapB ? [xDistances, arrA, arrB] : [xDistances, arrA];

    if (uplotSpeed) uplotSpeed.destroy();
    uplotSpeed = new uPlot({...commonOpts, height: 200, series: getSeries("Speed"), axes: [{ show: false }, { stroke: COLOR_SEC, grid: { stroke: "#374151" } }]}, getData(speedA, speedB), speedChartContainer.value);
    
    if (uplotRPM) uplotRPM.destroy();
    uplotRPM = new uPlot({...commonOpts, height: 150, series: getSeries("RPM"), axes: [{ show: false }, { stroke: COLOR_SEC, grid: { stroke: "#374151" } }]}, getData(rpmA, rpmB), rpmChartContainer.value);
    
    if (uplotThrottle) uplotThrottle.destroy();
    uplotThrottle = new uPlot({...commonOpts, height: 120, scales: { ...commonOpts.scales, y: { range: [0, 105] } }, series: getSeries("Throttle"), axes: [{ show: false }, { stroke: COLOR_SEC, grid: { stroke: "#374151" } }]}, getData(throttleA, throttleB), throttleChartContainer.value);
    
    if (uplotBrake) uplotBrake.destroy();
    uplotBrake = new uPlot({...commonOpts, height: 120, scales: { ...commonOpts.scales, y: { range: [0, 105] } }, series: getSeries("Brake"), axes: [{ stroke: "#9ca3af", grid: { stroke: "#374151" } }, { stroke: COLOR_SEC, grid: { stroke: "#374151" } }]}, getData(brakeA, brakeB), brakeChartContainer.value);
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
                <h1 class="text-3xl font-black text-white tracking-tight">SimTelemetry <span class="text-[#730F39]">PRO</span></h1>
                <p class="text-gray-400 text-sm mt-1">Análisis de telemetría avanzado</p>
            </div>
            <div class="relative bg-gray-800 border border-gray-700 rounded-lg px-6 py-3 hover:border-[#730F39] transition-colors cursor-pointer group shadow-lg">
                <input type="file" @change="handleFileUpload" accept=".csv" class="absolute inset-0 opacity-0 cursor-pointer" />
                <span class="text-sm font-bold text-gray-300 group-hover:text-[#F1CEDA] flex items-center gap-2">
                    Cargar RAW CSV
                </span>
            </div>
        </div>

        <div v-if="errorMsg" class="max-w-[1400px] w-full mx-auto mb-4 p-4 bg-red-900 border border-red-500 rounded text-red-200 text-sm">
            {{ errorMsg }}
        </div>

        <div v-if="availableLaps.length > 0 || savedSessions.length > 0" class="max-w-[1400px] w-full mx-auto flex gap-6 flex-1 min-h-0">
            <div class="w-64 flex flex-col shrink-0 gap-4">
                
                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl">
                    <h2 class="text-gray-300 font-bold text-xs uppercase mb-3 border-b border-gray-800 pb-2">Sesiones Guardadas</h2>
                    <div class="space-y-2 max-h-40 overflow-y-auto pr-2 custom-scrollbar">
                        <div v-for="session in savedSessions" :key="session.id" 
                             @click="loadSession(session)"
                             class="text-[11px] p-2 bg-gray-900/50 rounded border border-gray-800 hover:border-[#730F39] hover:bg-gray-800 cursor-pointer transition-all active:scale-95 shadow-sm group">
                            <div class="font-bold text-gray-200 truncate group-hover:text-[#F1CEDA]">
                                {{ session.original_filename }}
                            </div>
                            <div class="text-gray-500 text-[10px] mt-1 flex justify-between items-center">
                                <span>{{ new Date(session.created_at).toLocaleDateString() }}</span>
                                <span class="opacity-0 group-hover:opacity-100 text-[#730F39] font-bold uppercase text-[8px] transition-opacity tracking-widest">Cargar</span>
                            </div>
                        </div>
                        <div v-if="savedSessions.length === 0" class="text-[11px] text-gray-600 italic">No hay sesiones.</div>
                    </div>
                </div>

                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl flex flex-col items-center">
                    <h2 class="text-gray-300 font-bold text-xs uppercase mb-3 self-start w-full border-b border-gray-800 pb-2">Track Map</h2>
                    <canvas ref="mapCanvas" width="220" height="220" class="bg-gray-900/50 rounded-lg border border-gray-800"></canvas>
                    <p v-if="mapAxisLabel" class="mt-2 self-start text-[11px] text-gray-500">{{ mapAxisLabel }}</p>
                </div>

                <div v-if="availableLaps.length > 0" class="bg-[#1a1d24] border border-gray-800 rounded-xl overflow-hidden shadow-2xl flex-1 flex flex-col min-h-[200px]">
                    <div class="bg-gray-800/50 p-4 border-b border-gray-800">
                        <h2 class="text-gray-300 font-bold text-xs uppercase tracking-wider">Vueltas Archivo</h2>
                    </div>
                    <ul class="flex-1 overflow-y-auto p-2 space-y-1 custom-scrollbar">
                        <li v-for="lap in availableLaps" :key="lap.number" @click="selectLap(lap)"
                            class="group flex justify-between items-center p-3 rounded-lg cursor-pointer transition-all duration-200 border"
                            :class="[
                                selectedLap?.number === lap.number 
                                    ? (lap.isPurple ? 'bg-[#730F39]/40 border-[#F1CEDA] text-white' : 'bg-[#730F39]/20 border-[#730F39] text-white') 
                                    : (lap.isPurple ? 'bg-[#730F39]/10 border-[#730F39]/40 text-[#F1CEDA] hover:border-[#F1CEDA]' : 'hover:bg-gray-800 border-transparent text-gray-400')
                            ]">
                            
                            <div class="flex flex-col">
                                <span class="font-bold text-xs flex items-center gap-2">
                                    Lap {{ lap.number }}
                                    <span v-if="lap.isPurple" class="w-2 h-2 bg-[#F1CEDA] rounded-full animate-pulse"></span>
                                </span>
                                
                                <span v-if="lap.isOfftrack" class="text-[9px] uppercase text-red-500 font-bold tracking-tighter">Off Track / Invalida</span>
                                <span v-else-if="lap.isIncomplete" class="text-[9px] uppercase text-gray-600 font-bold tracking-tighter">Incompleta / Pits</span>
                            </div>

                            <div class="text-right">
                                <span class="font-mono text-xs block" :class="{'text-[#F1CEDA] font-bold': lap.isPurple}">
                                    {{ lap.timeFormatted }}
                                </span>
                                <span v-if="lap.isPurple" class="text-[8px] uppercase text-[#F1CEDA] font-black tracking-widest">Best</span>
                            </div>
                        </li>
                    </ul>
                </div>

                <div v-if="availableLaps.length > 0" class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-gray-300 font-bold text-xs uppercase">Comparar</span>
                        <button @click="compareMode = !compareMode" 
                                :class="compareMode ? 'bg-[#730F39] text-[#F1CEDA]' : 'bg-gray-700 text-gray-300'"
                                class="px-3 py-1 rounded text-[10px] font-bold transition-colors uppercase">
                            {{ compareMode ? 'ON' : 'OFF' }}
                        </button>
                    </div>
                    <select v-if="compareMode" v-model="compareLap" class="w-full bg-gray-900 border border-gray-700 text-white text-xs rounded px-2 py-2 outline-none">
                        <option :value="null" disabled>Elige vuelta...</option>
                        <option v-for="lap in availableLaps" :key="'comp-'+lap.number" :value="lap" :disabled="lap.number === selectedLap?.number">
                            Lap {{ lap.number }} ({{ lap.timeFormatted }})
                        </option>
                    </select>
                </div>
            </div>

            <div v-if="selectedLap" class="flex-1 bg-[#1a1d24] border border-gray-800 rounded-xl p-6 shadow-2xl min-w-0">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-200 uppercase tracking-tighter">
                        Vuelta {{ selectedLap.number }} 
                        <span v-if="compareMode && compareLap" class="text-[#F1CEDA] ml-2">vs Vuelta {{ compareLap.number }}</span>
                    </h2>
                </div>
                <div class="flex flex-col space-y-2">
                    <div ref="speedChartContainer" class="w-full"></div>
                    <div ref="rpmChartContainer" class="w-full"></div>
                    <div ref="throttleChartContainer" class="w-full"></div>
                    <div ref="brakeChartContainer" class="w-full"></div>
                </div>
            </div>

            <div v-if="selectedLap" class="w-56 flex flex-col shrink-0 gap-4">
                <div class="bg-[#1a1d24] border border-gray-800 rounded-xl p-4 shadow-2xl">
                    <h2 class="text-gray-300 font-bold text-xs uppercase mb-4 border-b border-gray-800 pb-2">
                        Sectores
                    </h2>
                    
                    <div class="flex flex-col gap-3">
                        <div v-for="sec in [1, 2, 3]" :key="sec" class="bg-gray-800/30 p-3 rounded-lg border border-gray-800 relative overflow-hidden group">
                            <div class="text-[10px] uppercase text-gray-500 font-bold tracking-widest mb-1 flex justify-between items-center">
                                <span>Sector {{ sec }}</span>
                                <span v-if="idealLap && selectedLap.sectors['s'+sec] && selectedLap.sectors['s'+sec] === idealLap['s'+sec]" class="text-[#F1CEDA] text-[9px] font-black tracking-wider animate-pulse">BEST</span>
                            </div>
                            
                            <div class="flex justify-between items-baseline">
                                <span class="text-lg font-mono font-bold text-[#F1CEDA]">
                                    {{ formatTime(selectedLap.sectors['s'+sec]) }}
                                </span>
                            </div>

                            <div v-if="compareMode && compareLap" class="flex justify-between items-center mt-1 pt-1 border-t border-gray-700/50">
                                <span class="text-xs font-mono font-bold text-[#730F39]">
                                    {{ formatTime(compareLap.sectors['s'+sec]) }}
                                </span>
                                <span class="text-[10px] font-mono font-bold" :class="getDiffClass(selectedLap.sectors['s'+sec], compareLap.sectors['s'+sec])">
                                    {{ formatDiff(selectedLap.sectors['s'+sec], compareLap.sectors['s'+sec]) }}
                                </span>
                            </div>
                        </div>

                        <div v-if="idealLap" class="mt-2 bg-gradient-to-br from-gray-800 to-gray-900 p-3 rounded-lg border border-[#730F39]/30">
                            <div class="text-[10px] uppercase text-[#730F39] font-black tracking-widest mb-1">
                                Ideal Teórico
                            </div>
                            <div class="text-lg font-mono font-bold text-white">
                                {{ formatTime(idealLap.total) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.uplot { font-family: ui-monospace, monospace; }
.u-legend { color: #9ca3af; font-size: 12px; padding-bottom: 5px; }
.u-value { color: #fff; font-weight: bold; }
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 10px; }
</style>

