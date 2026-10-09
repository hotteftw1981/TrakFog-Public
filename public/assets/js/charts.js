(() => {
  const SVG_NS = 'http://www.w3.org/2000/svg';

  const svgNode = (name, attributes) => {
    const node = document.createElementNS(SVG_NS, name);
    Object.entries(attributes || {}).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };

  const formatNumber = (value, decimals, unit) => {
    const number = Number(value);
    if (!Number.isFinite(number)) return '–';
    const formatted = new Intl.NumberFormat('de-DE', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    }).format(number);
    return unit ? formatted + ' ' + unit : formatted;
  };

  const renderChart = (root) => {
    let config;
    try {
      config = JSON.parse(root.dataset.chart || '{}');
    } catch (_) {
      root.innerHTML = '<div class="tf-chart-empty">Chartdaten konnten nicht gelesen werden.</div>';
      return;
    }

    const series = (Array.isArray(config.series) ? config.series : []).map((item) => ({
      ...item,
      points: (Array.isArray(item.points) ? item.points : [])
        .map((point) => ({x: Number(point.x), y: Number(point.y)}))
        .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y))
        .sort((a, b) => a.x - b.x)
    }));

    const allPoints = series.flatMap((item) => item.points);
    if (!allPoints.length) {
      root.innerHTML = '<div class="tf-chart-empty"><strong>Noch keine Daten im gewählten Zeitraum.</strong><span>Die Kurve füllt sich automatisch, sobald TrakFog neue Werte gespeichert hat.</span></div>';
      return;
    }

    const width = 900;
    const height = 270;
    const pad = {top: 18, right: 18, bottom: 38, left: 58};

    let xMin = Math.min(...allPoints.map((point) => point.x));
    let xMax = Math.max(...allPoints.map((point) => point.x));
    if (xMin === xMax) {
      xMin -= 60000;
      xMax += 60000;
    }

    const values = allPoints.map((point) => point.y);
    const configMin = Number(config.min);
    const configMax = Number(config.max);
    const fixedMin = config.min !== null && config.min !== undefined && Number.isFinite(configMin) ? configMin : null;
    const fixedMax = config.max !== null && config.max !== undefined && Number.isFinite(configMax) ? configMax : null;
    let yMin = fixedMin !== null ? fixedMin : Math.min(...values);
    let yMax = fixedMax !== null ? fixedMax : Math.max(...values);

    if (config.zeroFloor && fixedMin === null) yMin = Math.min(0, yMin);
    if (config.includeZero) {
      yMin = Math.min(0, yMin);
      yMax = Math.max(0, yMax);
    }

    if (yMin === yMax) {
      const spread = Math.max(1, Math.abs(yMin) * 0.08);
      yMin -= spread;
      yMax += spread;
    } else {
      const spread = (yMax - yMin) * 0.08;
      if (fixedMin === null && !config.zeroFloor) yMin -= spread;
      if (fixedMax === null) yMax += spread;
    }

    const plotWidth = width - pad.left - pad.right;
    const plotHeight = height - pad.top - pad.bottom;
    const xAt = (x) => pad.left + ((x - xMin) / (xMax - xMin)) * plotWidth;
    const yAt = (y) => pad.top + (1 - ((y - yMin) / (yMax - yMin))) * plotHeight;
    const decimals = Number(config.decimals || 0);
    const unit = config.unit || '';

    const legend = document.createElement('div');
    legend.className = 'tf-chart-legend';

    series.forEach((item) => {
      if (!item.points.length) return;
      const latest = item.points[item.points.length - 1];
      const minimum = Math.min(...item.points.map((point) => point.y));
      const maximum = Math.max(...item.points.map((point) => point.y));
      const entry = document.createElement('div');
      entry.className = 'tf-chart-legend-item';

      const dot = document.createElement('span');
      dot.className = 'tf-chart-legend-dot tf-chart-color-' + (item.key || 'brand');

      const label = document.createElement('span');
      label.textContent = item.label || 'Wert';

      const latestValue = document.createElement('b');
      latestValue.textContent = formatNumber(latest.y, decimals, unit);

      const limits = document.createElement('small');
      limits.textContent = (config.observedRange ? 'gemessen: ' : '') +
        'min ' + formatNumber(minimum, decimals, unit) + ' · max ' + formatNumber(maximum, decimals, unit);
      if (config.observedRange) limits.title = 'Nur gespeicherte Tesla-Messwerte; zwischen den Punkten können höhere Werte liegen.';

      entry.append(dot, label, latestValue, limits);
      legend.appendChild(entry);
    });

    const stage = document.createElement('div');
    stage.className = 'tf-chart-stage';
    const svg = svgNode('svg', {
      viewBox: '0 0 ' + width + ' ' + height,
      role: 'img',
      'aria-label': 'TrakFog Verlauf'
    });

    for (let i = 0; i <= 4; i += 1) {
      const ratio = i / 4;
      const y = pad.top + ratio * plotHeight;
      const value = yMax - ratio * (yMax - yMin);

      svg.appendChild(svgNode('line', {
        x1: pad.left,
        y1: y,
        x2: width - pad.right,
        y2: y,
        class: 'tf-chart-grid-line'
      }));

      const label = svgNode('text', {
        x: pad.left - 10,
        y: y + 4,
        class: 'tf-chart-axis-label',
        'text-anchor': 'end'
      });
      label.textContent = formatNumber(value, decimals, unit);
      svg.appendChild(label);
    }

    if (config.includeZero && yMin < 0 && yMax > 0) {
      const zeroY = yAt(0);
      svg.appendChild(svgNode('line', {
        x1: pad.left,
        y1: zeroY,
        x2: width - pad.right,
        y2: zeroY,
        class: 'tf-chart-zero-line'
      }));
    }

    const spanDays = (xMax - xMin) / 86400000;
    const dateFormatter = new Intl.DateTimeFormat(
      'de-DE',
      spanDays > 2
        ? {day: '2-digit', month: '2-digit', hour: '2-digit'}
        : {hour: '2-digit', minute: '2-digit'}
    );

    [0, 0.5, 1].forEach((ratio) => {
      const xValue = xMin + ratio * (xMax - xMin);
      const x = xAt(xValue);
      const label = svgNode('text', {
        x: x,
        y: height - 10,
        class: 'tf-chart-axis-label tf-chart-axis-x',
        'text-anchor': ratio === 0 ? 'start' : (ratio === 1 ? 'end' : 'middle')
      });
      label.textContent = dateFormatter.format(new Date(xValue));
      svg.appendChild(label);
    });

    const maxGapMs = Number(config.maxGapMs) > 0 ? Number(config.maxGapMs) : Infinity;
    let missingIntervals = 0;
    series.forEach((item) => {
      if (!item.points.length) return;
      const colorClass = 'tf-chart-color-' + (item.key || 'brand');
      const segments = [];
      let segment = [];
      item.points.forEach((point) => {
        if (segment.length && point.x - segment[segment.length - 1].x > maxGapMs) {
          segments.push(segment);
          segment = [];
          missingIntervals += 1;
        }
        segment.push(point);
      });
      if (segment.length) segments.push(segment);

      // Never draw a straight line through a period with no telemetry.
      segments.forEach((part) => {
        if (part.length < 2) return;
        const pathData = part.map((point, index) =>
          (index === 0 ? 'M' : 'L') + xAt(point.x).toFixed(2) + ' ' + yAt(point.y).toFixed(2)
        ).join(' ');
        svg.appendChild(svgNode('path', {d: pathData, class: 'tf-chart-line ' + colorClass}));
      });
      // Show actual samples even when no continuous line can be drawn.
      const sampleStep = Math.max(1, Math.ceil(item.points.length / 120));
      item.points.forEach((point, i) => {
        if (i % sampleStep !== 0 && i !== item.points.length - 1) return;
        const circle = svgNode('circle', {
          cx: xAt(point.x), cy: yAt(point.y),
          r: i === item.points.length - 1 ? 4.5 : 2.4,
          class: 'tf-chart-point ' + colorClass
        });
        const title = svgNode('title');
        title.textContent = new Intl.DateTimeFormat('de-DE', {
          day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'
        }).format(new Date(point.x)) + ': ' + formatNumber(point.y, decimals, unit);
        circle.appendChild(title);
        svg.appendChild(circle);
      });
    });

    stage.appendChild(svg);

    const footer = document.createElement('div');
    footer.className = 'tf-chart-footer';
    const latestAt = Math.max(...allPoints.map((point) => point.x));
    const rawSamples = Number(config.rawSampleCount);
    footer.textContent =
      allPoints.length.toLocaleString('de-DE') +
      ' Kurvenpunkte' + (Number.isFinite(rawSamples) && rawSamples >= 0 ? ' aus ' + rawSamples.toLocaleString('de-DE') + ' Messwerten' : '') +
      ' · letzter Wert ' +
      new Intl.DateTimeFormat('de-DE', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
      }).format(new Date(latestAt)) +
      (missingIntervals ? ' · ' + missingIntervals + ' Datenlücke(n) nicht verbunden' : '');
    root.dataset.gaps = String(missingIntervals);

    root.replaceChildren(legend, stage, footer);
  };

  const renderAllCharts = () => {
    document.querySelectorAll('.tf-chart[data-chart]').forEach(renderChart);
  };

  document.querySelectorAll('[data-chart-page]').forEach((page) => {
    const observer = new MutationObserver(() => {
      observer.disconnect();
      window.requestAnimationFrame(() => {
        renderAllCharts();
        observer.observe(page, {childList: true});
      });
    });
    observer.observe(page, {childList: true});
  });

  window.TrakFog = window.TrakFog || {};
  window.TrakFog.renderCharts = renderAllCharts;
  renderAllCharts();
})();
