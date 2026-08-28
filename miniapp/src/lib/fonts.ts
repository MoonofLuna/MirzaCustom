const WEIGHTS: Array<[name: string, weight: number]> = [
  ['Light', 300],
  ['Medium', 500],
  ['Bold', 700],
]

export function injectFonts() {
  const prefix = window.__APP_CONFIG__?.assetPrefix
  if (!prefix) return // local dev without the PHP wrapper: fall back to system fonts

  const css = WEIGHTS.map(
    ([name, weight]) => `
    @font-face {
      font-family: 'Vazir';
      font-weight: ${weight};
      font-display: swap;
      src: url('${prefix}fonts/Vazir-${name}.woff2') format('woff2'),
           url('${prefix}fonts/Vazir-${name}.woff') format('woff');
    }`,
  ).join('\n')

  const style = document.createElement('style')
  style.textContent = css
  document.head.appendChild(style)
}
