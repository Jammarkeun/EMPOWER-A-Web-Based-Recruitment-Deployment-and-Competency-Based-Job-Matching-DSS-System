/**
 * EMPOWER design tokens.
 *
 * Colours are declared as CSS variables in styles.css and referenced here, so a
 * single palette drives both Tailwind utilities and any raw CSS. Status colours
 * are named for what they mean in the recruitment workflow rather than for the
 * hue, which keeps the intent readable at the call site.
 */
module.exports = {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  // Class-based rather than media-based: the user's explicit choice has to be
  // able to override their operating system setting, and an HR office often has
  // one machine set differently from the rest.
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        border: 'hsl(var(--border))',
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
        success: {
          DEFAULT: 'hsl(var(--success))',
          foreground: 'hsl(var(--success-foreground))',
        },
        warning: {
          DEFAULT: 'hsl(var(--warning))',
          foreground: 'hsl(var(--warning-foreground))',
        },
        // The agency's navy, worn by the sidebar in both themes.
        sidebar: {
          DEFAULT: 'hsl(var(--sidebar))',
          foreground: 'hsl(var(--sidebar-foreground))',
          muted: 'hsl(var(--sidebar-muted))',
          hover: 'hsl(var(--sidebar-hover))',
          active: 'hsl(var(--sidebar-active))',
          border: 'hsl(var(--sidebar-border))',
        },
      },
      borderRadius: {
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'sans-serif'],
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
        'toast-in': 'toast-in 0.22s cubic-bezier(0.21, 1.02, 0.73, 1)',
        'toast-out': 'toast-out 0.18s ease-in forwards',
      },
    },
  },
  plugins: [],
}
