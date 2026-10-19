/**
 * EMPOWER design tokens.
 *
 * Colours are declared as CSS variables in styles.css and referenced here, so a
 * single palette drives both Tailwind utilities and any raw CSS. Status colours
 * are named for what they mean in the recruitment workflow rather than for the
 * hue, which keeps the intent readable at the call site.
 *
 * Every colour is an HSL triple wrapped with <alpha-value>, so opacity modifiers
 * such as `bg-navy-900/60` or `bg-status-danger-bg/20` actually compile. A bare
 * `var(--hex)` cannot take an alpha, and Tailwind silently drops the class.
 */
const token = (name) => `hsl(var(--${name}) / <alpha-value>)`

module.exports = {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  // Class-based rather than media-based: the user's explicit choice has to be
  // able to override their operating system setting, and an HR office often has
  // one machine set differently from the rest.
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        navy: {
          900: token('navy-900'),
          800: token('navy-800'),
          700: token('navy-700'),
        },
        brand: {
          DEFAULT: token('brand'),
          hover: token('brand-hover'),
        },
        text: {
          DEFAULT: token('text'),
          muted: token('text-muted'),
          subtle: token('text-subtle'),
        },
        border: {
          DEFAULT: 'hsl(var(--border))',
          strong: token('border-strong'),
        },
        input: 'hsl(var(--input))',
        ring: 'hsl(var(--ring))',
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
        },
        secondary: {
          DEFAULT: 'hsl(var(--secondary))',
          foreground: 'hsl(var(--secondary-foreground))',
        },
        muted: {
          DEFAULT: 'hsl(var(--muted))',
          foreground: 'hsl(var(--muted-foreground))',
        },
        accent: {
          DEFAULT: 'hsl(var(--accent))',
          foreground: 'hsl(var(--accent-foreground))',
        },
        destructive: {
          DEFAULT: 'hsl(var(--destructive))',
          foreground: 'hsl(var(--destructive-foreground))',
        },
        card: {
          DEFAULT: 'hsl(var(--card))',
          foreground: 'hsl(var(--card-foreground))',
        },
        popover: {
          DEFAULT: token('popover'),
          foreground: token('popover-foreground'),
        },
        success: {
          DEFAULT: 'hsl(var(--success))',
          foreground: 'hsl(var(--success-foreground))',
        },
        warning: {
          DEFAULT: 'hsl(var(--warning))',
          foreground: 'hsl(var(--warning-foreground))',
        },
        sidebar: {
          DEFAULT: 'hsl(var(--sidebar))',
          foreground: 'hsl(var(--sidebar-foreground))',
          muted: 'hsl(var(--sidebar-muted))',
          hover: 'hsl(var(--sidebar-hover))',
          active: 'hsl(var(--sidebar-active))',
          border: 'hsl(var(--sidebar-border))',
        },
        // Workflow status surfaces: `bg-status-success-bg`, `text-status-danger-text`,
        // and `bg-status-warning-dot` for the dot a status chip leads with.
        status: Object.fromEntries(
          ['success', 'warning', 'info', 'danger', 'neutral'].map((tone) => [
            tone,
            {
              bg: token(`status-${tone}-bg`),
              text: token(`status-${tone}-text`),
              border: token(`status-${tone}-border`),
              dot: token(`status-${tone}-dot`),
            },
          ])
        ),
        // A recessed surface inside a white panel: list rows, inner cards, tracks.
        inset: {
          DEFAULT: token('inset'),
          selected: token('inset-selected'),
          'selected-border': token('inset-selected-border'),
        },
      },
      borderRadius: {
        control: 'var(--radius-control)',
        panel: 'var(--radius-panel)',
        row: 'var(--radius-row)',
        modal: 'var(--radius-modal)',
        badge: 'var(--radius-badge)',
        tag: 'var(--radius-tag)',
        lg: 'var(--radius-row)',
        md: 'var(--radius-control)',
        sm: 'var(--radius-tag)',
      },
      boxShadow: {
        xs: '0 1px 2px 0 rgb(17 18 39 / 0.04)',
        // Popovers and menus: soft and wide, never a hard edge.
        float: '0 16px 40px -12px rgb(17 18 39 / 0.18)',
      },
      spacing: {
        4.5: '1.125rem',
      },
      minWidth: {
        4.5: '1.125rem',
      },
      backdropBlur: {
        xs: '2px',
      },
      fontFamily: {
        sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        heading: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
      keyframes: {
        'accordion-down': {
          from: { height: '0' },
          to: { height: 'var(--radix-accordion-content-height)' },
        },
        'accordion-up': {
          from: { height: 'var(--radix-accordion-content-height)' },
          to: { height: '0' },
        },
        'fade-in': {
          from: { opacity: '0', transform: 'translateY(4px)' },
          to: { opacity: '1', transform: 'translateY(0)' },
        },
        'fade-out': {
          from: { opacity: '1' },
          to: { opacity: '0' },
        },
        // Toasts arrive from the edge they live on rather than fading in
        // place, so the eye is led to the corner instead of having to find a
        // message that simply appeared.
        'toast-in': {
          from: { opacity: '0', transform: 'translateX(16px) scale(0.97)' },
          to: { opacity: '1', transform: 'translateX(0) scale(1)' },
        },
        'toast-out': {
          from: { opacity: '1', transform: 'translateX(0) scale(1)' },
          to: { opacity: '0', transform: 'translateX(16px) scale(0.97)' },
        },
        // The toast countdown bar lives in styles.css instead: it is driven by
        // an inline animation whose duration varies per toast, and Tailwind
        // only emits @keyframes that some animation utility references.
      },
      animation: {
        'accordion-down': 'accordion-down 0.2s ease-out',
        'accordion-up': 'accordion-up 0.2s ease-out',
        'fade-in': 'fade-in 0.2s ease-out',
        'fade-out': 'fade-out 0.15s ease-in forwards',
        'toast-in': 'toast-in 0.22s cubic-bezier(0.21, 1.02, 0.73, 1)',
        'toast-out': 'toast-out 0.18s ease-in forwards',
      },
    },
  },
  plugins: [],
}
