import * as React from 'react'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { Bar, Line } from 'react-chartjs-2'
import { useTheme } from '@/contexts/ThemeContext'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

/*
 * ---------------------------------------------------------------------------
 * Palette
 * ---------------------------------------------------------------------------
 *
 * Both modes are *selected*, not derived. Dark is not a flip of light: it is the
 * same hues re-stepped for a dark surface, because a colour that clears contrast
 * on white does not on a near-black card.
 *
 * These values are not eyeballed. Every slot was checked with the palette
 * validator against the two surfaces this application actually paints on — white
 * (--card, light) and #111827 (--card, dark) — for lightness band, chroma floor,
 * colour-vision separation, normal-vision separation, and contrast. All pass with
 * no warnings in either mode.
 *
 * What the previous palette got wrong, and why it is worth recording: its sixth
 * slot was slate #64748b, whose chroma of 0.041 is far below the 0.10 floor. A
 * colour that desaturated reads as grey, so it cannot carry identity — it looked
 * like a disabled series rather than a distinct one. In dark mode two further
 * slots sat under 3:1 against the card and quietly disappeared.
 *
 * The order of the slots is the colour-vision safety mechanism, not decoration.
 * Do not reorder them, and do not add a fifth by picking something that looks
 * nice: re-run the validator.
 */
const SERIES_LIGHT = ['#2a78d6', '#eb6834', '#199e70', '#c98500']
const SERIES_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500']

/*
 * Sequential blue, for values that have a natural order.
 *
 * Two things here were got wrong first time and are worth stating so they are
 * not reintroduced.
 *
 * Five steps, not ten. Ten steps off the same ramp put adjacent stages 0.047
 * apart in lightness, under the 0.06 needed to see a step at all — so a
 * ten-stage funnel rendered as a smooth wash with no readable boundaries.
 *
 * The direction flips per mode. Running light→dark on a dark card sends the
 * deepest step to 1.48:1 against the surface: the stages holding the most
 * applicants would have been the ones you could not see. On dark the ramp runs
 * the other way, so "further along" means brighter, and every step clears
 * contrast against the card it is painted on.
 */
const RAMP_LIGHT = ['#86b6ef', '#5598e7', '#2a78d6', '#184f95', '#0d366b']
const RAMP_DARK = ['#256abf', '#3987e5', '#6da7ec', '#9ec5f4', '#cde2fb']

/**
 * Resolves the chart palette and chrome colours for the active theme.
 *
 * Chart.js paints to a canvas, so it cannot inherit CSS custom properties the
 * way the rest of the interface does — every colour has to be handed to it as a
 * literal. Reading them here, keyed off the theme, is what keeps the charts from
 * being the one part of the application that ignores dark mode.
 */
function useChartTheme() {
  const { theme } = useTheme()

  // 'system' resolves at paint time, so the DOM is the authority rather than the
  // stored preference.
  const [isDark, setIsDark] = React.useState(
    () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
  )

  React.useEffect(() => {
    const read = () => setIsDark(document.documentElement.classList.contains('dark'))
    read()

    // ThemeContext toggles the class on <html>; watching it covers the explicit
    // toggle and an operating-system change while set to "system".
    const observer = new MutationObserver(read)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    return () => observer.disconnect()
  }, [theme])

  return React.useMemo(
    () => ({
      isDark,
      series: isDark ? SERIES_DARK : SERIES_LIGHT,
      ramp: isDark ? RAMP_DARK : RAMP_LIGHT,
      surface: isDark ? '#111827' : '#ffffff',
      text: isDark ? 'rgba(210,225,245,0.92)' : 'rgba(30,41,59,0.92)',
      // Grid and axis rules are hairlines one shade off the surface, and solid:
      // a dashed grid reads as a threshold or a projection when it is neither.
      grid: isDark ? 'rgba(148,163,184,0.14)' : 'rgba(100,116,139,0.14)',
      tooltipBg: isDark ? '#0b1220' : '#0f172a',
    }),
    [isDark]
  )
}

/**
 * Whether the viewer has asked for less movement.
 *
 * Chart animation is the one place where "respecting the preference" cannot be
 * done in CSS, because the drawing happens on a canvas.
 */
function usePrefersReducedMotion() {
  const [reduced, setReduced] = React.useState(
    () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )

  React.useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)')
    const onChange = () => setReduced(query.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  return reduced
}

/**
 * Shared chrome.
 *
 * Everything here is deliberately recessive. The marks carry the information;
 * the grid, ticks, and frame exist only to make them measurable, so they sit one
 * step off the surface and never compete.
 */
function useBaseOptions({ motion = true } = {}) {
  const t = useChartTheme()
  const reduced = usePrefersReducedMotion()
  const animate = motion && !reduced

  return React.useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      // The whole plot responds to the nearest x position rather than requiring
      // the pointer to land on a 3px point.
      interaction: { mode: 'index', intersect: false },
      animation: animate ? { duration: 700, easing: 'easeOutQuart' } : false,
      // Series appear in sequence rather than all at once, which lets the eye
      // follow one line at a time on first paint. Kept well under a second in
      // total: a dashboard that makes people wait to read it is a worse
      // dashboard, however pleasant the animation.
      animations: animate
        ? { y: { duration: 700, easing: 'easeOutQuart' }, x: { duration: 0 } }
        : false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            usePointStyle: true,
            pointStyle: 'circle',
            boxWidth: 8,
            padding: 16,
            color: t.text,
            font: { size: 11 },
          },
        },
        tooltip: {
          backgroundColor: t.tooltipBg,
          padding: 10,
          cornerRadius: 8,
          titleFont: { size: 12, weight: '600' },
          bodyFont: { size: 12 },
          displayColors: true,
          usePointStyle: true,
          boxPadding: 4,
        },
      },
      scales: {
        x: {
          grid: { display: false },
          border: { color: t.grid },
          ticks: { color: t.text, font: { size: 11 } },
        },
        y: {
          beginAtZero: true,
          grid: { color: t.grid, drawTicks: false },
          border: { display: false },
          // Headcounts are whole people; a "2.5 deployments" gridline is noise.
          ticks: { precision: 0, color: t.text, font: { size: 11 }, padding: 8 },
        },
      },
    }),
    [t, animate]
  )
}

/** Chart.js wants a canvas-space gradient, which needs the painted area. */
function verticalFade(ctx, area, hex, topAlpha = 0.22) {
  if (!area) return 'transparent'
  const gradient = ctx.createLinearGradient(0, area.top, 0, area.bottom)
  gradient.addColorStop(0, hex + Math.round(topAlpha * 255).toString(16).padStart(2, '0'))
  gradient.addColorStop(1, hex + '00')
  return gradient
}

/**
 * Hiring against attrition over time.
 *
 * Plotted together deliberately: read alone, a rising deployment count looks
 * like growth, when it may only be replacing leavers.
 *
 * One axis, never two. Each of these series counts people, so they share a scale
 * honestly — a second y-axis would let the chart invent a correlation by
 * choosing where the two scales line up.
 */
export function TrendChart({ data }) {
  const t = useChartTheme()
  const base = useBaseOptions()

  const chartData = React.useMemo(() => {
    const series = [
      { key: 'applications', label: 'Applications', colour: t.series[2], fill: true },
      { key: 'deployments', label: 'Deployments', colour: t.series[0], fill: true },
      { key: 'resignations', label: 'Resignations', colour: t.series[1], fill: false },
      { key: 'terminations', label: 'Terminations', colour: t.series[3], fill: false },
    ]

    return {
      labels: data.map((d) => d.label),
      datasets: series.map((s) => ({
        label: s.label,
        data: data.map((d) => d[s.key]),
        borderColor: s.colour,
        backgroundColor: s.fill
          ? (context) => verticalFade(context.chart.ctx, context.chart.chartArea, s.colour)
          : 'transparent',
        borderWidth: 2,
        tension: 0.35,
        fill: s.fill,
        // Points stay hidden until hovered: a marker on every month turns a
        // twelve-point line into a row of dots. The hit radius is generous so
        // the value is still easy to reach.
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHitRadius: 24,
        pointBackgroundColor: s.colour,
        // A ring in the surface colour separates the marker from the line it
        // sits on, rather than a border drawn around it.
        pointHoverBorderColor: t.surface,
        pointHoverBorderWidth: 2,
      })),
    }
  }, [data, t])

  return (
    <div className="h-72">
      <Line data={chartData} options={base} />
    </div>
  )
}

/**
 * One measure across nominal categories — companies, violation types.
 *
 * Every bar is the same colour, and that is deliberate. Colouring each bar
 * differently spends the identity channel re-encoding what the bar length
 * already shows, and it invites the reader to look for a meaning in the hues
 * that is not there. These categories have no order, so no ramp either.
 */
export function CategoryBarChart({ data, label = 'Count', horizontal = false }) {
  const t = useChartTheme()
  const base = useBaseOptions()

  const chartData = React.useMemo(
    () => ({
      labels: data.map((d) => d.label),
      datasets: [
        {
          label,
          data: data.map((d) => d.count),
          backgroundColor: t.series[0],
          hoverBackgroundColor: t.series[0],
          // Rounded only at the value end; the baseline end stays square so the
          // bar reads as starting exactly at zero.
          borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
          borderSkipped: horizontal ? 'left' : 'bottom',
          maxBarThickness: 34,
        },
      ],
    }),
    [data, label, t]
  )

  const options = React.useMemo(
    () => ({
      ...base,
      indexAxis: horizontal ? 'y' : 'x',
      // A single series needs no legend box — the card title names it.
      plugins: { ...base.plugins, legend: { display: false } },
      scales: horizontal
        ? {
            x: { ...base.scales.y, grid: { ...base.scales.y.grid, drawTicks: false } },
            y: { ...base.scales.x },
          }
        : base.scales,
    }),
    [base, horizontal]
  )

  return (
    <div className="h-72">
      <Bar data={chartData} options={options} />
    </div>
  )
}

/**
 * Applicants across the recruitment lifecycle.
 *
 * A horizontal bar on a single-hue ramp, not a pie. Two reasons: the lifecycle
 * runs to fifteen stages, well past the six a part-to-whole circle can carry, and
 * the stages are ordered — an applicant moves through them. A ramp puts that
 * order into the colour, so the funnel is visible as a shape rather than having
 * to be reconstructed from a legend.
 *
 * Names are on the axis rather than in a legend, which is what makes fifteen
 * categories readable at all.
 */
export function PipelineChart({ data }) {
  const t = useChartTheme()
  const base = useBaseOptions()

  const chartData = React.useMemo(() => {
    // Darkens along the pipeline, so later stages read as further through.
    const step = (index) =>
      t.ramp[Math.min(t.ramp.length - 1, Math.round((index / Math.max(1, data.length - 1)) * (t.ramp.length - 1)))]

    return {
      labels: data.map((d) => d.label),
      datasets: [
        {
          label: 'Applicants',
          data: data.map((d) => d.count),
          backgroundColor: data.map((_, i) => step(i)),
          borderRadius: { topLeft: 0, topRight: 4, bottomLeft: 0, bottomRight: 4 },
          borderSkipped: 'left',
          maxBarThickness: 18,
        },
      ],
    }
  }, [data, t])

  const options = React.useMemo(
    () => ({
      ...base,
      indexAxis: 'y',
      plugins: { ...base.plugins, legend: { display: false } },
      scales: {
        x: { ...base.scales.y },
        y: { ...base.scales.x, ticks: { ...base.scales.x.ticks, autoSkip: false, font: { size: 10 } } },
      },
    }),
    [base]
  )

  // Grows with the number of stages rather than squeezing them into a fixed
  // box, so the axis labels are never clipped.
  return (
    <div style={{ height: Math.max(220, data.length * 26 + 48) }}>
      <Bar data={chartData} options={options} />
    </div>
  )
}
