/**
 * GUGU Design Tokens — Rwanda flag palette
 * Blue (sky) · Yellow (sun) · Green (hills)
 */
export const seed = {
  color: {
    carrot: '#00A1DE',       // primary = Rwanda blue (kept key for compat)
    carrotHover: '#0088BD',
    carrotLight: '#E6F6FC',
    carrotSoft: '#CCECF8',
    carrotBg: '#F0FAFE',
    green: '#20603D',        // flag green
    greenLight: '#E8F5EE',
    red: '#F04452',
    yellow: '#FAD201',       // flag yellow
    gray900: '#212124',
    gray700: '#4D4D4D',
    gray600: '#868B94',
    gray500: '#ADB1BA',
    gray400: '#D1D3D8',
    gray200: '#EAEBEE',
    gray100: '#F2F3F6',
    gray50: '#FAFAFA',
    white: '#FFFFFF',
  },
  font: {
    family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    size: {
      xs: '0.6875rem',
      sm: '0.8125rem',
      md: '0.9375rem',
      lg: '1.0625rem',
      xl: '1.25rem',
      '2xl': '1.5rem',
      '3xl': '1.75rem',
    },
    weight: {
      normal: 400,
      medium: 500,
      semibold: 600,
      bold: 700,
      extrabold: 800,
    },
  },
  radius: {
    sm: '6px',
    md: '8px',
    lg: '12px',
    xl: '16px',
    full: '9999px',
  },
  spacing: {
    xs: '4px',
    sm: '8px',
    md: '12px',
    lg: '16px',
    xl: '24px',
    '2xl': '32px',
    '3xl': '48px',
  },
  shadow: {
    sm: '0 1px 3px rgba(0,0,0,0.06)',
    md: '0 4px 16px rgba(0,0,0,0.08)',
    lg: '0 8px 30px rgba(0,0,0,0.12)',
    carrot: '0 4px 12px rgba(0,161,222,0.35)',
  },
  transition: {
    fast: '0.12s ease',
    normal: '0.2s ease',
    stack: '0.32s cubic-bezier(0.32, 0.72, 0, 1)',
  },
  layout: {
    maxWidth: '430px',
    headerHeight: '56px',
    bottomNavHeight: '56px',
  },
} as const;

export type SeedColor = keyof typeof seed.color;
